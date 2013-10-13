local runTime = getVCvar(devID_Logic_DeIceConfig, 3)
logMsg("De-Ice: Run for: " .. runTime .. " hours", global_enableDebugging)
local runAutomated = string.upper(getVCvar(devID_Logic_DeIceConfig, 4))
local minTempThreshold = tonumber(getVCvar(devID_Logic_DeIceConfig, 5) or 0)

-- Set to true by default (assuming automated management of the de-icing cables)
local blAutoRun = true

logMsg("De-Ice: Automated mode? " .. runAutomated, global_enableDebugging)
local dayOnly = string.upper(getVCvar(devID_Logic_DeIceConfig, 1))
local ranAt = tonumber(getVCvar(devID_Logic_DeIceConfig, 2) or 1000)
-- local endTime = getVCvar(devID_Logic_DeIceConfig, 2)
--
local rStatus

local deIceFront = switchStatus(devID_FrontDeIcingSwitch)
local deIceFrontTop = switchStatus(devID_FrontTopDeIce)
local deIceRear = switchStatus(devID_RearDeIcingSwitch)

local isDay = tonumber(luup.variable_get("urn:rts-services-com:serviceId:DayTime", "Status", devID_DayOrNight) or 0)
local gTemp = luup.variable_get("urn:upnp-org:serviceId:TemperatureSensor1","CurrentTemperature", devID_FrontTempSensor)
local fTemp = luup.variable_get("urn:upnp-org:serviceId:TemperatureSensor1","CurrentTemperature", devID_WeatherCurTemp)
local dTemp = luup.variable_get("urn:upnp-org:serviceId:TemperatureSensor1","CurrentTemperature", devID_FrontDoorTemp)

if ((global_VeraRebooted) and (ranAt ~= 0))
then
    logMsg("De-Ice: Initial check after a Luup/Lua restart", global_enableDebugging)
    logMsg("De-Ice: Checking state of de-icing cables", global_enableDebugging)
    if ((deIceFront == "1") or (deIceFrontTop == "1"))
    then
        logMsg("De-Ice: Front cables are enabled after a reboot, check timestamp for when they were started", global_enableDebugging)
        if ( (ranAt + 1800) > os.time())
        then
            logMsg("De-Ice: Has not been running for 30 minutes yet. Pretend like it never started", global_enableDebugging)
            runScene(sceneID_FrontHouseDeIcing)
        else
            logMsg("De-Ice: Has been running for >30 minutes. Stop front de-icing & run the cables at the back of the house", global_enableDebugging)
            setPowerSwitch(devID_FrontDeIcingSwitch, "off")
            setPowerSwitch(devID_FrontTopDeIce, "off")
            runScene(sceneID_RearHouseDeIcing)
        end
        -- If there front cables have been on for less than 30 minutes, stop the cables.
    elseif (deIceRear == "1")
    then
        logMsg("De-Ice: Rear cables are enabled after restart. Check timestamp for when they were started", global_enableDebugging)
        if ((ranAt + 1800) > os.time())
        then
             logMsg("De-Ice: Rear de-icing cables have not been running for 30 minutes yet, pretend like they never started", global_enableDebugging)
             runScene(sceneID_RearHouseDeIcing)
             return true
        else
             logMsg("De-Ice: Disable the rear de-icing cables", global_enableDebugging)
             setPowerSwitch(devID_RearDeIcingSwitch, "off")
             setVCvar(devID_Logic_DeIceConfig, 2, 0)
             return false
        end
    end
else
   logMsg("De-Ice: Not after a reboot, or cables finished. Continue...", global_enableDebugging)
end

if (runAutomated == "NO")
then
    logMsg("De-Ice: Automated management is disabled", global_enableDebugging)
    blAutoRun = false
end

local vHour = (60 * 60)
-- In Seconds: runTime + 2 minutes
local delayTime = ((vHour * tonumber(runTime)) + 120)
local real_avgTemp = ((tonumber(gTemp) + tonumber(fTemp) + tonumber(dTemp)) / 3)

