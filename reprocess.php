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
$mode = optional_param('mode', null, PARAM_TEXT);


if(isset($mode)){
    $PAGE->set_url('/local/ap_report/reprocess.php', array('mode'=>$mode));
}else{
    $PAGE->set_url('/local/ap_report/reprocess.php');
}
$PAGE->set_course($SITE);

//$PAGE->set_pagetype('mymedia-index');
$PAGE->set_pagelayout('admin');
$PAGE->set_title($header);
$PAGE->set_heading($header);

echo $OUTPUT->header();

$reprocess = !isset($mode);
$preview   = (isset($mode) and ($mode == 'preview'));
$backfill  = isset($mode) and $mode == 'backfill';



if(is_siteadmin($USER)){

    $report = new lmsEnrollment();
//    mtrace(sprintf("mode = %s, reprocess = %s, Preview =, backfill = ",$mode,(int)$reprocess,(int)$preview,(int)$backfill));
    
    
    //get records
    if($reprocess or $mode == 'preview'){
        
        $xml = !isset($mode ) ? $report->run() : $report->preview_today();
        
        $a = new stdClass();
        $a->start = strftime('%F %T',$report->start);
        $a->end   = strftime('%F %T',$report->end);
        
        
        echo html_writer::tag('h2', 'Current Enrollment');
        
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
            $file_loc = isset($mode) ? $CFG->dataroot.'/preview.xml' : $CFG->dataroot.'/'.$CFG->apreport_enrol_xml.'.xml';
            echo html_writer::tag(
                    'p', 
                    get_string('file_location', 'local_ap_report', $file_loc));

            $records = $xml->getElementsByTagName('lmsEnrollment');


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
        
    }elseif($mode == 'group_membership'){
        $gm = new lmsGroupMembership();
        if(($xdoc = $gm->run())!=false){
            echo $xdoc->saveXML();
        }else{
            echo "failed updating groupmembership report";
        }
//        $semesters = $report->get_active_ues_semesters();
//        $earliest = time();
//        foreach($semesters as $semester){
//            $earliest = $semester->ues_semester->classes_start < $earliest ? $semester->ues_semester->classes_start : $earliest;
//            
//        }
//        
//        $marker = time();
//        while($marker >= $earliest){
//            $daily = new lmsEnrollment();
//            list($start, $end) = apreport_util::get_yesterday(strftime('%F',$marker));
//            $status = $daily->run_arbitrary_day($start,$end);
//            $marker = $daily->start;
//
//            $data[] = array( 
//                'day'=>strftime('%F', $marker),
//                'status'=>$status);
//        }
//        
//    
//    
//        $table = new html_table();
//        $table_data = array();
//        foreach($data as $datum){
//            $cells = array();
//            $cells[] = new html_table_cell($datum['day']);
//            $cells[] = new html_table_cell($datum['status']);
//            $row = new html_table_row($cells);
//            $table_data[] = $row;
//        }
//        $table->head = array('day', 'status');
//        $table->data = $table_data;
//        echo html_writer::table($table);
        
    }
    


    
    
    
    
    

}elseif($mode == 'section_group'){
    $sg = new lmsSectionGroup();
        if(($xdoc = $sg->run())!=false){
            echo $xdoc->saveXML();
        }else{
            echo "failed updating groupmembership report";
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
