@echo off

echo Removing old tasks...
SCHTASKS /DELETE /TN "WebTLO\Control" /F
SCHTASKS /DELETE /TN "WebTLO\Update"  /F
SCHTASKS /DELETE /TN "WebTLO\Keepers" /F
SCHTASKS /DELETE /TN "WebTLO\Reports" /F

echo Adding tasks...
SCHTASKS /CREATE /SC HOURLY /TN "WebTLO\Control" /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\cron\control.php'" /ST 00:25
SCHTASKS /CREATE /SC HOURLY /TN "WebTLO\Update"  /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\cron\update.php'"  /ST 00:15
SCHTASKS /CREATE /SC DAILY  /TN "WebTLO\Keepers" /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\cron\keepers.php'" /ST 05:00
SCHTASKS /CREATE /SC DAILY  /TN "WebTLO\Reports" /TR "'%cd%\php\RunHiddenConsole.exe' '%cd%\php\php.exe' '%cd%\nginx\wtlo\cron\reports.php'" /ST 06:00

pause
