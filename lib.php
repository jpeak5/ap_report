<?php

//defined('MOODLE_INTERNAL') || die;
require_once(dirname(__FILE__) . '/../../config.php');
require_once("$CFG->libdir/externallib.php");
require_once('classes/apreport.php');
require_once('classes/dbal.php');
require_once('classes/enrollment.php');


function local_ap_report_cron(){
    global $CFG;
        $current_hour = (int)date('H');
        
        $acceptable_hour = (int)$CFG->apreport_daily_run_time_h;

        
        if($current_hour == $acceptable_hour){
        
            mtrace("Begin generating AP Reports...");
            $report = new lmsEnrollment();
            mtrace(sprintf("Getting activity statistics for time range: %s -> %s",
                    strftime('%F %T',$report->start),
                    strftime('%F %T',$report->end)
                    ));
            $report->run();
            add_to_log(1, 'ap_reports', 'cron');
            mtrace("done.");
        }
        return true;
}

abstract class apreport {
    
    public $data;
    public $start;
    public $end;
    public $filename;

    /**
     * 
     * @return \DOMDocument this will be the document that is returned to the client
     */
    abstract protected function buildXML($records);

}



class lmsEnrollment extends apreport{

    
    /**
     * FROM THE AP SPEC:
     * The enrollment status should accurately reflect the status of the student’s enrollment in the section. If
        the student enrolls and the enrollment is accepted, a new enrollment record should reflect that the
        student is actively enrolled in the course. If there is a reason that the student should no longer have
        access to the class, i.e., they drop the course, do not fulfill their financial obligation, etc., then the
        enrollment status should reflect this.
     */

        /**
         *
         * @var enrollment_model 
         */
        public $enrollment;

    
/*----------------------------------------------------------------------------*/    
/*                  Establish time parameters                                 */    
/*----------------------------------------------------------------------------*/    
    /**
     * stats harvesting for yesterday.
     * @param int $start timestamp for start of the range of interest
     * @param int $end   timestamp for end   of the range of interest
     */
    public function __construct(){
        global $CFG;
        list($this->start, $this->end) = apreport_util::get_yesterday();

        $this->enrollment = new enrollment_model();
        assert(count($this->enrollment->semesters)>0);
        $this->filename = isset($CFG->apreport_enrol_xml) 
                ? '/'.$CFG->apreport_enrol_xml.'.xml' 
                : '/enrollment.xml';
    }
 

    /**
     * Defines an array of caseSensitive mappings from 
     * internal class member names to XSD-required names.
     * Using these, we build a document of activity records
     * @param $records array of lmsenrollmentRecords
     * @TODO include schema definition
     * @return DOMDocument
     */
    public function buildXML($records) {
        assert(!empty($records));
        $internal2xml = array(
            'enrollmentid'  =>  'enrollmentId',
            'studentid'     =>  'studentId',
            'courseid'      =>  'courseId',
            'sectionid'     =>  'sectionId',
            'startdate'     =>  'startDate',
            'enddate'       =>  'endDate',
            'status'        =>  'status',
            'lastaccess'    =>  'lastCourseAccess',
            'timespent'     =>  'timeSpentInClass',
            'extensions'    =>  'extensions'
            
        );
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('lmsEnrollments');
        $root->setAttribute('university', '002010');
        
        foreach($records as $k=>$rec){
            $rec = new lmsEnrollmentRecord($rec);
            $rec->validate();

            $lmsEnollment = $doc->createElement('lmsEnrollment');

            foreach($internal2xml as $k => $v){
                $node = $doc->createElement($v, $rec->$k);
                $lmsEnollment->appendChild($node);
            }
            
            $root->appendChild($lmsEnollment);
        }
        $doc->appendChild($root);
        
        assert(get_class($doc) == 'DOMDocument');
        
        return $doc;
    }
    

