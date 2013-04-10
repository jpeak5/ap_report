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
    
    $repro_url = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'reprocess'));
    $a->url = $repro_url->out(false);
    
    $preview = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'preview'));
    $a->preview = $preview->out(false);
    
    $group_membership = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'group_membership'));
    $a->group_membership = $group_membership->out(false);
    
    $section_groups = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'section_groups'));
    $a->section_groups = $section_groups->out(false);
    

    
    $settings->add(
            new admin_setting_heading(
                    'apreports_settings',
                    'Enrollment Report Settings',
                    get_string('preview',$plugin, $a)
                    ));

    $stop  = $CFG->apreport_job_complete;
    $start = $CFG->apreport_job_start;
    
    
    $instr ='';
    if(!$CFG->apreport_got_enrollment){
        $instr .= "Failure getting enrollment data: Check to be sure that log data reflects user activity for the requested timeframe. ";
    }
    if(!$CFG->apreport_got_xml){
        $instr .= "Failure saving activity statistics. Ensure that the file system is writable at ".$CFG->dataroot.'/ ';
    }

    
    if(!isset($stop) and isset($start)){
        $compl_status = sprintf("FAILURE! Last job began at %s and has not recorded a completion timestamp %s;
            This appears to be the first run of the system as there is no old completion time set. %s",
                strftime('%F %T', $start, $instr),
                get_string('no_completion',$plugin, $a));
    }elseif(!isset($stop) and !isset($start)){
        $compl_status = get_string('never_run',$plugin, $a);
        
    }elseif(isset($stop) and !isset($start)){
        $compl_status = sprintf("ERROR: job completion time is set as %s, but no start time exists in the db.", $stop);
    }elseif($stop > $start){
        $stop  = $stop;
        $start = $start;
        $compl_status = sprintf("Last Run began at %s and completed at %s. %s",
                strftime('%F %T', $stop), 
                strftime('%F %T', $start),
                $instr);
    }else{
        $compl_status = sprintf("FAILURE! Last job began at %s and has not recorded a completion timestamp %s. %s",
                strftime('%F %T', $start),
                get_string('no_completion',$plugin, $a),
                $instr
                );
    }
    
    $settings->add(
            new admin_setting_heading(
                    'apreport_last_completion', 
                    'Completion Status', $compl_status
                    ));
    $hours = array();
    $i=0;
    while($i<24){
        $hours[] = $i;
        $i++;
    }
    $settings->add(
            new admin_setting_configselect(
                    'apreport_daily_run_time_h',
                    $_s('daily_run_time'),
                    $_s('daily_run_time_dcr'),
                    1,
                    $hours
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
    
//    $settings->add(
//            new admin_setting_configtext(
//                    'local_apreport_range_start',
//                    $_s('range_start'),
//                    $_s('range_start_dcr'),
//                    PARAM_INT
//                    )
//            );
//    
//    $settings->add(
//            new admin_setting_configtext(
//                    'local_apreport_range_end',
//                    $_s('range_end'),
//                    $_s('range_end_dcr'),
//                    PARAM_INT
//                    )
//            );
    
    
    
    $settings->add(
            new admin_setting_configtext(
                    'apreport_enrol_xml', 
                    'Filename', 
                    'give a name for the xml file (the extension is not required here).',
                    'enrollment',
                    PARAM_FILE)
            );

    
    $settings->add(
        new admin_setting_heading(
                'group_membership_header', 
                'Group Membership Report',  get_string('group_membership',$plugin, $a)
                ));
    
    
    $settings->add(
        new admin_setting_heading(
                'section_groups_header', 
                'Section Groups Report',  get_string('section_groups_header_desc',$plugin, $a)
                ));
    
    //config selects for primary inst/coach
    global $DB;
    //@TODO double check the default values are being used
    $pi_defaults = array('3');

    $roles = $DB->get_records_menu('role');
    $settings->add(
            new admin_setting_configmultiselect('apreport_primy_inst_roles','Primary Instructor Roles', 
                    'roles that should be considered when querying for groups primary instructor members'
                    , $pi_defaults, $roles)
            );
    
    //@TODO double check the default values are being used
    $coach_defaults = array(4,19,20,21);
    $settings->add(
            new admin_setting_configmultiselect('apreport_coach_roles','Coach Roles', 
                    'roles that should be considered when querying for groups COACH members'
                    , $coach_defaults, $roles)
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
