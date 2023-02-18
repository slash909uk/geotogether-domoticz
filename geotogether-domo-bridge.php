<?php
// S Ashby, 03/08/2021, created
// script to poll Geotogether smartmeter API and push data into domoticz via MQTT broker
// v1.1 - support export readings as second domoticz IDX - NB: Geo API returns export as a negative value. Leave as a negative so it is easy to add the values together for a total over time, or chart
// V1.2 - support periodic data API to obtain cumulative import meter reading, call at slower interval (no reason to poll quickly!) Remember to set domoticz device to 'from device' not 'calculated' for energy read
// 17/10 - add sig handler to dump stack trace as process gets stuck somehow after a a few days running. Cannot debug with core dump as no GDB on target!
// V1.3 - add alarm on 'null' power reported for an entire telemetry period, most likely the meter has stopped uploading?
// V1.4 - add simple power level output for openevse grid balancing 

// debug
$debug=false;

echo "Geotogether-domo MQTT bridge started\n";

require "../phpMQTT-master/phpMQTT.php";

$domo_idx_import = 2727; // domoticz IDX for the electricity import meter device to update
$domo_idx_export = 2725; // domoticz IDX for the electricity export meter device to update

$server = "localhost";     // change if necessary
$port = 1883;                     // change if necessary
$client_id = "geotogether-subscriber"; // make sure this is unique for connecting to sever - you could use uniqid()
$username = ''; // MQTT credentials if required
$password = '';
// Geo API config / credentials
define(BASE_URL,'https://api.geotogether.com/');
define(LOGIN_URL,'usersservice/v2/login');
define(DEVICEDETAILS_URL,'api/userapi/v2/user/detail-systems?systemDetails=true');
define(LIVEDATA_URL,'api/userapi/system/smets2-live-data/');
define(PERIODICDATA_URL,'api/userapi/system/smets2-periodic-data/');
define(USERNAME,'xxx');
define(PASSWORD,'xxx');
// polling interval (sec)
$polling_sec = 20;
$periodic_sec = 300;

// telemetry interval
$telemetry_sec = 600;

// simple power level export MQTT topic
define(SIMPLE_PWR_TOPIC,'geotogether-domo/gridpower');
$simple_pwr_offset = 0; // Watts offset to test/force charging to start in low PV conditions
// simple voltage source, currently E2PV script
define(SIMPLE_VOLTAGE_TOPIC,'openevse/voltage');
$voltage = 0; // set to zero to default to 240V AC
$lastvoltage = 0; // time of last reading - data is discarded after 1 minute


// include Syslog class for remote syslog feature
require "../Syslog-master/Syslog.php";
Syslog::$hostname = "localhost";
Syslog::$facility = LOG_DAEMON;
Syslog::$hostToLog = "geotogether-domo";

function report($msg, $level = LOG_INFO, $cmp = "geotogether-domo-bridge") {
	global $debug;
	if(!$debug && $level === LOG_DEBUG) return; // skip debug level msg if disabled
	if($debug) echo "Report:".$msg."\tLevel:".$level."\n";
    Syslog::send($msg, $level, $cmp);
}

// Geotogether API functions
$curl = false;
$headers = false;
$deviceId = false;
$systemName = false;
$totalImport = 0;

