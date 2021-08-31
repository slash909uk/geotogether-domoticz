<?php
// S Ashby, 03/08/2021, created
// script to poll Geotogether smartmeter API and push data into domoticz via MQTT broker

echo "Geotogether-domo MQTT bridge started\n";

require "../phpMQTT-master/phpMQTT.php";

$domo_idx_elec = 2599; // domoticz IDX for the electricity meter device to update

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

// telemetry interval
$telemetry_sec = 600;
// debug
$debug=false;

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

// make MQTT instance
$mqtt = new phpMQTT($server, $port, $client_id);

$lasttelemetry = time();
$lastpoll = time();
// infinite loop here
while (true) {
	// connect to MQTT domoticz/in topic
	if(!$mqtt->connect(true, NULL, $username, $password)) {
		report('Geotogether-domo cannot connect to MQTT - retrying in 10 sec',LOG_ERROR);
		sleep(10);
	} else {
		report('Geotogether-domo connected to queue:'.$server.':'.$port,LOG_NOTICE);
		
// Subscribe to control topic only - might pick up domoticz/out for messaging Geo if API supports any control inputs?
//		$topics['domoticz/out'] = array("qos" => 0, "function" => "procmsg");
		$topics['geotogether-domo/cmd'] = array("qos" => 0, "function" => "procmsg");
		$mqtt->subscribe($topics, 0);

		while($mqtt->proc()){
			$now=time();
			if($lastpoll < $now-$polling_sec) {
				$tele = 'Geotogether-domo poll live';
				report($tele,LOG_DEBUG);
				// fake MQTT getlive call
				procmsg('geotogether-domo/cmd','getlive', false);
				$lastpoll = $now;
			}
			if($lasttelemetry < $now-$telemetry_sec) {
				$tele = 'Geotogether-domo telemetry';
				report($tele,LOG_INFO);
				$lasttelemetry = $now;
			}
		}

		// proc() returned false - reconnect
		report('Geotogether-domo lost MQTT connection - retrying',LOG_NOTICE);
		$mqtt->close();
	}
}

function procmsg($topic, $msg, $retain){
	global $mqtt;
	global $telemetry_sec;
	global $deviceId;
	global $systemName;
	global $domo_idx_elec;
	
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
			$data->now = $now;
			$data->deviceId = $deviceId;
			$data->systemName = $systemName;
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
			$data->telemetry_sec = $telemetry_sec;
			$data->geourl = BASE_URL;
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
				$meter_count = count($livedata->power);
				for($i = 0; $i < $meter_count; $i++) {
					if($livedata->power[$i]->type == 'ELECTRICITY') {
						// extract watts data and abort loop
						$power = $livedata->power[$i]->watts;
						$data = new stdClass();
						$data->idx = $domo_idx_elec;
						$data->nvalue = 0;
						$data->svalue = $power.';0';
						$msg = JSON_encode($data);
						$mqtt->publish('domoticz/in',$msg,0);
						report('send to domo:'.$msg,LOG_DEBUG);
						break;
					}
				}
			}
			return;
		}
	}
/*	else if ($topic=='domoticz/out') {
		// domoticz msgs - use 'idx' field as ID
		$data = JSON_decode($msg);
		if($debug) echo "out:".$data."\n";
		// Skip if Type == Scene (not a device, overlapping IDX!)
		if(strcmp($data->Type,'Scene')==0)
			return;
		// Skip if Type == Group (not a device, overlapping IDX!)
		if(strcmp($data->Type,'Group')==0)
			return;
		// Skip if name == Unknown (spurious RFXCOM devices)
		if(strcmp($data->name,'Unknown')==0)
			return;
		$id = $data->idx;
		$name = $data->name;
	}
*/
	else {
		// not expecting other topics - skip out
		report('unexpected message topic:'.$topic,LOG_DEBUG);
	}
	return;
}
