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
//    $report->survey_enrollment();
    $xml = $report->run();
    if(!$xml){
        
        echo sprintf("no results for the time range beginning %s and ending %s", 
                strftime('%F %T',$report->start),
                strftime('%F %T',$report->end));
    }else{
        echo html_writer::tag('textarea', $xml->saveXML(),array('cols'=>80, 'rows'=>120));
    }

}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('nopermission', lsu_online_reports, '/');
}



?>
