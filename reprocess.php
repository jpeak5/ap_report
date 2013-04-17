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

if($mode=='cron'){
        if(local_ap_report_cron()){
            redirect(new moodle_url('/admin/settings.php', array('section'=>'local_ap_report')));
        }
}

echo $OUTPUT->header();

//----------------------------------------------------------------------------//
//----------------------------------------------------------------------------//
//------------------------ BEGIN VIEW BRANCHES -------------------------------//
//----------------------------------------------------------------------------//
//----------------------------------------------------------------------------//


if(is_siteadmin($USER)){

    
    //get records
    if($mode == 'reprocess' or $mode == 'preview'){
        mtrace('running reprocess or preview');
        $report = new lmsEnrollment();    
        $xml = $mode == 'reprocess' ? $report->run() : $report->preview_today();
        
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
            echo html_writer::tag('p', 
                    get_string('file_location', 'local_ap_report', $file_loc));

            $records = $xml->getElementsByTagName('lmsEnrollment');

            $fields = array(
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
            echo render_table($xml, $records, $fields);
            
        }
        
    }elseif($mode == 'group_membership'){
        $gm = new lmsGroupMembership();
        if(($xdoc = $gm->run())!=false){
            echo render_table($xdoc, $xdoc->getElementsByTagName('lmsGroupMember'), lmsGroupMembershipRecord::$camels);
        }else{
            echo "failed updating groupmembership report";
        }

    }elseif($mode == 'section_groups'){
        $sg = new lmsSectionGroup();
        if(($xdoc = $sg->run())!=false){
            echo render_table($xdoc,
                    $xdoc->getElementsByTagName('lmsSectionGroup'), 
                    lmsSectionGroupRecord::$camels);
        }else{
            echo "failed updating section groups report";
        }
    
    }elseif($mode == 'coursework'){
        $cw = new lmsCoursework();
        if(($xdoc = $cw->run())!=false){
            echo render_table($xdoc,
                    $xdoc->getElementsByTagName('lmsCourseworkItem'), 
                    lmsCourseworkRecord::$camels);
        }else{
            echo "failed updating LMS Coursework report";
        }
    
    }else{
        print_error('unknownmode', 'local_ap_report', '/');
    }

}else{
    /**
     * @TODO fix the link to point at site root
     * @TODO define a lang file
     */
    print_error('apr_nopermission', 'local_ap_report', '/');
}

echo $OUTPUT->footer();

//----------------------------------------------------------------------------//
//----------------------------------------------------------------------------//
//----------------------------------------------------------------------------//
//--------------------------- HELPERS   --------------------------------------//
//----------------------------------------------------------------------------//
//----------------------------------------------------------------------------//
//----------------------------------------------------------------------------//

function render_table($xml,$element_list,$fields){
    $table = new html_table();
        $display = "";
        $table->head = $fields;
        $data = array();
        $xpath = new DOMXPath($xml);

        $display .= "returning only the first 100 table rows";
        for ($i=0; $i<100; $i++){
            $record = $element_list->item($i);
            $cells = array();
            foreach($table->head as $field){
                $cells[] = new html_table_cell($xpath->evaluate("string({$field})", $record));
            }
            $row = new html_table_row($cells);
            $data[] = $row;
        }

        $table->data = $data;
        $display .= html_writer::table($table);

        $display .= html_writer::tag('h4', 'Raw XML:');
        $row_count = 40;
        $xml->formatOutput = true;
        $display .= html_writer::tag('textarea', $xml->saveXML(),array('cols'=>45, 'rows'=>$row_count));
        return $display;
}
?>
