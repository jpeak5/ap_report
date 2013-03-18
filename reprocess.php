<?php

require_once 'lib.php';
global $USER, $PAGE, $OUTPUT;

require_login();

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');




if(is_siteadmin($USER)){
    
    $limit = 100;
    $report = new lmsEnrollment();
    
    echo html_writer::tag('h2', 'Current Enrollment');
//    echo html_writer::tag('a', );
    $report->survey_enrollment();
    echo html_writer::tag('textarea', $report->get_enrollment_xml()->saveXML(),array('cols'=>80, 'rows'=>120));
    if($report->create_file($report->get_enrollment_xml())){
        redirect('/admin/settings.php?section=local_lsuonlinereport_settings_page', 'File saved successfuly');
    }
}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('nopermission', lsu_online_reports, '/');
}



?>
