<?php

require_once 'lib.php';
global $USER, $PAGE, $OUTPUT;

require_login();

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');


$header = "online reports";
$context = get_system_context();
$PAGE->set_context($context);
$header  = format_string($SITE->shortname).": {$header}";

$PAGE->set_url('/local/lsuonlinereports/view.php');
$PAGE->set_course($SITE);

//$PAGE->set_pagetype('mymedia-index');
$PAGE->set_pagelayout('admin');
$PAGE->set_title($header);
$PAGE->set_heading($header);

echo $OUTPUT->header();

if(is_siteadmin($USER)){
    
    $report_since = strtotime('-5 days');
    $report = new lmsEnrollment();
    if($report->activity_check()){
        
        $data = $report->survey_enrollment();
        
        echo html_writer::tag('h2', 'Current Enrollment');
    //    echo html_writer::tag('a', );
        echo html_writer::tag('textarea', $data,array('cols'=>80, 'rows'=>120));
        
    }else{
        mtrace(sprintf("no new activity to report"));
    }
}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('nopermission', lsu_online_reports, '/');
}



echo $OUTPUT->footer();
?>
