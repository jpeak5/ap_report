<?php

//defined('MOODLE_INTERNAL') || die;
require_once(dirname(__FILE__) . '/../../config.php');
require_once("$CFG->libdir/externallib.php");
require_once('classes.php');


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
     * The enrollment status should accurately reflect the status of the student’s enrollment in the section. If
        the student enrolls and the enrollment is accepted, a new enrollment record should reflect that the
        student is actively enrolled in the course. If there is a reason that the student should no longer have
        access to the class, i.e., they drop the course, do not fulfill their financial obligation, etc., then the
        enrollment status should reflect this.
     */
    
    
//    public $tree; //tree of enrollment data

    
        public $active_semesters;
        public $active_users;
        /**
         *
         * @var enrollment_model 
         */
        public $enrollment;
        
        /**
         *
         * @var int unix timestamp for the beginning of an ad hoc reporting timespan 
         */
        public $range_start;
        
        /**
         *
         * @var int unix timestamp for the end of an ad hoc reporting timespan 
         */
        public $range_end;
    
/*----------------------------------------------------------------------------*/    
/*                  Establish time parameters                                 */    
/*----------------------------------------------------------------------------*/    
    /**
     * Default configuration (via cron) is to call without params, resulting in 
     * stats harvesting for yesterday.
     * @param int $start timestamp for start of the range of interest
     * @param int $end   timestamp for end   of the range of interest
     */
    public function __construct(){
        global $CFG;
        list($this->start, $this->end) = self::get_yesterday();

        $this->enrollment = new enrollment_model();
        $this->filename = isset($CFG->apreport_enrol_xml) 
                ? '/'.$CFG->apreport_enrol_xml.'.xml' 
                : '/enrollment.xml';
    }
    
    /**
     * This method derives timestamps for the beginning and end of yesterday
     * @return array int contains the start and end timestamps
     */
    public static function get_yesterday(){
        $today = new DateTime();
        $midnight = new DateTime($today->format('Y-m-d'));
        $end = $midnight->getTimestamp();
        
        //now subtract one day from today's first second
        $today->sub(new DateInterval('P1D'));
        $yesterday = new DateTime($today->format('Y-m-d'));
        $start = $yesterday->getTimestamp();

        return array($start, $end);
    }
    

    /**
     * Get a list of all semesters 
     * where {classes_start < time() < grades_due}
     * @TODO this probably should be made inclusive,  
     * lest we lose stats for the actual first and 
     * last days of the semester
     * 
     * @return array stdClass of active semesters
     */
    public function get_active_ues_semesters(){
        global $DB;
        $time = time();
        $semesters = $DB->get_records_sql('SELECT 
                                                * 
                                            FROM 
                                                {enrol_ues_semesters}
                                            WHERE 
                                                classes_start < ? 
                                            AND 
                                                grades_due > ?'
                                        , array($time,$time));

        $s = array();
        foreach($semesters as $semester){
            $obj = semester::instantiate(array('ues_semester'=>$semester));
            $s[$obj->ues_semester->id] = $obj;
        }
        
        return $this->enrollment->semesters = $s;
    }
    



    /**
     * this function is a utility method that helps optimize the overall 
     * routine by limiting the number of people we check;
     * 
     * We do this by first getting a collection of potential users from current enrollment;
     * Then, limit that collection to include only those users who have registered activity in the logs
     * 
     * @param int $since unix timestamp for the minimum mdl_log.time value to check
     * this should usually be the time this service was last run
     * 
     */
    public function get_active_users(){
       global $DB;
       assert(is_numeric($this->start));
       
       //get one userid for anyone in the mdl_log table that has done anything
       //in the temporal bounds
       //get, also, the timestamp of the last time they were included in this 
       //scan (so we keep a contiguous record of their activity)
       $sql =  vsprintf("SELECT DISTINCT u.id
                FROM 
                    {log} log 
                        join 
                    {user} u 
                        on log.userid = u.id 
                WHERE 
                    log.time > %s
                AND 
                    log.time < %s;",array($this->start,$this->end));
       $this->active_users = $DB->get_records_sql($sql);
       
       return count($this->active_users) > 0 ? $this->active_users : false;
    }
    

    /**
     * utility function that takes in an array of [active] semesterids and queries
     * the db for enrollment records on a per-[ues]section basis;
     * The result set of this query is limited by the return value of 
     * @see get_active_users
     * @global type  $DB
     * @param  array $semesterids  integer semester ids, presumably for active semesters
     * @return array stdClass | false
     */
    public function get_semester_data($semesterids){
        global $DB;
        $active_users = $this->get_active_users();

        //use the idnumbers of the active users to reduce the number of rows we're working with;
        if(!$active_users){
            add_to_log(1, 'ap_reports', 'no active users');
            return false;
        }
        $active_ids = array_keys($active_users);
        
        $sql = vsprintf(
            "SELECT
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number, '_', u.idnumber) AS enrollmentId,
                u.id AS studentId, 
                usem.id AS semesterid,
                usem.year,
                usem.name,
                uc.department,
                uc.cou_number,
                us.sec_number AS sectionId,
                c.id AS mdl_courseid,
                us.id AS ues_sectionid,
                'A' AS status,
                
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number) AS uniqueCourseSection
            FROM {course} AS c
                INNER JOIN {context}                  AS ctx  ON c.id = ctx.instanceid
                INNER JOIN {role_assignments}         AS ra   ON ra.contextid = ctx.id
                INNER JOIN {user}                     AS u    ON u.id = ra.userid
                INNER JOIN {enrol_ues_sections}       AS us   ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students}       AS ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN {enrol_ues_semesters}      AS usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses}        AS uc   ON uc.id = us.courseid
                
                
            WHERE 
                ra.roleid IN (5)
                AND usem.id in(%s)
                AND ustu.status = 'enrolled'
                AND u.id IN(%s)
            ORDER BY uniqueCourseSection"
                , array(implode(',',$semesterids)
                        , implode(',', $active_ids))
                );
        
        $rows = $DB->get_records_sql($sql);
        
        return count($rows) > 0 ? $rows : false;
    }
    
    

    public function build_user_tree(){
        assert(!empty($this->enrollment->semesters));
        
        $datarow = $this->get_semester_data(array_keys($this->enrollment->semesters));
        
        if(empty($this->enrollment->students)){
            $this->enrollment->students = array();
        }
        
        foreach($datarow as $row){
            
            $ues_course              = new ues_courses_tbl();
            $ues_course->cou_number  = $row->cou_number;
            $ues_course->department  = $row->department;

            $ues_section             = new ues_sections_tbl();
            $ues_section->sec_number = $row->sectionid;
            $ues_section->id         = $row->ues_sectionid;
            $ues_section->semesterid = $row->semesterid;

            $mdl_course              = new mdl_course();
            $mdl_course->id          = $row->mdl_courseid;

            $course                  = new course();
            $course->mdl_course      = $mdl_course;
            $course->ues_course      = $ues_course;
            $course->ues_section     = $ues_section;
            
            if(!array_key_exists($row->studentid, $this->enrollment->students)){
                $s = new mdl_user();
                $s->id = $row->studentid;
                
                $student = new student();
                $student->mdl_user = $s;
                
                $this->enrollment->students[$student->mdl_user->id] = $student;
            }
            
            $this->enrollment->students[$student->mdl_user->id]->courses[$course->mdl_course->id] = $course;
            
        }

        return $this->enrollment->students;
        
    }
    
