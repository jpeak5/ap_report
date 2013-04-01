<?php

require_once 'lib.php';
global $CFG, $USER, $PAGE, $OUTPUT;

require_login();

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir.'/cronlib.php');
    
$header = "online reports";
$context = get_system_context();
$PAGE->set_context($context);
$header  = format_string($SITE->shortname).": {$header}";
$preview = optional_param('mode', null, PARAM_TEXT);


if(isset($preview)){
    $PAGE->set_url('/local/ap_report/reprocess.php', array('mode'=>$preview));
}else{
    $PAGE->set_url('/local/ap_report/reprocess.php');
}
$PAGE->set_course($SITE);

//$PAGE->set_pagetype('mymedia-index');
$PAGE->set_pagelayout('admin');
$PAGE->set_title($header);
$PAGE->set_heading($header);

echo $OUTPUT->header();


if(is_siteadmin($USER)){

    $report = new lmsEnrollment();
    
    echo html_writer::tag('h2', 'Current Enrollment');
    
    //get records
    $xml = isset($preview) ? $report->preview_today() : $report->run();

    $a = new stdClass();
    $a->start = strftime('%F %T',$report->start);
    $a->end   = strftime('%F %T',$report->end);
    
    
    
    if(!$xml){
        echo html_writer::tag(
                'p',
                get_string(
                        'no_activity__summary',
                        'local_ap_report', 
                        $a
                        )
                );
    }else{
        assert(get_class($xml) == 'DOMDocument');
        echo html_writer::tag(
                'p', 
                get_string(
                        'view_range_summary',
                        'local_ap_report', 
                        $a
                        )
                );
        $file_loc = isset($preview) ? $CFG->dataroot.'/preview.xml' : $CFG->dataroot.'/'.$CFG->apreport_enrol_xml.'.xml';
        echo html_writer::tag(
                'p', 
                get_string('file_location', 'local_ap_report', $file_loc));
        
        $records = $xml->getElementsByTagName('lmsEnrollment');
        $count = $records->length;
        
        $table = new html_table();
        $table->head = array(
            'enrollmentId',
            'studentId', 
            'courseId',
            'sectionId',
            'startDate',
            'endDate',
            'status',
            'lastCourseAccess',
            'timeSpentInClass',
            'extensions',
            );
        $data = array();
        $xpath = new DOMXPath($xml);
        foreach($records as $record){
            $cells = array();
            foreach($table->head as $field){
                $cells[] = new html_table_cell($xpath->evaluate("string({$field})", $record));
            }
            $row = new html_table_row($cells);
            $data[] = $row;
        }
        
        $table->data = $data;
        echo html_writer::table($table);
        
        
        echo html_writer::tag('h4', 'Raw XML:');
        $row_count = 40;
        $xml->formatOutput = true;
        echo html_writer::tag('textarea', $xml->saveXML(),array('cols'=>45, 'rows'=>$row_count));
    }

}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('nopermission', local_ap_report, '/');
}

echo $OUTPUT->footer();

?>
