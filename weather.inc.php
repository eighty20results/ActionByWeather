<?php
/**
 * Created by JetBrains PhpStorm.
 * User: sjolshag
 * Date: 1/3/13
 * Time: 9:47 AM
 * To change this template use File | Settings | File Templates.
 */

define('WU_API_KEY', "2cf4939aff4f59c2");
define('WU_CITY', "Goffstown");
define('WU_STATE', "NH");
define('PRECIPITATION_MAX_INCHES', 3);
define('PRECIPITATION_THRESHOLD', (25.4 * PRECIPITATION_MAX_INCHES) ); /* == 76.2 */
define('PRECIPITATION_DEVIATION', (25.4 * 1)); // Assuming 1 inch is an acceptable deviation between forecast & actual.
// define('MIN_TEMP', -8.33); // Lowest temperature where we'll allow the de-icing cables to be enabled.
define('MIN_TEMP', -20.33); // Lowest temperature where we'll allow the de-icing cables to be enabled.
/*
 * If the difference between the forecast amount and the actual recorded amount is > PRECIPITATION_DEVIATION we will
 * always use the actual (recorded) value as the default.
 */

/* MySQL database connection variables/constants */
define('DB_NAME', "vera_weather");
define('DB_USER', "veraAdmin");
define('DB_PASSWORD', "ePFJmjrjv2rh8G4");
define('DB_HOST', "127.0.0.1");

define('STATE_YES', 1);
define('STATE_NO', 0);
define('STATE_ACTIVE', 2);
define('STATE_ERROR', 3);
$DEBUG = true;
$TOWEB = false;
$THRESHOLD = E_USER_WARNING;