rStatus = checkWS("weather")

if ((rStatus == 1) and (blAutoRun))
then
   logMsg("De-Ice: Successfully connected with Server", global_enableDebugging)
   logMsg("De-Ice: Weather forecast updated and deIcing recommendations recorded", global_enableDebugging)
end

if (real_avgTemp <= minTempThreshold)
then
   logMsg("De-Ice: Too cold to enable de-icing cables: " .. real_avgTemp, global_enableDebugging)
   return false
end

if ( onVacation() ~= false )
then
    logMsg("De-Ice: Nobody home! Unsafe to enable de-icing cables!", global_enableDebugging)
    -- Send push notification that there is enough snow to warrant running the cables!
    luup.call_action("urn:upnp-org:serviceID:SmtpNotification1", "SendEmail", { Recipient_Name="Sjolshagen Family", Recipient_eMail="family@sjolshagen.net", Subject="Warning: De-Icing cables should run but won't", Message="There is enough snow or ice on the roof to warrant running the de-icing cables. However, since nobody is home - the house is in Vacation Mode - the cables will NOT be enabled. You may want to think about getting somebody to rake the roof!"}, devID_SendMailDev)
    return false
end

if ((dayOnly == "YES") and (isDay == 0))
then
     logMsg("De-Ice: It's dark. Running doesn't make sense so overriding the recommendation to run (before it is made)!", global_enableDebugging)
    return false
end

if (blAutoRun)
then

   rStatus = checkWS("check")
   logMsg("De-Ice: Server forecast check return status: " .. rStatus, global_enableDebugging)

   if (rStatus == 1)
   then
       logMsg("De-Ice: Web server recommends the cables be enabled", global_enableDebugging)
       logMsg("De-Ice: Temp, Time and Vacation status favorable to enable the de-icing cables", global_enableDebugging)
       local deicestatus = luup.call_delay("runScene", delayTime, sceneID_RearHouseDeIcing)
       logMsg("De-ice: Queued the de-icing process for the back of the house: " .. deicestatus, global_enableDebugging)
       logMsg("De-Ice: Starting the de-icing process for the front of the house", global_enableDebugging)
       runScene(sceneID_FrontHouseDeIcing)
       -- Set timestamp for when DeIcing was started.
       setVCvar(devID_Logic_DeIceConfig, 2, os.time())
       return true
   elseif (rStatus == 2)
   then
        logMsg("De-Ice: Server reports the cables are already on", global_enableDebugging)

        if ((deIceFront == "1") or (deIceFrontTop == "1") or (deIceRear == "1"))
        then
             logMsg("De-Ice: Database is correct. Nothing more to do", global_enableDebugging)
             return false
        else
             logMsg("De-Ice: Incorrect database entry. Clearing it", global_enableDebugging)
             if (checkWS("clear") == 0)
             then
                  logMsg("De-Ice: Cleared incorrect DB flag. Stand by for next scheduled review", global_enableDebugging)
                  return false
             end
        end -- End of "Test of Devices"
   else
       logMsg("De-Ice: Weather logic does not recommend running the de-icing cables", global_enableDebugging)
       return false
   end
else
   logMsg("De-Ice: Server returned: " .. rStatus, global_enableDebugging)

   if (not blAutoRun)
   then
        local itSnowed = luup.variable_get("urn:upnp-org:serviceId:VSwitch1","Status", devID_HasSnowed)

        if (itSnowed == "1")
        then
           local deicestatus = luup.call_delay("runScene", delayTime, sceneID_RearHouseDeIcing)
           logMsg("De-Ice: Queued the de-icing process for the back of the house:" .. deicestatus, global_enableDebugging)
           logMsg("De-Ice: Starting the de-icing process for the front of the house", global_enableDebugging)
           runScene(sceneID_FrontHouseDeIcing)
           return true
        end
    end
    return false
end