    /**
     * @TODO learn how to do this the Moodle way
     * NOTE: this is a destructive operation in the 
     * sense that the old file, if exists, will be overwritten WITHOUT
     * warning. This is by design, as we never want more than 
     * one disk copy of this data around.
     * 
     * @param DOMDocument $contents
     */
    public function create_file($contents)  {
        
        global $CFG;
        $contents->formatOutput = true;
        $file = $CFG->dataroot.$this->filename;
        $handle = fopen($file, 'w');
        assert($handle !=false);
        $success = fwrite($handle, $contents->saveXML());
        fclose($handle);
        if(!$success){
            add_to_log(1, 'ap_reports', 'error writing to filesystem');
            return false;
        }
        return true;
   
    }
    

    
    /**
     * Cleans up the apreport_enrol table, if needed before writing new data;
     * There should never be more than one record per day per user per course 
     * 
     * @global type $DB
     */
    private function delete_enrollment_data(){
        global $DB;
        $range = array($this->start, $this->end);
        $table = 'apreport_enrol';
        $where = vsprintf("lastaccess >= %s AND lastaccess < %s",$range);
        
        $count = $DB->get_records_select($table,$where);
        
        if(count($count) > 0){
            $DB->delete_records_select($table,$where);
        }
        return true;
        
    }
    

    
    /**
     * parse log records an a per user basis to calculate 'timeSpentInClass'
     * @global type $DB
     * @global type $CFG
     * @return enrollment_model
     */
    public function calculate_time_spent(){

        global $DB, $CFG;

        foreach($this->enrollment->students as $student){
            $courses = array_keys($student->courses);
            assert(!in_array(1,$courses));
            
            //just ensure we're are starting with earliest log and moving forward
            //NOTE assuming that ksort returns log events in the correct order,
            //chronologically from least to greatest is predicated on the assumption
            //that moodle writes logs with increasing id numbers
            ksort($student->activity);
            
            $current_course = null;
            
            //walk through each log record
            foreach($student->activity as $a){

                assert(!array_key_exists(1,$student->courses));
                
                if(!in_array($a->course, $courses) or ($a->action != 'login' and $a->course ==1)){
                    continue;
                    //if we have logs for a course or something 
                    //we don't know about,skip it
                    //only valid course view events or logins will pass
                }
                
                if($a->action != 'login'){
                    
                    if(!isset($student->courses[$a->course])){
                        $student->courses[$a->course] = new course();
                        assert(!array_key_exists(1,$student->courses));
                    }

                    /**
                     * if it doesn't exist yet, 
                     * set up an ap_report
                     * record to store data
                     */
                    if(!isset($student->courses[$a->course]->ap_report)){
                        $student->courses[$a->course]->ap_report = new ap_report_table();
                    }
                
                
                    $ap             = $student->courses[$a->course]->ap_report;
                    $ap->userid     = $student->mdl_user->id;
                    $ap->semesterid = $student->courses[$a->course]->ues_section->semesterid;
                    $ap->sectionid  = $student->courses[$a->course]->ues_section->id;
                    $this->enrollment->students[$student->mdl_user->id]->courses[$a->course]->ap_report = $ap;
                
                }else{
                    //handle a login event
                    //reset lastaccess placeholder
                    foreach($this->enrollment->students[$student->mdl_user->id]->courses as $c){
                        if(isset($c->ap_report)){
                            $c->ap_report->last_countable = null;
                        }
                    }
                    //reset last active course placeholder
                    $current_course = null;
                    continue;
                }
                //now calculate values:
                if(!isset($current_course)){
                    $current_course = $a->course;
                    $ap->lastaccess = $a->time;
                    $ap->last_countable = $a->time;
                }elseif ($current_course == $a->course) { //continuation
                    $ap->agg_timespent += ($a->time - $ap->lastaccess);
                    $ap->last_countable = $ap->lastaccess = $a->time;
                }else{ // implies $current is set and NOT equal to the current $a->course
                    assert($current_course != 1);
                    $this->enrollment->students[$student->mdl_user->id]->courses[$current_course]->ap_report->last_countable = null;
                    $ap->last_countable = $ap->lastaccess = $a->time;
                    $current_course = $a->course;
                }
            }

        }
        return $this->enrollment;
    }
    
    
    
/*----------------------------------------------------------------------------*/    
/*                              Persist Data                                  */    
/*----------------------------------------------------------------------------*/    

    /**
     * wrapper/convenience method called by @see run() and @see preview_today()
     * When this function exits, user tree structure is built and the timespent 
     * information has been calculated and stored in it.
     * @return boolean true on success, flase on failure
     */
    public function get_enrollment(){
        $semesters = $this->enrollment->get_active_ues_semesters();
        if(empty($semesters)){
            add_to_log(1, 'ap_reports', 'no active semesters');
            return false;
        }
        $this->enrollment->get_active_users($this->start, $this->end);
        $data = $this->enrollment->get_semester_data(array_keys($semesters),
                array_keys($this->enrollment->active_users));
        if(!$data){
            add_to_log(1, 'ap_reports', 'no user activity');
            return false;
        }
        $tree     = $this->enrollment->get_active_students($this->start, $this->end);
        $activity = $this->populate_activity_tree();
        $timespt  = $this->calculate_time_spent();
        
        return !empty($tree) and !empty($activity) and !empty($timespt);

    }    
    
