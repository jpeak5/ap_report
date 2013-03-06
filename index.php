<?php

require_once 'lib.php';
global $USER;

require_login();
if(is_siteadmin($USER)){
    
    $limit = 100;
    $report = new currentEnrollment();
    
    echo html_writer::tag('h2', 'Current Enrollment');
    echo html_writer::tag('p', sprintf("Showing first %d records", $limit));
    echo html_writer::tag('textarea', $report->getReport('XML', 20),array('cols'=>80, 'rows'=>120));
}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('nopermission', lsu_online_reports, '/');
}

?>
