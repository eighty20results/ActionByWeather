<?php

require_once './weather.inc.php';

/*
 * Basic assumptions:
 *      Run the de-icing cables 1 hour for every 75.2mm of snow (precip_today AND blFreezingRain == false)
 *      Run the de-icing cables 1 hour for every 7.5mm of freezing rain (precip_today AND blFreezingRain = True)
 * /

/* Used to collect and save structured forecast & current condition data from Weather Underground */
$WUurl = "http://api.wunderground.com/api/" . WU_API_KEY . "/geolookup/forecast/q/" . WU_STATE . "/" . WU_CITY . ".json";
$json_string = file_get_contents($WUurl);
$parsed_json = json_decode($json_string);

$forecast = $parsed_json->{'forecast'}->{'simpleforecast'};

/* Grab structured data - the forecast - from Weather Underground */
$WUurl = "http://api.wunderground.com/api/" . WU_API_KEY . "/geolookup/conditions/q/" . WU_STATE . "/" . WU_CITY . ".json";
$json_string = file_get_contents($WUurl);
$parsed_json = json_decode($json_string);

$weather = $parsed_json->{'current_observation'};

$WUurl = null;
$parsed_json = null;
$json_string = null;

/* Database object */
$dbLink = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME,
    DB_USER,
    DB_PASSWORD
);

switch ($_GET['action']) {

    case 'weather':
        tlsDebug('Updating forecast & De-Icing information');
        fnUpdateWeather();
        echo '1';
        break;

    case 'update':
        tlsDebug('De-Icing scene is complete. Increment the completed deicing DB entry');
        if (isset($_GET['value'])) {
           $retval = fnIncrCompletedHrs( $_GET['value']);
        } else {
            $retval = fnIncrCompletedHrs( 1 );
        }
        if ($retval != true ) {
            echo '0';
        } else {
            tlsDebug('Cables are now inactive, update DB to reflect it');
            fnSetActive(0);
            echo '1';
        }
        break;

    case 'clear':
        fnSetActive(0);
        echo '1';
        break;

    case 'check':
        tlsDebug('Running the cable check');
        // ' Need to return a valid HTML page for the receiving entity to get the result from the fnCheck';
        echo fnCheck() . "\n";
        break;
}
/* *************************** Actual program execution ******************************
 * Do 'step 1' hourly?
 * Do 'step 2' hourly!
 * Do 'Step 3' hourly!
 * Do 'Step 4' hourly!
 * Do 'Step 5' on Completion of Vera de-icing scene, only!
 *************************************************************************************/

/*
 * Function is executed by the Vera system to update weather forecast info and de-Icing hours.
 */
function fnUpdateWeather() {
    /* Step 1 */
    if (! fnSaveSnowForecast()) {
        tlsDebug('Didn\'t save the snow forecast!', E_USER_ERROR);
    }
    /* Step 2 */
    if (! fnUpdateAccumulation()) {
        tlsDebug('Didn\'t save the snow accumulation amounts!', E_USER_WARNING);
    }
    /* Step 3 */
    if (! fnCalcDeiceHours()) {
        tlsDebug('Could not calculate the De-Icing hours!', E_USER_ERROR);
    }
}
/*
 * Function is executed by the Vera system as part of the regularly scheduled de-icing cable checks.
 */

function fnCheck() {
    $state = fnRecommendCableState();
    switch ($state) {
        case STATE_NO:
            tlsDebug('Logic recommends not to enable the de-icing cables', E_USER_WARNING);
            break;
        case STATE_ACTIVE:
            tlsDebug('Cables are already active, according to the database', E_USER_WARNING);
            break;
        case STATE_ERROR:
            tlsDebug('Error while checking for recommendation', E_USER_WARNING);
            break;
        case STATE_YES:
            tlsDebug('Recommendation is to run the de-icing cables', E_USER_WARNING);
            fnSetActive(1);
            break;
    }
    return $state;
}

/* Step -1: On completion of the deicing scene: fnIncrCompletedHrs() */

/*
 * Function will set the 'blCablesEnabled' flag in the database to indicate that a de-icing event is underway.
 */
