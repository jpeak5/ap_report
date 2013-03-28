<?php
$string['apreports_settings']   = 'AP Reports Settings';
$string['pluginname_desc']      = 'LSU Online Reports Settings...- [Reprocess]({$a})';

$string['daily_run_time']       = 'Daily Run Time'; 
$string['daily_run_time_dcr']   = 'When should cron trigger this job? The enrollment report never automatically queries activity occurring after the first second of today.'; 

$string['range_start']          = 'Report Range Start';
$string['range_start_dcr']      = 'Sets the starting time of an arbitrary time window for the purpose of running one-off reports. This setting does NOT affect the temporal parameters of daily cron runs of this job.';

$string['range_end']            = 'Report Range End';
$string['range_end_dcr']        = 'Sets the ending time of an arbitrary time window for the purpose of running one-off reports. This setting does NOT affect the temporal parameters of daily cron runs of this job.';

$string['starttime']            = "Start time";
$string['starttime_desc']       = "time to mark the beginning of the cron window";
$string['endtime']              = "End Time";
$string['endtime_desc']         = "Time to mark the end of the cron window";

$string['last_run_start']       = "Last run started";
$string['last_run_end']         = "Last Run Completed";


$string['storage_path']         = "Path to store files";
$string['storage_path_desc']    = "Path should exist, should be writable by the webserver. This plugin will not create the directory.";
$string['with_cron']            = "Run with cron?";
$string['with_cron_desc']       = "If checke, reports data will be collected during cron. If left unchecked, values for time window start and end will be ignored";
?>
