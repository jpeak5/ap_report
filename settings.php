<?php

defined('MOODLE_INTERNAL') or die();

global $CFG, $DB;
$plugin = 'local_ap_report';

$_s = function($key,$a=null) use ($plugin){
    return get_string($key, $plugin, $a);
};

$status = function ($comp){
    global $CFG;
    $status = 'apreport_'.$comp;
    if(!isset($CFG->$status)) return;
    $msg_core = html_writer::tag('em', html_writer::tag('strong', $CFG->$status));    
    $br = html_writer::empty_tag('br');
    return html_writer::tag('p','Copmpletion Status for last run:'.$br.$msg_core);
};

$hours = function(){
    $i=0;
    $hours = array();
    while($i<24){
        $hours[] = $i;
        $i++;
    }
    return $hours;
};

if ($hassiteconfig) {
    require_once dirname(__FILE__) . '/lib.php';
    
    $settings = new admin_settingpage('local_ap_report', $_s('mod_name'));
    $a = new stdClass();
    
//----------------------------- GLOBAL SETTINGS ------------------------------//        
    
    /**
     * A links section; 
     * add a term to the array to have that url avail in the $a string var
     */
    $alinks = array('reprocess', 'cron', 'preview','backfill', 'view_current', 'view_latest');
    foreach($alinks as $alink){
        $a->$alink = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>$alink));;
    }


    $cron_desc = $_s(
        'apr_with_cron_desc')
        .html_writer::tag('br','')
        .html_writer::link($a->cron, $_s('apr_cron_url'))
        .$_s('apr_cron_desc');

    $settings->add(
       new admin_setting_configcheckbox(
           'apreport_with_cron',
           $_s('apr_with_cron'),
           $cron_desc,
       0
    ));

    $settings->add(
        new admin_setting_configselect(
            'apreport_daily_run_time_h',
            $_s('apr_daily_run_time'),
            $_s('apr_daily_run_time_dcr'),
            1,
            $hours()
    ));

    $a->apreport_dir_default = $CFG->dataroot.DIRECTORY_SEPARATOR.'apreport';

    //list text
    $lmsEn_options = '';
    $lmsEn_linksList = html_writer::alist(array(
        html_writer::link($a->view_current,$_s('lmsEn_view_current_url')) .$_s('lmsEn_view_current_desc'),
        html_writer::link($a->view_latest,$_s('lmsEn_view_latest_url')) .$_s('lmsEn_view_latest_desc'),
        html_writer::link($a->reprocess,$_s('lmsEn_reprocess_url')) .$_s('lmsEn_reprocess_desc'),
        html_writer::link($a->preview, $_s('lmsEn_preview_url'))    .$_s('lmsEn_preview_desc'),
        html_writer::link($a->backfill,$_s('lmsEn_backfill_url'))   .$_s('lmsEn_backfill_desc')
    ));
    $lmsEn_options .= $lmsEn_linksList;

    /**
     * lmsEN completion STATUS
     * Figure out what our current status is with respect to the last run
     */
    if(!isset($a->lmsEn_stop) and isset($a->lmsEn_start)){
        $status_msg = $_s('lmsEn_job_unended', $a);

    }elseif(!isset($a->lmsEn_stop) and !isset($a->lmsEn_start)){
        $status_msg = $_s('never_run',$a);

    }elseif(isset($a->lmsEn_stop) and !isset($a->lmsEn_start)){
        $status_msg = $_s['no_start_set'];

    }elseif($correct_order){
        $status_msg = $_s('lmsEn_success', $a);

    }else{
        $status_msg = $_s('lmsEn_job_unended', $a);
        $status_msg .= $_s('lmsEn_reprocess_url',$a);
    }

    $settings->add(
        new admin_setting_heading(
            'apreports_settings',
            $_s('lmsEn_hdr'),
            $lmsEn_options.$status('lmsEnrollment')
            ));    

    //-------------------------- lmsGroupMembership ------------------------------//

    $group_membership = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'group_membership'));
    $a->group_membership = $group_membership->out(false);

    $settings->add(
        new admin_setting_heading(
            'group_membership_header', 
            $_s('lmsGM_hdr'),  
            $_s('lmsGM_hdr_desc',$a).$status('lmsGroupMembership')
        )
    );

    //-------------------------- lmsSectionGroup ------------------------------//

    $section_groups = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'section_groups'));
    $a->section_groups = $section_groups->out(false);

    $settings->add(
        new admin_setting_heading(
            'section_groups_header', 
            $_s('lmsSecGrp_hdr'),  
            $_s('section_groups_header_desc', $a).$status('lmsSectionGroup')
        )
    );

    //config selects for primary inst/coach
    //@TODO double check the default values are being used

    $roles = role_get_names(null, null, true);

    $pi_defaults = array_keys($roles,'Teacher');
    if(!isset($CFG->apreport_primy_inst_roles)){
        $def = is_array($pi_defaults) ? implode(',',$pi_defaults) : $pi_defaults;
        set_config('apreport_primy_inst_roles', $def);
    }

    $settings->add(
        new admin_setting_configmultiselect(
            'apreport_primy_inst_roles',
            $_s('lmsSecGrp_pi_roles'), 
            $_s('lmsSecGrp_pi_role_dsc'),
            $pi_defaults, $roles)
    );

    //@TODO double check the default values are being used
    $coach_defaults = array_keys($roles,'Non-editing teacher');
    if(!isset($CFG->apreport_coach_roles)){
        $def = is_array($coach_defaults) ? implode(',',$coach_defaults) : $coach_defaults;
        set_config('apreport_coach_roles', $def);
    }

    //$coach_defaults = array(4,19,20,21);
    $settings->add(
        new admin_setting_configmultiselect(
            'apreport_coach_roles',
            $_s('lmsSecGrp_coach_roles'), 
            $_s('lmsSecGrp_coach_sel'),
            $coach_defaults, $roles)
    );


    //----------------------------- lmsCoursework --------------------------------//

    $tbl = new html_table();
    $tbl->head = array($_s('lmsCwk_subrept_thead'), $_s('lmsCwk_status_thead'));
    $data = array();

    foreach(lmsCoursework::$subreports as $sr){
        $cells = array();

        $r = $_s('lmsCwk_fq_prefix').$sr;

        $k = html_writer::tag('strong', strtoupper($sr));
        $cells[] = $k;
        $cells[] = isset($CFG->$r) ? $CFG->$r : '';
        $data[]  = new html_table_row($cells);
    }

    $tbl->data = $data;


    //lang strings
    $a->cwk_status_sub = html_writer::table($tbl);
    $cwk = new moodle_url('/local/ap_report/reprocess.php', array('mode'=>'coursework'));
    $a->cwk = $cwk->out(false);    
    $a->cwk_status = $status('lmsCoursework');

    $settings->add(
        new admin_setting_heading(
            'lmsCoursework_header', 
            $_s('lmsCwk_hdr'),  
            $_s('lmsCwk_hdr_desc',$a)
        )
    );

    $ADMIN->add('localplugins', $settings);
    
}
?>