function fnSetActive($blEnable = 0) {
    global $dbLink;
    $varArr = array();

    $SQL = "UPDATE vw_precipitation SET blCablesEnabled = :blEnabled, ts_updated = CURRENT_TIMESTAMP WHERE ((numYear = :numYear) AND (numDoY = :DoY));";
    try {
        $dbLink->beginTransaction();
        $rs = $dbLink->prepare($SQL);

        if ($blEnable == 1) {
            $varArr = array(
                ':numYear' => date('Y'),
                ':DoY' => date('z'),
                ':blEnabled' => true
            );
        } else {
            $varArr = array(
                ':numYear' => date('Y'),
                ':DoY' => date('z'),
                ':blEnabled' => false
            );
        }

        $rs->execute($varArr);
        $dbLink->commit();
    }
    catch (PDOException $e) {
        $dbLink->rollBack();
        tlsDebug('Error updating flag for active cables: ' . $e->getMessage(), E_USER_ERROR);
    }
}

/*
 * Function Purpose: Save the forecast precipitation amount for the day of the year & year when the observation was made,
 * assuming the conditions in the forecast claim snow/sleet/freezing rain.
 * @params:
 * @returns: true/false
 */
function fnSaveSnowForecast()
{
    /*
     * TODO: Account for the "Forecast Description Phrases" on http://www.wunderground.com/weather/api/d/docs?d=resources/phrase-glossary
     * Currently missing phrases: sleet, flurries
     */

    $retval = true; /* Assume the save operation will work as expected */

    $blInsert = false;
    $blFreezingRain = 0;

    /* Connect to the database instance */
    global $dbLink;
    global $forecast;

    /* Grab structured data - the forecast - from Weather Underground */
    /* Test URL: http://api.wunderground.com/api/2cf4939aff4f59c2/geolookup/forecast/q/NH/Goffstown.json */

    foreach ($forecast->{'forecastday'} as $fcday)
    {
        $fcastPrecip = 0;

        $doY = $fcday->{'date'}->{'yday'};
        $cYear = $fcday->{'date'}->{'year'};
        $HiTemp = $fcday->{'high'}->{'celsius'};

        /* Save the forecast value in mm (millimeters), so multiply cm value with 10 */
        $snowDay = $fcday->{'snow_day'}->{'cm'} * 10;
        $snowNight = $fcday->{'snow_night'}->{'cm'} * 10;
        $snowTot = $fcday->{'snow_allday'}->{'cm'} * 10;

        if ($snowTot >= ($snowDay + $snowNight)) {
            $fcastPrecip = $snowTot;
        } else {
            $fcastPrecip = ($snowDay + $snowNight);
        }

        if (preg_match('/freezing/i', $fcday->{'conditions'})) {
            // Warning: We'll have freezing rain!.
            toWeb("Freezing rain anticipated!<br/>");
            $fcastPrecip = $fcday->{'qpf_allday'}->{'mm'};
            $blFreezingRain = 1;
        }

        tlsDebug('Condition for day ' . $doY . ' is expected to be: ' . $fcday->{'conditions'}, E_USER_NOTICE);

        $SQL = "SELECT numHighCelsius, numForecastPrecip FROM vw_precipitation WHERE ((numYear = :cYear) AND (numDoY = :DoY));";

        try {

            $rsh = $dbLink->prepare($SQL);
            $rsh->execute(array(':cYear' => $cYear, ':DoY' => $doY));

            if ($rsh->rowCount() == 0) {
                $blInsert = true;
                tlsDebug('DB query for day ' . $doY . ' returned no data!', E_USER_NOTICE);
            } else {
                /* The query returned data so we'll need to see whether we need to update the database or not*/
                $blInsert = false; /* We won't be inserting new data, regardless */
            }
            // $row = mysql_fetch_assoc($result);
            while ($row = $rsh->fetch(PDO::FETCH_ASSOC)) {
                /* If there are no changes in the forecast to what we've already recorded, exit this function */
                if (($row['numHighCelsius'] == $HiTemp ) && ($row['numForecastPrecip'] == $fcastPrecip)) {
                    tlsDebug('No change in Precipitation or the High temp for day: ' . $doY, E_USER_NOTICE);
                    $retval = true;
                }
            } // End of while - read results from db
        } // End of Try block
        catch(PDOException $e) {
            tlsDebug('Error checking for existing forecast data: '. $e->errorInfo, E_USER_WARNING);
        } // End of catch

        if (($blFreezingRain != 0) || (
        (preg_match('/snow/i', $fcday->{'conditions'}) || preg_match('/blizzard/i', $fcday->{'conditions'} || preg_match('/ice/i', $fcday->{'conditions'} )) && (
            (!preg_match('/blowing/i', $fcday->{'conditions'})) &&
                (!preg_match('/drifting/i', $fcday->{'conditions'}))
        ))))
        {
            tlsDebug('Conditions indicate snow or sleet. Saving the forecast.', E_USER_NOTICE);

            if ($blInsert) {
                $SQL = "INSERT INTO vw_precipitation (numHighCelsius, numYear, numDoY, numForecastPrecip, blFreezingRain) ";
                $SQL .= "VALUES ( :numHighCelsius, :numYear, :numDoY, :numForecastPrecip, :blFreezingRain);";
            } else {
                $SQL = "UPDATE vw_precipitation SET ";
                $SQL .= "numHighCelsius = :numHighCelsius, ";
                $SQL .= "numYear = :numYear, ";
                $SQL .= "numDoY = :numDoY, ";
                $SQL .= "numForecastPrecip = :numForecastPrecip, ";
                $SQL .= "ts_updated = CURRENT_TIMESTAMP, ";
                $SQL .= "blFreezingRain = :blFreezingRain ";
                $SQL .= "WHERE ((numYear = :numYear) AND (numDoY = :numDoY));";
            }

            try {

                $dbLink->beginTransaction();

                $rsh = $dbLink->prepare($SQL);
                $result = $rsh->execute(
                    array(':numYear' => $cYear,
                        ':numDoY' => $doY,
                        ':numHighCelsius' => $HiTemp,
                        ':numForecastPrecip' => $fcastPrecip,
                        ':blFreezingRain' => $blFreezingRain
                    )
                );

                $dbLink->commit();
                $retval = true;
            }
            catch (PDOException $e) {
                $dbLink->rollBack();
                tlsDebug('Error updating/adding forecast data: '. $e->errorInfo, E_USER_WARNING);
                $retval = false;
            } // End Try/Catch
        } // End of IF - Weather conditions

    } // End of foreach forecast day

    return $retval;
}