/*----------------------------------------------------------------------------*/    
/*                              Calculate Time Spent                          */    
/*----------------------------------------------------------------------------*/

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
        
        foreach($logs as $log){
            if($log->course == 1 and $log->action != 'login'){
                continue;
            }
            if(array_key_exists($log->userid, $this->enrollment->students)){
                $this->enrollment->students[$log->userid]->activity[$log->logid] = $log;
            }
        }
        
        
        return $this->enrollment;
    }
    
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
                     * if it doesn't exist, set up an ap_repor
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
//                    echo "<hr/>";
//                    print_r($course);
                    
                    
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
    
/*----------------------------------------------------------------------------*/    
/*                              Fetch/report-building methods                 */    
/*----------------------------------------------------------------------------*/
    
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
                , agg_timespent AS timespent
                , len.lastaccess 
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
                    len.lastaccess > %s and len.lastaccess <= %s
                GROUP BY len.sectionid"
                ,array($this->start, $this->end)
                );
        
        $enrollments = $DB->get_records_sql($sql);
//        print_r($enrollments);
        
//          print(strftime('%F %T',$this->start)."--".strftime('%F %T',$this->end)."\n".$sql);
//        echo sprintf("using %s and %s as start and end", $start, $end);
        if(!count($enrollments) > 0){
            add_to_log(1, 'ap_reports', 'no records found in apreports_enrol');
            return false;
        }
        return $enrollments;
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
    
    
    
    
/*----------------------------------------------------------------------------*/    
/*               WRAPPER / CONVENIENCE METHODS                                */
/*----------------------------------------------------------------------------*/

    
    
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
    
    
    public function get_enrollment(){
        $semesters = $this->get_active_ues_semesters();
        if(empty($semesters)){
            add_to_log(1, 'ap_reports', 'no active semesters');
            return false;
        }
        $data = $this->get_semester_data(array_keys($semesters));
        if(!$data){
            add_to_log(1, 'ap_reports', 'no user activity');
            return false;
        }
        $tree     = $this->build_user_tree();
        $activity = $this->populate_activity_tree();
        $timespt  = $this->calculate_time_spent();
        
        return !empty($tree) and !empty($activity) and !empty($timespt);

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
    
    public function save_enrollment_data(){
        $delete = $this->delete_enrollment_data();
        if(!$delete){
            add_to_log(1, 'ap_reports', 'db error: delete_records');
            return false;
        }
//        print_r($this->start);
//        print_r($this->end);

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
    
    public function make_output(){
        return true;
    }
    
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
     * @global type $CFG
     * @global type $DB
     * @return boolean
     */
    public function run(){
        set_config('apreport_got_xml', false);
        set_config('apreport_got_enrollment', false);        
        set_config('apreport_job_start', microtime(true));
        
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
    
    

}



class user{
    public $id;    
    public $apid; //FK into apreports_enrol table
    public $last_update;
    public $time_spent;
    public $activity;//log records
}



class engagementReport extends apreport{
    public function buildXML($records) {
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('lmsEnrollments');
        $root->setAttribute('university', '002010');
        
        foreach($records as $rec){
            $fields = array_diff(get_object_vars($rec), array('lmsEnrollments','lmsEnrollment'));
            $lmsEnollment = $doc->createElement('lmsEnrollment');

            foreach($fields as $k => $v){
                $node = $doc->createElement($k, $v);
                $lmsEnollment->appendChild($node);
            }
            
            $root->appendChild($lmsEnollment);
        }
        $doc->appendChild($root);
        return $doc;
    }

    public function getData($limit = 0) {
        
    }

    protected function saveData() {
        
    }
}

/**
 * parent class for all user-facing data records.
 * Contains formatting methods that ensure adherence to the end-user XML schema
 */
class reportRecord{
    public $enrollmentid;
    public $studentid;
    public $sectionid;
    public $courseid;
    
    public static function format_year($y){
        return substr($y, strlen($y) - 2);
    }
    
    public static function format_section_number($s){
        return sprintf("%03u",(int)$s);
    }
    
    public static function format_department($d){
        return sprintf("%-4s",$d);
    }
    
    public static function format_enrollmentid($y,$sid, $cid, $snum){
        $year_part  = self::format_year($y);
        $mdlcourseid= self::format_5d_courseid($cid);
        $snum = self::format_section_number($snum);
        return $year_part.$sid.$mdlcourseid.$snum;
    }
    
    public static function format_courseid($d, $s){
        $department = self::format_department($d);
        $sectionnum = self::format_section_number($s);
        return $department."".$sectionnum;
    }
    
    public static function format_5d_courseid($d){
        return sprintf('%05d', $d);
    }
    
    public static function format_ap_date($ts){
        return strftime('%m/%d/%Y',$ts);
    }
    
    public static function format_ap_datetime($ts){
        return strftime('%m/%d/%Y %H:%M:%S',$ts);
    }
}

class lmsEnrollmentRecord extends reportRecord{
    
    //see schema @ tests/enrollemnts.xsd for source of member names
    /**
     * this is getting out of control
     * break this out into internal names and a formatter function for xml output
     * @var type 
     */
    public $id;
    public $semesterid; //from ues_semester
    public $year;       //from ues_semester
    public $name;       //from ues_semester
    public $session_key;//from ues_semester
    public $status;
    public $lastaccess;
    public $timespent;
    public $extensions;
    public $sectionnumber;
    public $coursenumber;
    public $department;
    public $startdate;
    public $enddate;
    
    public function __construct($record){
        
        if(!is_array($record)){
            $record = (array)$record;
        }
        
        $fields = get_class_vars('lmsEnrollmentRecord');
        
        foreach($fields as $field => $value){
            if(array_key_exists($field, $record)){
                $this->$field = $record[$field];
            }
        }
  
    }
    
    public function validate(){
        
        $this->studentid        = (int)$this->studentid;
        $this->timeSpentInClass = (int)$this->timespent;
        $this->enrollmentid     = $this->id;
        $this->courseid         = self::format_courseid($this->department, $this->coursenumber);
        $this->lastaccess       = self::format_ap_datetime($this->lastaccess);
        $this->startdate        = self::format_ap_date($this->startdate);
        $this->enddate          = self::format_ap_date($this->enddate);
        
        
        $this->enrollmentid     = self::format_enrollmentid($this->year
                                                            , $this->studentid
                                                            , $this->coursenumber
                                                            , $this->sectionid
                                                            );

    }
    

}



?>
