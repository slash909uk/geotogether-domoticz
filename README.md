# geotogether-domoticz
A simple PHP script to poll smart meter live data from the Geotogether (reverse engineered!) API and push this into domoticz via an MQTT broker. Depends on a couple of other small PHP projects in my repos; phpMQTT and Syslog. You can find these in my public repo list.

Presently I am using these scripts myself with a Geo Trio II smartmeter IHD + WiFi interface, and Ecotricity's utility supply.

V1.1 - support negative power response indicating export of energy to the grid. Split into two Domoticz devices accordingly.

This work is based on that done by owainlloyd here: https://github.com/owainlloyd/Geohome_Integration - thanks!