/*
 * This function will analyze the data from the MySQL database and return a
 * recommendation for whether to enable or disable the roof de-icing cables.
 *
 */
function fnRecommendCableState()
{
    /* Default value for recommendation is 'no' - aka false */
    $enableCable = STATE_NO;

    /*
     *   Database connection variable and the "current weather conditions" variable
     */
    global $dbLink, $weather;
    $precipDeviation = PRECIPITATION_DEVIATION;
    $precipThreshold = PRECIPITATION_THRESHOLD;

    $DoY = date('z');
    $Year = date('Y');

    /*
     * Check whether or not the blCablesEnabled flag is set at this time.
     * If it is, we'll just exit right now.
     */

    $SQL = "SELECT blCablesEnabled FROM vw_precipitation WHERE ((numYear = :numYear) AND (numDoY = :numDoY));";
    $rs = $dbLink->prepare($SQL);
    $rs->execute(array(':numDoY' => $DoY, ':numYear' => $Year));

    if ($rs->rowCount() == 0) {
        tlsDebug('DB query for deIcing data (' . $DoY . ') returned no data', E_USER_NOTICE);
    } else {

        tlsDebug('DB query for deIcing data (' . $DoY . ') returned data...', E_USER_NOTICE);

        while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {
            if ($row['blCablesEnabled']) {
                tlsDebug('Cables are already on', E_USER_NOTICE);
                // Do not enable the cables again, they're already active.
                return STATE_ACTIVE;
            }
        }
    }

    /* Init all other variables we use */
    $precipitation = null;
    $row = null;
    $rs = null;

    // SQL to grab forecast data for yesterday
    $SQL = 'SELECT numPlannedDeIceHrs, numComplDeIceHrs ';
    $SQL .= 'FROM vw_precipitation ';
    $SQL .= 'WHERE ((numDoY = :numDoY) AND (numYear = :numYear));';

    try {
        $rs = $dbLink->prepare($SQL);
        $rs->execute(array(':numDoY' => $DoY, ':numYear' => $Year));

        if ($rs->rowCount() == 0) {
            tlsDebug('DB query for deIcing data (' . $DoY . ') returned no data', E_USER_NOTICE);
        } elseif ($rs->rowCount() != 0) {
            while ($row = $rs->fetch(PDO::FETCH_ASSOC)) {

                // Do something to process the data
                if ((is_null($row['numPlannedDeIceHrs'])) && (is_null($row['numComplDeIceHrs']))) {
                    $enableCable = STATE_NO;
                    tlsDebug('No planned or actual cable data recorded for today!', E_USER_WARNING);
                } else {
                    $plannedDeIce = (is_null($row['numPlannedDeIceHrs'])) ? 0 : $row['numPlannedDeIceHrs'];
                    $complDeIce = (is_null($row['numComplDeIceHrs'])) ? 0 : $row['numComplDeIceHrs'];
                } /* End of if is_null() */

                if ($plannedDeIce > $complDeIce) {
                    tlsDebug("Planned > Completed, returning 'yes' status", E_USER_NOTICE);
                    $enableCable = STATE_YES;
                } elseif ($plannedDeIce < $complDeIce) {
                    tlsDebug('Cables have been run for more than the planned-for time today', E_USER_WARNING);
                    $enableCable = STATE_NO;
                } else {
                    tlsDebug('Planned and actual run time is the same.');
                    $enableCable = STATE_NO;
                }
            } /* End of While Loop */
        } /* End of if record set contains data */
    } /* End of Try block */
    catch (PDOException $e) {
        tlsDebug('Error obtaining data for the current day (' . $DoY . '): '. $e->getMessage(), E_USER_WARNING);
        $enableCable = STATE_ERROR;
    }

    /* Check what the current temperature is before going ahead w/any 'enableCable = true' recommendation. */

    if (($enableCable) && ($weather->{'temp_c'} < MIN_TEMP)) {
        tlsDebug('Too cold to start de-cing cables!');
        $enableCable = STATE_NO;
    }

    /* And override the temp reading if it's currently rain or drizzle! */
    if ((! $enableCable) && (
        ( preg_match('/(light|heavy)\brain\b/i', $weather->{'weather'})) ||
        ( preg_match('/(light|heavy)\bdrizzle\b/i', $weather->{'weather'})) ) )
    {
        tlsDebug('Current condition is rain/drizzle. Need to enable the cables, regardless!', E_USER_WARNING);
        $enableCable = STATE_YES;
    }

    /* Return the recommendation to the calling function */
    if (is_null($enableCable)) {
        tlsDebug("Error: No recommendation given?!?", E_USER_WARNING);
    }
    return $enableCable;
}

