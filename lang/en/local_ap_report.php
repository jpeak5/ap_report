<?php

//common
$string['mod_name']                 = 'AP Report';
$string['pluginname']                 = 'AP Report';

$string['apr_daily_run_time']       = 'Daily Run Time'; 
$string['apr_daily_run_time_dcr']   = 'When should cron trigger this job? The enrollment report never automatically queries activity occurring after the first second of today.'; 
$string['apr_with_cron']            = "Enable Cron";
$string['apr_with_cron_desc']       = "Should these jobs run with cron?";
$string['apr_cron_url']             = 'Invoke cron...';
$string['apr_cron_desc']            = ':  click to run the cron jobs provided by this plugin. Note that cron must be enabled on this page and the current hour must match that selected in the selector';
$string['apreport_dir']             = 'Reports directory';
$string['apreport_dir_desc']        = 'specify the directory, under the Moodle dataroot, where the reports should be saved. Default is {$a->apreport_dir_default}';

//----------- common Errors
$string['apr_file_not_writable']= 'Failure saving activity statistics. Ensure that the file system is writable at {$a->mdl_dataroot}';
$string['apr_unknownmode']          = "Unknown value given for process mode";
$string['apr_nopermission']         = "You don't have permission to access this page";

//lmsEnrollment
$string['lmsEn_comp_stat_hdr']  = 'Completion Status';
$string['lmsEn_hdr']            = 'lmsEnrollment';
$string['file_location']        = 'file is located at {$a}';
$string['view_range_summary']   = 'Activity report for {$a->start} through {$a->end}';

//----------- lmsEn Status
$string['lmsEn_success']        = 'Last Run began at {$a->lmsEn_start} and completed at {$a->lmsEn_stop}. {$a->lmsEn_instr}';
$string['lmsEn_job_unended']    = 'FAILURE! Last job began at {$a->lmsEn_start} and has not recorded a completion timestamp. {$a->lmsEn_instr}';
$string['never_run']            = 'There is no evidence that this proces has ever run...- [Run now]({$a->reprocess})';
$string['first_run']            = 'This appears to be the first run of the system as there is no old completion time set. ';
$string['no_start_set']         = 'ERROR: job completion time is set as {$a->lmsEn_stop}, but no start time exists in the db.';
$string['lmsEn_no_activity']    = 'Failure getting enrollment data for the time range {$a->start} - {$a->end}: Check to be sure that log data reflects user activity for the requested timeframe. ';


//----------- lmsEn URLs
$string['lmsEn_view_current_url']= 'Current Report';
$string['lmsEn_view_latest_url']= 'Preview Report';
$string['lmsEn_reprocess_url']  = 'Reprocess';
$string['lmsEn_preview_url']    = 'Preview';
$string['lmsEn_xml_url']        = 'XML';
$string['lmsEn_backfill_url']   = 'Backfill';

$string['lmsEn_view_current_desc']= ':  builds a report as they would be built for the cron process.';
$string['lmsEn_view_latest_desc']= ':  same as the Current Report, but includes results up until the current moment.';
$string['lmsEn_reprocess_desc'] = ':  Re-run the process for yesterday\'s activity.';
$string['lmsEn_preview_desc']   = ':  Run the process for all activity up until the current moment.';
$string['lmsEn_xml_desc']       = ':  Location of enrollment report xml';
$string['lmsEn_backfill_desc']  = ':  If you have just installed this plugin, you may also want to refresh the group membership report data.';


//lmsGroupMembership
$string['lmsGM_hdr']            = 'lmsGroupMembership';
$string['lmsGM_hdr_desc']       = 'Run [Group Membership]({$a->group_membership}) job.';

//lmsSectionGroup
$string['lmsSecGrp_hdr']        = 'lmsSectionGroups';
$string['section_groups_header_desc']
                                = 'View [Section Groups Report]({$a->section_groups})';
$string['lmsSecGrp_coach_sel']  = 'roles that should be considered when querying for groups COACH members';
$string['lmsSecGrp_coach_roles']= 'Coach Roles';
$string['lmsSecGrp_pi_roles']   = 'Primary Instructor Roles';
$string['lmsSecGrp_pi_role_dsc']= 'roles that should be considered when querying for groups primary instructor members';



//lmsCoursework
$string['lmsCwk_hdr']           = 'lmsCoursework Report';
$string['lmsCwk_hdr_desc']      = 'The lmsCoursework report aggregates information about assignments, their due dates and grades on a per-user basis. Click to [reprocess]({$a->cwk}).{$a->cwk_status_sub}{$a->cwk_status}';
$string['lmsCwk_fq_prefix']     = 'apreport_lmsCoursework_';
$string['lmsCwk_subrept_thead'] = 'Sub-report';
$string['lmsCwk_status_thead']  = '&lt;stage&gt;: &lt;status&gt; [: &lt;info&gt;]';

?>
