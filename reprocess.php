<?php

require_once 'lib.php';
global $CFG, $USER, $PAGE, $OUTPUT;

require_login();

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->libdir.'/cronlib.php');


$_s = function($key,$a=null) {
    return get_string($key, 'local_ap_report', $a);
};

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
    //@TODO rewrite this meth
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

    $lmsEnrollment_modes = array('preview', 'reprocess', 'view_latest', 'view_current');
    if(in_array($mode, $lmsEnrollment_modes)){
        $report = new lmsEnrollment($mode);
        $xml = $report->run();
        make_enrollment_report($xml, $report->report_start, $report->report_end, $report->filename);
    }
    elseif($mode == 'group_membership'){
        $gm = new lmsGroupMembership();
        if(($xdoc = $gm->run())!=false){
            echo render_table($xdoc, $xdoc->getElementsByTagName('lmsGroupMember'), lmsGroupMembershipRecord::$camels);
            echo render_xml($xdoc);
        }else{
            echo "failed updating groupmembership report";
        }

    }elseif($mode == 'section_groups'){
        $sg = new lmsSectionGroup();
        if(($xdoc = $sg->run())!=false){
            echo render_table($xdoc,
                    $xdoc->getElementsByTagName('lmsSectionGroup'), 
                    lmsSectionGroupRecord::$camels);
            echo render_xml($xdoc);
        }else{
            echo "failed updating section groups report";
        }
    
    }elseif($mode == 'coursework'){
        $cw = new lmsCoursework();
        if(($xdoc = $cw->run())!=false){
            echo render_table($xdoc,
                    $xdoc->getElementsByTagName('lmsCourseworkItem'), 
                    lmsCourseworkRecord::$camels);
            echo render_xml($xdoc);
        }else{
            echo "failed updating LMS Coursework report";
        }
    
    }elseif($mode == 'backfill'){
        
        if(lmsEnrollment::backfill()){
            echo "data filled successfully";
        }else{
            echo "fail";
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

function make_enrollment_report($xml, $start,$end,$filename){
    global $CFG;
            $a = new stdClass();
        $a->start = strftime('%F %T',$start);
        $a->end   = strftime('%F %T',$end);
        
        echo html_writer::tag('h2', 'Current Enrollment');
        
        if(!$xml){
            echo html_writer::tag(
                    'p',
                    $_s('lmsEn_no_activity',$a)
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
            $file_loc = isset($filename) ? $CFG->dataroot.'/'.$filename : 'ERROR- report is undefined';

            $xpath = new DOMXPath($xml);
            $records = $xpath->query('//lmsEnrollment[timeSpentInClass>0]');
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
            $message = "Returning records with timeSpent values greater than 0";
            echo render_table($xml, $records, $fields,$message);
        }
}

function render_xml($xml){
    $xml->formatOutput = true;
    return html_writer::tag('textarea', $xml->saveXML(),array('cols'=>45, 'rows'=>200));
}

function render_table($xml,$element_list,$fields, $message=''){
    $table = new html_table();
        $display = "";
        $table->head = $fields;
        $data = array();
        $xpath = new DOMXPath($xml);

        $display .= $message;
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

        $row_count = 40;
//        $xml->formatOutput = true;
//        $display .= html_writer::tag('textarea', $xml->saveXML(),array('cols'=>45, 'rows'=>$row_count));
        return $display;
}
?>
