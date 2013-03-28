<?php

defined('MOODLE_INTERNAL') or die();
global $CFG;
$plugin = 'local_ap_report';

$_s = function($key,$a=null) use ($plugin){
    return get_string($key, $plugin, $a=null);
};


//echo $_s('pluginname_desc');
//die();

//global $ADMIN;
if ($hassiteconfig) {

    $settings = new admin_settingpage('local_ap_report', 'AP Reports');
    
    
    $a = new stdClass();
    $repro_url = new moodle_url('/local/ap_report/reprocess.php');
    $a->url = $repro_url->out(false);
    
    
//    $reproc = html_writer::tag('a', 'Reprocess', array('href'=>$a->url));
    
    $settings->add(
            new admin_setting_heading(
                    'apreports_settings',
                    'Enrollment Report Settings',
                    get_string('pluginname_desc',$plugin, $a->url)
                    ));

//    $settings->add(
//            new admin_setting_configcheckbox(
//                    'local_apreport_with_cron',
//                    $_s('with_cron'),
//                    $_s('with_cron_desc'),
//                    0
//                    ));
    
    
    
    if(!isset($CFG->apreport_job_complete) or !isset($CFG->apreport_job_start)){
        $compl_status = sprintf("This report has never run, or failed on first run"
                );
    }elseif($CFG->apreport_job_complete > $CFG->apreport_job_start){
        $compl  = $CFG->apreport_job_complete;
        $job_st = $CFG->apreport_job_start;
        
        $compl_status = sprintf("Last Run began at %s and completed at %s",
                strftime('%F %T', $compl), 
                strftime('%F %T', $job_st)
                );
    }else{
        $compl_status = sprintf("FAILURE! Last job began at %s and has not recorded a completion timestamp",
                strftime('%F %T', $job_st)
                );
    }
    $settings->add(
            new admin_setting_heading(
                    'apreport_last_completion', 
                    'Completion Status', $compl_status
                    ));
    
    $settings->add(
            new admin_setting_configtime(
                    'apreport_daily_run_time_h',
                    'apreport_daily_run_time_m',
                    $_s('daily_run_time'),
                    $_s('daily_run_time_dcr'),
                    array('h'=>0, 'm'=>5)
            ));
    
//    $settings->add(
//            new admin_setting_configtime(
//                    'local_apreport_range_start_h',
//                    'local_apreport_range_start_m',
//                    $_s('range_start'),
//                    $_s('range_start_dcr'),
//                    array('h'=>0, 'm'=>5)
//                    ));
//    $settings->add(
//            new admin_setting_configtime(
//                    'local_apreport_range_end_h',
//                    'local_apreport_range_end_m',
//                    $_s('range_end'),
//                    $_s('range_end_dcr'),
//                    array('h'=>0, 'm'=>5)
//            ));
    
    $settings->add(
            new admin_setting_configtext(
                    'local_apreport_range_start',
                    $_s('range_start'),
                    $_s('range_start_dcr'),
                    PARAM_INT
                    )
            );
    
    $settings->add(
            new admin_setting_configtext(
                    'local_apreport_range_end',
                    $_s('range_end'),
                    $_s('range_end_dcr'),
                    PARAM_INT
                    )
            );
    
    
    
    $settings->add(
            new admin_setting_configtext(
                    'apreport_enrol_xml', 
                    'Filename', 
                    'give a name for the xml file (the extension is not required here).',
                    'enrollment',
                    PARAM_FILE)
            );
    
    $ADMIN->add('localplugins', $settings);

    
    
}

//class admin_setting_configtext_hour extends admin_setting_configtext{
//    public function validate($data){
//        if(parent::validate($data)){
//            if($data <=23 and $data >=0){
//                return true;
//            }else{
//                return false;
//            }
//        }
//    }
//}

?>