    /**
     * fetches time spent from apreport_enrol 
     * table for a given time range
     * 
     * @global type $DB
     * @param int $start min time boundary
     * @param int $end   max time boundary
     * @return array stdClass | false if no rows are returned
     */
    public function get_enrollment_activity_records(){
        
        global $DB;
        $enrollments = array();
        
        foreach($this->enrollment->semesters as $semester){
            $sql = vsprintf("SELECT len.id AS enrollmentid
                    , len.userid
                    , u.idnumber AS studentid
                    , len.sectionid AS ues_sectionid
                    , c.id AS courseid
                    , len.semesterid AS semesterid
                    , usem.year
                    , usem.name
                    , usem.session_key
                    , usem.classes_start AS startdate
                    , usem.grades_due AS enddate
                    , sum(agg_timespent) AS timespent
                    , max(len.lastaccess) AS lastaccess
                    , ucourse.department AS department
                    , ucourse.cou_number AS coursenumber
                    , usect.sec_number AS sectionid
                    , 'A' as status
                    , NULL AS extensions
                    FROM {apreport_enrol} len
                        LEFT JOIN {user} u
                            on len.userid = u.id
                        LEFT JOIN {enrol_ues_sections} usect
                            on len.sectionid = usect.id
                        LEFT JOIN {course} c
                            on usect.idnumber = c. idnumber
                        LEFT JOIN {enrol_ues_courses} ucourse
                            on usect.courseid = ucourse.id
                        LEFT JOIN {enrol_ues_semesters} usem
                            on usect.semesterid = usem.id
                    WHERE 
                        usem.id = %s
                        AND
                        len.lastaccess >= %s and len.lastaccess <= %s
                    GROUP BY len.sectionid"
                    ,array(
                        $semester->ues_semester->id,
                        $semester->ues_semester->classes_start, 
                        $this->end)
                    );

            $enrollments = array_merge($enrollments,$DB->get_records_sql($sql));
    
        }
        if(!count($enrollments) > 0){
            add_to_log(1, 'ap_reports', 'no records found in apreports_enrol');
            return false;
        }
        return $enrollments;
    }
    
    /**
     * main getter for enrollment data as xml
     * @return string
     */
    public function get_enrollment_xml(){
        
        $records = $this->get_enrollment_activity_records();
        $xml = $this->buildXML($records);
        
        return $xml;
    }    