// connect/login to Geotogether service and cache auth token
function connect_geo() {
	global $curl;
	global $headers;
	global $deviceId;
	global $systemName;

	// clear old state
	$headers = array(
	   "Accept: application/json",
	   "Content-Type: application/json",
	);
	$deviceId = false;
	// make curl object if not done
	if(!$curl) {
		report('opening cURL instance',LOG_DEBUG);
		$curl = curl_init();
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10); // should be plenty!
		curl_setopt($curl, CURLOPT_TIMEOUT, 10);
		//for debug only!
		// curl_setopt($curl, CURLOPT_VERBOSE, true);
		// curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		// curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
	}
	
	// POST login credentials and cache token
	$url = BASE_URL.LOGIN_URL;
	$data = new stdClass();
	$data->identity = USERNAME;
	$data->password = PASSWORD;
	$msg = JSON_encode($data);
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, $msg);

	report('call login to Geo API. url:'.$url.' post:'.$msg,LOG_DEBUG);
	$resp = curl_exec($curl);
	// curl_close($curl); // leave connection open if possible 
	report('login got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('Geotogether-domo login failed. Code:'.$code,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	
	$data = JSON_decode($resp, false);
	if(!isset($data->accessToken)) {
		report('Geotogether-domo login token not found. Response: '.$resp,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	array_push($headers, "Authorization: Bearer ".$data->accessToken); // set access token into headers
	curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
	
	// GET device ID data
	$url = BASE_URL.DEVICEDETAILS_URL;
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, null);
	curl_setopt($curl, CURLOPT_POST, false);

	report('call deviceID to Geo API. url:'.$url,LOG_DEBUG);
	$resp = curl_exec($curl);
	report('deviceID got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('Geotogether-domo deviceID failed. Code:'.$code,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	
	$data = JSON_decode($resp, false);
	if(!isset($data->systemRoles[0]->systemId)) {
		report('Geotogether-domo deviceID not found. Response: '.$resp,LOG_WARNING);
		curl_close($curl); $curl=false;
		return false;
	}
	$deviceId = $data->systemRoles[0]->systemId;
	$systemName = $data->systemDetails[0]->name;
	
	report('Geotogether-domo login success. DeviceID: '.$deviceId.' System name: '.$systemName,LOG_NOTICE);
	return true;
}

function get_live() {
	global $curl;
	global $deviceId;
	
	// check connection
	if(!$deviceId) {
		if(!connect_geo()) {
			return false;
		}
	}
	
	// build GET request
	$url = BASE_URL.LIVEDATA_URL.$deviceId;
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, null);
	curl_setopt($curl, CURLOPT_POST, false);

	report('call livedata to Geo API. url:'.$url,LOG_DEBUG);
	$resp = curl_exec($curl);
	// curl_close($curl); // leave connection open if possible 
	report('livedata got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('Geotogether-domo livedata failed. Code:'.$code,LOG_WARNING);
		$deviceId=false; // force reconnnct next time
		curl_close($curl); $curl=false;
		return false;
	}
	
	return JSON_decode($resp, false);
}

function get_periodic() {
	global $curl;
	global $deviceId;
	
	// check connection
	if(!$deviceId) {
		if(!connect_geo()) {
			return false;
		}
	}
	
	// build GET request
	$url = BASE_URL.PERIODICDATA_URL.$deviceId;
	curl_setopt($curl, CURLOPT_URL, $url);
	curl_setopt($curl, CURLOPT_POSTFIELDS, null);
	curl_setopt($curl, CURLOPT_POST, false);

	report('call periodicdata to Geo API. url:'.$url,LOG_DEBUG);
	$resp = curl_exec($curl);
	// curl_close($curl); // leave connection open if possible 
	report('periodicdata got:'.$resp,LOG_DEBUG);
	
	// check response
	$code = 0;
	if (false === $resp || ($code = curl_getinfo($curl, CURLINFO_HTTP_CODE)) > 200) {
		report('Geotogether-domo periodicdata failed. Code:'.$code,LOG_WARNING);
		$deviceId=false; // force reconnnct next time
		curl_close($curl); $curl=false;
		return false;
	}
	
	return JSON_decode($resp, false);
}

// *** MAIN STARTS HERE ***

/*
 * SIGHUP handler to generate debug trace!
 */
//pcntl_async_signals(TRUE); //not supported in 5.6
declare(ticks = 1); //ouch ...

pcntl_signal(SIGHUP, function($signal) {
	ob_start();
	debug_print_backtrace();
	$trace = ob_get_contents();
	ob_end_clean();
	$trace = 'Debug trace called: '.str_replace(PHP_EOL,',',$trace);
	echo $trace;
	report($trace,LOG_INFO);
});
 

// make MQTT instance
$mqtt = new phpMQTT($server, $port, $client_id);

$lasttelemetry = 0;
$lastpoll = 0;
$lastperiodic = 0;
$nodata = true;
// infinite loop here
while (true) {
	// connect to MQTT domoticz/in topic
	if(!$mqtt->connect(true, NULL, $username, $password)) {
		report('Geotogether-domo cannot connect to MQTT - retrying in 10 sec',LOG_ERROR);
		sleep(10);
	} else {
		report('Geotogether-domo connected to queue:'.$server.':'.$port,LOG_NOTICE);
		
// Subscribe to control topic and openEVSE current only - might pick up domoticz/out for messaging Geo if API supports any control inputs?
//		$topics['domoticz/out'] = array("qos" => 0, "function" => "procmsg");
		$topics['geotogether-domo/cmd'] = array("qos" => 0, "function" => "procmsg");
		$topics[SIMPLE_VOLTAGE_TOPIC] = array("qos" => 0, "function" => "procmsg");
		$mqtt->subscribe($topics, 0);

		while($mqtt->proc()){
			$now=time();
			// do this first to set totalImport value
			if($lastperiodic < $now-$periodic_sec) {
				$tele = 'Geotogether-domo poll periodic';
				report($tele,LOG_DEBUG);
				// fake MQTT getperiodic call
				procmsg('geotogether-domo/cmd','getperiodic', false);
				$lastperiodic = $now;
			}
			if($lastpoll < $now-$polling_sec) {
				$tele = 'Geotogether-domo poll live';
				report($tele,LOG_DEBUG);
				// fake MQTT getlive call
				procmsg('geotogether-domo/cmd','getlive', false);
				$lastpoll = $now;
			}
			if($lasttelemetry < $now-$telemetry_sec) {
				$tele = 'Geotogether-domo telemetry: total import = '.$totalImport;
				$log_lvl = LOG_INFO;
				if($nodata) { $tele = $tele.' ALARM: no data received in telemetry period'; $log_lvl = LOG_ERROR; }
				$nodata = true; // set alarm for next period
				report($tele,$log_lvl);
				$lasttelemetry = $now;
			}
			// dont hog the CPU! proc() is non-blocking
			sleep(1);
		}

		// proc() returned false - reconnect
		report('Geotogether-domo lost MQTT connection - retrying',LOG_NOTICE);
		$mqtt->close();
	}
}

function procmsg($topic, $msg, $retain){
	global $debug;
	global $mqtt;
	global $telemetry_sec;
	global $periodic_sec;
	global $deviceId;
	global $systemName;
	global $totalImport;
	global $nodata;
	global $lastperiodic;
	global $lasttelemetry;
	global $domo_idx_import;
	global $domo_idx_export;
	global $domo_idx_evpwr;
	global $simple_pwr_offset;
	global $voltage;
	global $lastvoltage;
	
	$now = time();
	// skip retain flag msgs (LWT usually)
	if($retain)
		return;
	// process by topic
	report('msg from:'.$topic,LOG_DEBUG);
	if ($topic=='geotogether-domo/cmd') {
		report("cmd:".$msg,LOG_DEBUG);
		if((empty($msg))|| $msg=='status') {
			$data = new stdClass();
			$data->cmd = "status";
			$data->deviceId = $deviceId;
			$data->systemName = $systemName;
			$data->nodata = $nodata;
			$data->secstonextperiodical = $lastperiodic+$periodic_sec-$now;
			$data->secstonexttelemetry = $lasttelemetry+$telemetry_sec-$now;
			$data->nodata = $nodata;
			$data->voltage = $voltage;
			$msg = JSON_encode($data);
			$mqtt->publish('geotogether-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='reset') {
			// logout and reconnect to Geotogether API
			if(connect_geo()) $state = 'connected';
			else $state = 'unconnected';
			$data = new stdClass();
			$data->cmd = "reset";
			$data->state = $state;
			$msg = JSON_encode($data);
			$mqtt->publish('geotogether-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='config') {
			$data = new stdClass();
			$data->cmd = "config";
			$data->debug = $debug;
			$data->telemetry_sec = $telemetry_sec;
			$data->geourl = BASE_URL;
			$data->domo_idx_import = $domo_idx_import;
			$data->domo_idx_export = $domo_idx_export;
			$data->offset = $simple_pwr_offset;
			$msg = JSON_encode($data);
			$mqtt->publish('geotogether-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='debug') {
			$debug = !$debug; // toggle and report debug state
			$data = new stdClass();
			$data->cmd = "debug";
			$data->debug = $debug;
			$msg = JSON_encode($data);
			$mqtt->publish('geotogether-domo/status',$msg,0);
			if($debug) echo 'reply:'.$msg."\n";
			return;
		}
		if(strpos($msg,'offset')===0) {
			$simple_pwr_offset = intval(explode(' ',$msg,2)[1]);
			$data = new stdClass();
			$data->cmd = "offset";
			$data->offset = $simple_pwr_offset;
			$msg = JSON_encode($data);
			$mqtt->publish('geotogether-domo/status',$msg,0);
			report('reply:'.$msg,LOG_DEBUG);
			return;
		}
		if($msg=='getlive') {
			if(false === ($livedata = get_live())) {
				// failed - report to status channel
				$data = new stdClass();
				$data->cmd = "getlive";
				$data->state = "live failed";
				$msg = JSON_encode($data);
				$mqtt->publish('geotogether-domo/status',$msg,0);
				report('reply:'.$msg,LOG_DEBUG);
			} else {
				// success - send to domoticz
				// check we got a reading!
				$power = 0;
				if($livedata->power!=null) {
					$nodata = false; // clear alarm
					$meter_count = count($livedata->power);
					for($i = 0; $i < $meter_count; $i++) {
						if($livedata->power[$i]->type == 'ELECTRICITY') {
							// extract watts data and abort loop
							$power = $livedata->power[$i]->watts;
							break;
						}
					}
				}
				$data = new stdClass();
				// update import idx using most recent totalImport, send zero if exporting
				$data->idx = $domo_idx_import;
				$data->nvalue = 0;
				$data->svalue = $power < 0? 0 : $power;
				$data->svalue = $data->svalue.';'.$totalImport;
				$msg = JSON_encode($data);
				$mqtt->publish('domoticz/in',$msg,0);
				report('send to domo:'.$msg,LOG_DEBUG);
				// update export idx, send zero if importing, no total here
				$data->idx = $domo_idx_export;
				$data->nvalue = 0;
				$data->svalue = $power < 0? $power : 0;
				$data->svalue = $data->svalue.';0';
				$msg = JSON_encode($data);
				$mqtt->publish('domoticz/in',$msg,0);
				report('send to domo:'.$msg,LOG_DEBUG);
				// update simple power MQTT topic
				// adjust with test/force offset
				// positive value is subtracted to give more apparent export, negative value is taken directly as export
				$power = $simple_pwr_offset < 0 ? $simple_pwr_offset : $power - $simple_pwr_offset;
				$mqtt->publish(SIMPLE_PWR_TOPIC,$power,0);
				report('send to simple:'.$power,LOG_DEBUG);
			}
			return;
		}
		if($msg=='getperiodic') {
			if(false === ($periodicdata = get_periodic())) {
				// failed - report to status channel
				$data = new stdClass();
				$data->cmd = "getperiodic";
				$data->state = "periodic failed";
				$msg = JSON_encode($data);
				$mqtt->publish('geotogether-domo/status',$msg,0);
				report('reply:'.$msg,LOG_DEBUG);
			} else {
				// success - extract and record total import for later
				$meter_count = count($periodicdata->totalConsumptionList);
				for($i = 0; $i < $meter_count; $i++) {
					if($periodicdata->totalConsumptionList[$i]->commodityType == 'ELECTRICITY') {
						// extract energy data and abort loop
						$totalImport = $periodicdata->totalConsumptionList[$i]->totalConsumption * 1000; // API gives kWh, domo needs Wh
						report('total import updated: '.$totalImport,LOG_DEBUG);
						break;
					}
				}
				
			}
			return;
		}
	}
	else {
		// not expecting other topics - skip out
		report('unexpected message topic:'.$topic,LOG_DEBUG);
	}
	return;
}
