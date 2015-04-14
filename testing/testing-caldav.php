<?php

// Test CalDAV server
// This code will do an initial sync and a second sync.

require_once 'vendor/autoload.php';

define('LOGLEVEL', LOGLEVEL_DEBUG);
define('LOGUSERLEVEL', LOGLEVEL_DEVICEID);

$username = "fmbiete";
$password = "fmb13t313";

define('CALDAV_SERVER', 'https://calendariocorreo.epi.es');
// define('CALDAV_PORT', '446');
// define('CALDAV_SERVER', 'https://calendariocorreo.renr.es');
define('CALDAV_PORT', '443');
define('CALDAV_PATH', '/caldav.php/%u/');
define('CALDAV_PERSONAL', 'EPI');
define('CALDAV_SUPPORTS_SYNC', true);

$caldav_path = str_replace('%u', $username, CALDAV_PATH);
$caldav = new CalDAVClient(CALDAV_SERVER . ":" . CALDAV_PORT . $caldav_path, $username, $password);

printf("Connected %d\n", $caldav->CheckConnection());

// Show options supported by server
// $options = $caldav->DoOptionsRequest();
// print_r($options);

// $calendars = $caldav->FindCalendars();
// print_r($calendars);

$path = $caldav_path . "EPI" . "/";
// $val = $caldav->GetCalendarDetails($path);
// print_r($val);

// $begin = gmdate("Ymd\THis\Z", time() - 24*7*60*60);
// $finish = gmdate("Ymd\THis\Z", 2147483647);
// $msgs = $caldav->GetEvents($begin, $finish, $path);
// print_r($msgs);

// Initial sync
$results = $caldav->GetSync($path, true, CALDAV_SUPPORTS_SYNC);
print_r($results);

sleep(60);

$results = $caldav->GetSync($path, false, CALDAV_SUPPORTS_SYNC);
print_r($results);