    /**
     * get activity records for active users from the DB.
     * This data will be used to calculate 'timeSpentInclass'
     * @global type $DB Moodle DB
     * @return array stdClass {log} records
     */
    public function get_log_activity(){
        global $DB;
        $sql = vsprintf(
               "SELECT 
                   log.id AS logid
                   ,usect.id AS sectionid
                   ,usect.semesterid AS semesterid
                   ,log.time AS time
                   ,log.userid
                   ,log.course
                   ,log.action
                FROM 
                    {enrol_ues_sections} usect
                LEFT JOIN
                    {course} course ON course.idnumber = usect.idnumber
                LEFT JOIN
                    {log} log on course.id = log.course
                WHERE 
                    log.time > %s 
                    AND 
                    log.time < %s AND (log.course > 1 OR log.action = 'login')
                ORDER BY sectionid, log.time ASC;",array($this->start, $this->end));
        $activity_records = $DB->get_records_sql($sql);

        return empty($activity_records) ? false : $activity_records;
    }

    /**
     * take the flat log records and move them into place in the enrollment tree
     * @param array stdClass $logs
     */
    public function populate_activity_tree(){
        $logs = $this->get_log_activity();
        $count = 0;
        foreach($logs as $log){
            if($log->course == 1 and $log->action != 'login'){
                continue;
            }
            if(array_key_exists($log->userid, $this->enrollment->students)){
                $this->enrollment->students[$log->userid]->activity[$log->logid] = $log;
                $count++;
            }
        }
        
        
        return $count > 0 ? $this->enrollment : false;
    }
    
    /**
     * wraps most calls made by @see run(), but parameterizes the report to 
     * return results for the current day beginning at 0:00 and ending at time().
     * NB that the records created by this method do not constitute a complete 
     * day's report, and so will be dropped the next time @see run() or is called 
     * or the next time it, itself, is called (the daily cron run will wipe out 
     * records persisted by this method).
     * @return boolean ture on success, false on failure
     */
    public function preview_today(){
        $today = new DateTime();
        $midnight = new DateTime($today->format('Y-m-d'));
        $this->start = $midnight->getTimestamp();
        $this->end = time();
        $this->filename = '/preview.xml';
        add_to_log(1, 'ap_reports', 'preview');
        
        $e = $this->get_enrollment();
        $x = $this->save_enrollment_data();
        if($e and $x){
            return $x;
        }else{
            return false;
        }
    }    
    
    /**
     * @TODO let the start and complete flags be simple boolean rather than timestamps
     * @TODO the boolean set_configs could be made more granular
     * @TODO continue refactoring this wrapper to allow better communication back
     * to the caller for better end-user messages (ie remove intermediary wrappers)
     * @global type $CFG
     * @global type $DB
     * @return boolean
     */
    public function run(){
        set_config('apreport_got_xml', false);
        set_config('apreport_got_enrollment', false);        
        set_config('apreport_job_start', microtime(true));
        
        //if there has been no activity, that is not a failure of this system
        if(!$this->enrollment->get_active_users($this->start,$this->end)){
            $doc = new DOMDocument();
            $doc->appendChild(new DOMElement('lmsEnrollments', "No Data. Check for user activity in the moodle log table"));
            set_config('apreport_job_complete', microtime(true));
            return $doc;
        }
        
        if(!$this->get_enrollment()){
            add_to_log(1, 'ap_reports', 'get_enrollment failure');
            return false;
        }
        set_config('apreport_got_enrollment', true);
        
        $xml = $this->save_enrollment_data();
        
        if(!$xml){
            add_to_log(1, 'ap_reports', 'no user activity');

            return false;
        }
        set_config('apreport_got_xml', true);
        
        set_config('apreport_job_complete', microtime(true));
        add_to_log(1, 'ap_reports', 'complete');
        return $xml;
    }
    
    public function run_arbitrary_day($start, $end){
        
        
        
        $this->start = $start;
        $this->end = $end;
        add_to_log(1, 'ap_reports', 'backfill_'.$start);
        
        $semesters = $this->enrollment->get_active_ues_semesters();
        if(empty($semesters)){
            add_to_log(1, 'ap_reports', 'no active semesters');
            return false;
        }
        $data = $this->get_semester_data(array_keys($semesters));
        if(!$data){
            add_to_log(1, 'ap_reports', 'no user activity');
            return 'no data';
        }
        $tree = $this->enrollment->get_active_students($this->start, $this->end);
        if(empty($tree)){
            return 'no students';
        }
        if(!$this->populate_activity_tree()){
            return 'no activity';
        }
        
        $this->calculate_time_spent();
        
        $x = $this->save_enrollment_data();
        if($x){
            return 'success';
        }else{
            return 'save fail';
        }
    }

    /**
     * save enrollment data, prepared from the enrollment tree, to the database
     * in our dedicated table. This is the last step in the creation of enrollment data.
     * @see prepare_enrollment_records
     * @global type $DB
     * @param array $records of type lmsEnrollmentRecord
     * @return array errors
     */
    public function save_enrollment_activity_records(){
        
        global $DB;
        $inserts = array();
        foreach($this->enrollment->students as $student){
            foreach($student->courses as $course){
                if(!isset($course->ap_report)){
                    continue;
                }else{

                    //prevent a DB write no nulls error by setting 0 for 
                    //the case when someone has merely touched a course once
                    if(!isset($course->ap_report->agg_timespent)){
                        $course->ap_report->agg_timespent = 0;
                    }
                    $course->ap_report->timestamp = time();

                    $inserts[] = $DB->insert_record(
                            'apreport_enrol', 
                            $course->ap_report, 
                            true, 
                            true
                            );
                }
                
                
            }
            
        }

        return $inserts;
        
    }    
    
    /**
     * convenience/wrapper method called by @see run() and @see preview_today
     * to write calculated timespent to the db.
     * NB that this method first makes a call to @see delete_enrollment_data()
     * to wipe out any data for the time span defined by 
     * $this->start and $this->end only; this is intended to be useful when 
     * reprocessing activity stats after a failure; also, this clears away 
     * any data created by @see preview_today() which is considered incomplete 
     * until the day is done (midnight).
     * @return boolean
     */
    public function save_enrollment_data(){
        $delete = $this->delete_enrollment_data();
        if(!$delete){
            add_to_log(1, 'ap_reports', 'db error: delete_records');
            return false;
        }

        $inserts = $this->save_enrollment_activity_records();
        if(!$inserts){
            add_to_log(1, 'ap_reports', 'db error: save_activity ');
            return false;
        }
        
        $xml = $this->get_enrollment_xml();
        if(empty($xml)){
           add_to_log(1, 'ap_reports', 'error get_enrollment_xml');
           return false; 
        }
        
        if(!$this->create_file($xml)){
            add_to_log(1, 'ap_reports', 'error create_file');
            return false;
        }
        
        return $xml;
    }    

    
    

}










?>