/*
 *  Function will add 2 hours to the numDeiceHours field for the date('z') - numDoY & date('Y') - numYear
 *  in the MySQL database for every PRECIPITATION_THRESHOLD multiple.
 *
 *  This function is normally run by Vera's De-Ice scene at the end of the deicing cycle.
 *
 * Calculate the number of hours to run the de-ice cables for the current amount of precipitation
 *
 * Assumption: 1 hour of de-icing per 3 inches of snow or .3 inches of ice
 * Assumption: Accumulation is always read from the database.
 * Assumption: If freezing rain, we only need 1/10th the amount of precipitation to trigger the cables
 */

function fnCalcDeiceHours()
{
    /* Initialize local variables to use */
    global $dbLink, $weather;
    $rs = null;
    $row = null;
    $recordedPrecip = 0;

    $precipDeviation = PRECIPITATION_DEVIATION;
    $precipThreshold = PRECIPITATION_THRESHOLD;

    $old_deiceHours = 0;
    $yest_deIceHours = 0;
    $yest_precip = 0;
    $plannedDeiceHoursYest = 0;
    $recordedPlannedHrs = 0;
    $recordedCompletedHrs = 0;
    $deIceHoursLeftYest = 0;
    $complDeiceHoursYest = 0;
    $newDeIceHours = 0;
    $deIceFromYestForecast = 0;

    $deIceHours = 0;
    $recordedHrsLeft = 0;

    $fcastPrecip = 0;
    $actualPrecip = 0;

    // Get the Day-of-Year and Year for yesterday.
    $DoY = (((date('z')-1) < 0) ? date('z', strtotime('12/31/' . date('Y', strtotime('last year')))) : (date('z')-1) );
    $Year = (((date('z')-1) < 0) ? date('Y', strtotime('last year')) : date('Y') );

    // Day of Year & Year for today
    $nowDoY = date('z');
    $nowYear = date('Y');

    // SQL to grab forecast data for yesterday
    $SQL = 'SELECT numActualPrecip, blFreezingRain, numForecastPrecip, numPlannedDeIceHrs, numComplDeIceHrs ';
    $SQL .= 'FROM vw_precipitation ';
    $SQL .= 'WHERE ((numDoY = :numDoY) AND (numYear = :numYear));';

    /* Collect the data stored in the database about yesterdays precipitation, etc */
    try {
        $rs = $dbLink->prepare($SQL);
        $rs->execute(array(':numDoY' => $DoY, ':numYear' => $Year));

        if ($rs->rowCount() == 0) {
            tlsDebug('DB query for (' . $DoY . ') returned no data', E_USER_NOTICE);
        } elseif ($rs->rowCount() != 0) {

            tlsDebug('DB query for (' . $DoY . ') returned data...', E_USER_NOTICE);

            /* Process the data retrieved from yesterday's forecast/de-icing activities */
            while ($row = $rs->fetch(PDO::FETCH_ASSOC)){

                /* If yesterday saw freezing rain, we'll need to account for the change in precipitation levels */
                /* Typically, weather services operate w/the volume of snow being 10x the volume of rain/ice */

                if ((! is_null($row['blFreezingRain'])) && ($row['blFreezingRain'] == '1')) {
                    /* We've got freezing rain in the forecast/reported weather*/
                    tlsDebug("We had freezing rain yesterday, so set precipitation requirements accordingly", E_USER_NOTICE);
                    $precipDeviation = (PRECIPITATION_DEVIATION / 10); /* Assuming 10 to 1 snow/rain volume ratio */
                    $precipThreshold = (PRECIPITATION_THRESHOLD / 10);
                }

                // Save the # of hours left to de-ice from yesterday
                $plannedDeiceHoursYest = is_null($row['numPlannedDeIceHrs']) ? 0 :  $row['numPlannedDeIceHrs'];
                $complDeiceHoursYest = is_null($row['numComplDeIceHrs']) ? 0 :  $row['numComplDeIceHrs'];

                /* Calculate the remaining deicing hours left to do - will be done today, if possible */
                if ($complDeiceHoursYest >= $plannedDeiceHoursYest) {
                    $yest_deIceHours = 0;
                } else {
                    $yest_deIceHours = ($plannedDeiceHoursYest - $complDeiceHoursYest);
                }

                $fcastPrecip = (is_null($row['numForecastPrecip'])) ? 0 : $row['numForecastPrecip'];
                $actualPrecip = (is_null($row['numActualPrecip'])) ? 0 : $row['numActualPrecip'];

                if ($actualPrecip >= ($fcastPrecip - $precipDeviation)) {
                    $recordedPrecip = $actualPrecip;
                } else {
                    tlsDebug('Precipitation - Actual: ' . $actualPrecip . ' Forecast: ' . $fcastPrecip, E_USER_WARNING);
                    /* Set the precipitation to whatever was actually reported as recorded */
                    if ($actualPrecip > 0) {
                        tlsDebug('Setting the recorded precipitation value to that of the actual recorded value', E_USER_WARNING);
                        $recordedPrecip = $actualPrecip;
                    } else {
                        tlsDebug('Setting the recorded precipitation value to that of the forecast:' . $fcastPrecip, E_USER_WARNING);
                        $recordedPrecip = $fcastPrecip;
                    }
                }

                tlsDebug("Precipitation Data for yesterday: " . $recordedPrecip, E_USER_NOTICE);
                $deIceFromYestForecast = (int) ($recordedPrecip / $precipThreshold);
                tlsDebug('De-icing time based on yesterday\'s forecast: ' . $deIceFromYestForecast, E_USER_NOTICE);

            } /* End of While loop to process yesterday's forecast data */
        } /* End of If - Found data for yesterdays forecast */
    }
    catch (PDOException $e) {
        tlsDebug('Database Error: ' . $e->getMessage(), E_USER_WARNING);
    }

    /* check if it's currently snowing/raining ice or otherwise doing something that will accumulate on the roof
     * (and can be melted!)
     */
    if ( (preg_match('/freez/i', $weather->{'weather'})) ||
        (preg_match('/snow/i', $weather->{'weather'})) ||
        (preg_match('/ice/i', $weather->{'weather'})) ) {

        // It is, so add the accumulated precipitation for today
        $newDeIceHours += (int) ((($weather->{'precip_today_metric'} < 0) ? 0 : $weather->{'precip_today_metric'}) / $precipThreshold);
        tlsDebug("Precipitation in the current weather conditions, calculated additional de-icing hours: " . $newDeIceHours, E_USER_NOTICE);
    } else {
        toWeb("No new deIcing time to be added based on ongoing snow/freezing rain<br/>");
        tlsDebug("Conditions do not warrant immediate de-icing", E_USER_NOTICE);
    }

    tlsDebug("De-Ice hours left from yesterday: " . $yest_deIceHours);

    if ($yest_deIceHours > 0) {
        /*
         * We've accounted for the de-icing hours left from yesterday
         * so we'll need to set planned = completed for yesterday's data.
         */
        tlsDebug('Zeroing out yesterday\'s de-icing hours as we\'ll be applying them to today\'s efforts', E_USER_NOTICE);

        $SQL =  "UPDATE vw_precipitation SET ts_updated = CURRENT_TIMESTAMP, numComplDeIceHrs = :yestCompl ";
        $SQL .= "WHERE ((numYear=:numYear) AND (numDoY=:numDoY));";

        try {
            $dbLink->beginTransaction();
            $rs = $dbLink->prepare($SQL);
            $rs->execute(array( ':yestCompl' => $plannedDeiceHoursYest,
                                ':numDoY' => $DoY,
                                ':numYear' => $Year
            ));
            $dbLink->commit();
        }
        catch (PDOException $e) {
            $dbLink->RollBack();
            tlsDebug('calcDeIceHrs Error: ' . $e->getMessage(), E_USER_ERROR);
        }
    }

    /* Update the numPlannedDeIceHrs for today in the database */
    $SQL = "SELECT * from vw_precipitation WHERE ((numYear =  :numYear) AND (numDoY = :DoY));";

    $rs = $dbLink->prepare($SQL);
    $rs->execute(array(':numYear' => $nowYear, ':DoY' => $nowDoY));

    if (($dbRows = $rs->rowCount()) != 0) {
        $row = $rs->fetch(PDO::FETCH_ASSOC);
        $recordedPlannedHrs = (is_null($row['numPlannedDeIceHrs']) ? 0 : $row['numPlannedDeIceHrs']);
        $recordedCompletedHrs = (is_null($row['numComplDeIceHrs']) ? 0 : $row['numComplDeIceHrs']);

        // Need to test whether any new de-icing hours are > than the recordedPlannedHrs. If not,
        // reset $deIceFromYestForecast to 0.
        if (($deIceFromYestForecast <= $recordedPlannedHrs) && ($deIceFromYestForecast != 0)){
            tlsDebug('Resetting the forecast based calculation to 0', E_USER_WARNING);
            $deIceFromYestForecast = 0;
        }

        tlsDebug("Planned hours recorded for today: " . $recordedPlannedHrs,E_USER_NOTICE);
        tlsDebug("Completed Hours: " . $recordedCompletedHrs, E_USER_NOTICE);

    }

    /* TODO: Account for cumulative snow-fall below the threshold for past 3 (consecutive) days */
    /*
     *  Algorithm (Possibly)
     *  If the past 3 days (Day-of-Year - 2 (remember to account for new year!)) of numForecastPrecipitation > Threshold
     *  AND there num complDeIceHrs for all 3 days == 0 or is less than the calculated # of hours
     *  Then
     *  Calculate the accumulated snow amounts
     *  Use that to calculate the # of de-icing hours we should run
     *  And Subtract the sum of numComplDeIceHrs for all 3 days.
     */
    /*
     * Prepare to update the data for the current day's de-icing activities
     * We may not need to do this, it depends on the data returned!
     */


    /* Calculate the number of hours we should run the cables today */
    /*
     *   Algorithm:
     *          if ($yest_deIceHours > 0) then add the difference to the total
     *          if ($deIceFromYestForecast > 0) then add it to the total
     *          if ($newDeIceHours > 0) then add it to the total
     *          if ($recordedPlannedHrs - $recordedCompletedHrs) > 0 then add it to the total
     */

    if ($yest_deIceHours > 0) {
        $deIceHours += $yest_deIceHours;
    }
    if ($deIceFromYestForecast > 0 ) {
        $deIceHours += $deIceFromYestForecast;
    }
    if ($newDeIceHours > 0) {
        $deIceHours += $newDeIceHours;
    }
    if (($recordedPlannedHrs - $recordedCompletedHrs) > 0) {
        $deIceHours += ($recordedPlannedHrs - $recordedCompletedHrs);
    }

    // $deIceHours = ($recordedPlannedHrs + $newDeIceHours + $yest_deIceHours);
    tlsDebug("Estimated max hours for today: " . $deIceHours);

    if (($recordedPlannedHrs <= $newDeIceHours) || ($yest_deIceHours > 0)) {

        tlsDebug('Need to update the # of hours we need to de-ice today', E_USER_NOTICE);

        if ($dbRows == 0) {
            $SQL = "INSERT INTO vw_precipitation (numYear, numDoY, numPlannedDeIceHrs, numActualPrecip, ts_updated ) VALUES ( :numYear, :numDoY, :plannedHrs, :actualPrecip, CURRENT_TIMESTAMP );";
        } else {
            $SQL = "UPDATE vw_precipitation SET numPlannedDeIceHrs = :plannedHrs, ts_updated = CURRENT_TIMESTAMP WHERE ((numYear = :numYear) AND (numDoY = :numDoY));";
        }

        /* Update the database with the new information */
        try {

            $dbLink->beginTransaction();
            $rs = $dbLink->prepare($SQL);

            // Different parameters for update vs insert SQL.
            if ($dbRows == 0) {
                // INSERT
                $rs->execute( array( ':numYear' => $nowYear,
                    ':numDoY' => $nowDoY,
                    ':plannedHrs' => $deIceHours,
                    ':actualPrecip' => (($precip = $weather->{'precip_today_metric'} < 0) ? 0 : $weather->{'precip_today_metric'})
                ));
            } else {
                // UPDATE
                $rs->execute(array(':numYear' => $nowYear, ':numDoY' => $nowDoY, ':plannedHrs' => $deIceHours));
            }
            $dbLink->commit();
        }
        catch (PDOException $e) {
            $dbLink->rollBack();
            tlsDebug('Database Error: ' . $e->getMessage(), E_USER_WARNING);
        }
    }

    tlsDebug('Estimated De-Icing time for today: ' . ($deIceHours - $recordedCompletedHrs) . " hours", E_USER_WARNING);

    // Return the data to the calling function
    return true;
}

