<?php

defined('MOODLE_INTERNAL') or die();

$plugin = 'local_lsuonlinereport';

$_s = function($key,$a=null) use ($plugin){
    return get_string($key, $plugin, $a=null);
};


//echo $_s('pluginname_desc');
//die();

//global $ADMIN;
if ($ADMIN->fulltree) {

    $settings = new admin_settingpage('local_lsuonlinereport_settings_page', 'LSUOnline Reports');
    
    $a = new stdClass();
    $repro_url = new moodle_url('/local/lsuonlinereport/reprocess.php');
    $a->url = $repro_url->out(false);
    
    
//    $reproc = html_writer::tag('a', 'Reprocess', array('href'=>$a->url));
    
    $settings->add(new admin_setting_heading('local_lsuonlinereport_settings', 'Online Report Settings',
        get_string('pluginname_desc',$plugin, $a->url)));

    $settings->add(new admin_setting_configcheckbox('local_lsuonlinereport_with_cron',
        $_s('with_cron'), $_s('with_cron_desc'), 0));
    
    $settings->add(new admin_setting_configtime('local_lsuonlinereport_starttime_h'
            , 'local_lsuonlinereport_starttime_m'
            , 'local_lsuonlinereport_starttime', $_s('starttime')
            , $_s('starttime_desc'), 0));
    $settings->add(new admin_setting_configtime('local_lsuonlinereport_endtime_h'
            , 'local_lsuonlinereport_endtime_m'
            , 'local_lsuonlinereport_endtime', $_s('endtime')
            , $_s('endtime_desc'), 0));
    
    
    
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
