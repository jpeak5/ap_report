<?php

require_once 'lib.php';
global $USER, $PAGE, $OUTPUT;

require_login();

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');


$header = "online reports";
$PAGE->set_context(get_system_context());
$header  = format_string($SITE->shortname).": {$header}";

$PAGE->set_url('/local/lsuonlinereports/view.php');
$PAGE->set_course($SITE);

//$PAGE->set_pagetype('mymedia-index');
$PAGE->set_pagelayout('admin');
$PAGE->set_title($header);
$PAGE->set_heading($header);

echo $OUTPUT->header();

if(is_siteadmin($USER)){
    
    $limit = 100;
    $report = new lmsEnrollment();
    
    echo html_writer::tag('h2', 'Current Enrollment');
    echo html_writer::tag('p', sprintf("Showing first %d records", $limit));
//    echo html_writer::tag('textarea', $report->getReport('XML', 20),array('cols'=>80, 'rows'=>120));
}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('nopermission', lsu_online_reports, '/');
}


echo "start calculating time spent...<br/>";
$report->calculateTimeSpent();

echo sprintf("testing the get fieldset routine:<br/>");
$semesterids = $report->get_active_ues_semester_ids();
$sections = $report->get_active_section_ids($semesterids);
$id = 501; //pomarico's course
$students = $report->get_studentids_per_section($id);
$courseid = $report->get_moodle_course_id($id);
echo sprintf("moodle course id is %d", $courseid);
foreach($students as $s){
   $time = $report->get_time_spent_today_section($s, $courseid);
   
//   echo sprintf("user with id %d spent %d seconds in the course<br/>", $s, $time);
}


echo $OUTPUT->footer();
?>