/*
 * Function will increment the 'numComplDeIceHrs' database entry for Day-of-Year: Now and numYear: Now.
 */
function fnIncrCompletedHrs( $hrsCompleted ) {

    global $dbLink;

    /* SQL to figure out how many hours we've got left to de-ice for as well as the SQL to update the Completion count */
    $updSQL = "UPDATE vw_precipitation SET numComplDeIceHrs = :hrs, ts_updated = CURRENT_TIMESTAMP WHERE ((numDoy=:numDoY) AND (numYear=:numYear));";
    $SQL = "SELECT numComplDeIceHrs, numPlannedDeIceHrs FROM vw_precipitation WHERE ((numDoY = :numDoY) AND (numYear = :numYear));";

    try {
        $rs = $dbLink->prepare($SQL);
        $rs->execute( array (
                                ':numYear' => date('Y'),
                                ':numDoY' => date('z')
        ));

        if ($rs->rowCount() > 0) {
            $row = $rs->fetch(PDO::FETCH_ASSOC);
            if (($row['numPlannedDeIceHrs'] - $row['numComplDeIceHrs']) > 0) {
                $dbLink->beginTransaction();
                $rs = $dbLink->prepare($updSQL);
                $rs->execute(array( /* Increment the number of completed deicing hours */
                                    ':hrs' => ($row['numComplDeIceHrs'] + $hrsCompleted),
                                    ':numDoY' => date('z'),
                                    ':numYear' => date('Y')
                ));
                $dbLink->commit();
            } else {
                tlsDebug('No deicing updates needed', E_USER_NOTICE);
            }
        }
    }
    catch (PDOException $e) {
        $dbLink->rollBack();
        tlsDebug('Database Error decrementing deice hours: ' . $e->getMessage(), E_USER_ERROR);
        return false;
    }

    return true;
}
/*
 * Function will update the current accumulation value for the current Day-of-Year - date('z') -  and Year - (date('Y');
 * This function should be run every hour by the Vera instance.
 *
 */

function fnUpdateAccumulation() {

    /* Test URL: http://api.wunderground.com/api/2cf4939aff4f59c2/geolookup/conditions/q/NH/Goffstown.json */
    /* Test URL: http://api.wunderground.com/api/2cf4939aff4f59c2/geolookup/hourly/q/NH/Goffstown.json */

    global $weather;
    global $dbLink;

    $numDoY = date('z');
    $numYear = date('Y');
    $precip = $weather->{'precip_today_metric'};

    /* Sanity check by validating that the precipitation metric returned is not negative */
    if ($precip >= 0)
    {
        toWeb("Precipitation so far today: " . $precip . " mm <br/>");
        toWeb("Conditions right now: " . $weather->{'weather'} . "<br/>");

        /* Check whether there is forecast data stored in the database already */
        $SQL = "SELECT numHighCelsius, blFreezingRain, numForecastPrecip, numActualPrecip FROM vw_precipitation WHERE ((numYear = :cYear) AND (numDoY = :DoY));";

        try {

            $dbh = $dbLink->prepare($SQL);
            $dbh->execute(array(':cYear' => $numYear, ':DoY' => $numDoY));
            $SQL = ""; // Reset the SQL statement text

            if ($dbh->rowCount() != 0) {
                /* We've got data to update. */
                $SQL = "UPDATE vw_precipitation SET ";
                $SQL .= "numActualPrecip = :numActualPrecip";
                $SQL .= ", ts_updated = CURRENT_TIMESTAMP";

                $row = $dbh->fetch(PDO::FETCH_ASSOC);

                /* Check whether or not the Freezing Rain flag is or should be set */

                if (preg_match('/freez/i', $weather->{'weather'})) {
                    $SQL .= ", blFreezingRain = 1";
                }

                $SQL .= " WHERE ((numYear = :cYear) AND (numDoY = :DoY));";

            } else {
                /* We need to insert new data */
                tlsDebug('DB query for day ' . $numDoY . ' returned no data!', E_USER_WARNING);

                if ( (preg_match('/snow/i', $weather->{'weather'}) || preg_match('/ice/i', $weather->{'weather'}) ||
                    preg_match('/freez/i', $weather->{'weather'})) && (! preg_match('/blowing/i', $weather->{'weather'})) ) {

                    $SQL = "INSERT INTO vw_precipitation (blFreezingRain, numActualPrecip ) VALUES (";

                    if (preg_match('/freez/i', $weather->{'weather'})) {
                        $SQL .= "1, ";
                    } else {
                        $SQL .= "0, ";
                    }

                    $SQL .= ":numActualPrecip) ";
                    $SQL .= "WHERE ((numYear = :cYear) AND (numDoY = :DoY));";
                }
            }

            if ($SQL != "") {

                $dbLink->beginTransaction();
                $dbh = $dbLink->prepare($SQL);
                $dbh->execute(array(':numActualPrecip' => $precip, ':cYear' => $numYear, ':DoY' => $numDoY));
                $dbLink->commit();
            } else {
                tlsDebug('Didn\'t create SQL for UPDATE or INSERT statement', E_USER_NOTICE);
                return false;
            }

        }
        catch (PDOException $e) {
            $dbLink->rollBack();
            tlsDebug('DB Error: ' . $e->getMessage(), E_USER_ERROR);
            return false;
        }
    } else {
        tlsDebug('The returned precipitation value is negative, not saving new data', E_USER_WARNING);
        return false;
    }

    return true;

}

function toWeb($strMessage) {
    global $TOWEB;

    if ($TOWEB) {
        echo $strMessage;
    }
}
function tlsDebug($strMessage, $severity = E_USER_NOTICE) {
    global $DEBUG;
    global $THRESHOLD;

    if (($DEBUG) && ($severity <= $THRESHOLD)) {
        trigger_error($strMessage, $severity);
    }
}

?>
