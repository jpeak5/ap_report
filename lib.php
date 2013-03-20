<?php

//defined('MOODLE_INTERNAL') || die;
require_once(dirname(__FILE__) . '/../../config.php');
require_once("$CFG->libdir/externallib.php");

abstract class lsuonlinereport {
    
    public $data;
    public $start;
    public $end;
    

    


    /**
     * 
     * @return \DOMDocument this will be the document that is returned to the client
     */
    abstract protected function buildXML($records);



    
}



class lmsEnrollment extends lsuonlinereport{

    
    /**
     * The enrollment status should accurately reflect the status of the student’s enrollment in the section. If
        the student enrolls and the enrollment is accepted, a new enrollment record should reflect that the
        student is actively enrolled in the course. If there is a reason that the student should no longer have
        access to the class, i.e., they drop the course, do not fulfill their financial obligation, etc., then the
        enrollment status should reflect this.
     */
    
    
//    public $tree; //tree of enrollment data
    
    /**
     *
     * @var int unix time for the min time bound to report on
     */
    public $report_start;
    
    /**
     *
     * @var int unix time for the max time bound to report on
     */
    public $report_end;
    
    
    
/*----------------------------------------------------------------------------*/    
/*                  Establish time parameters                                 */    
/*----------------------------------------------------------------------------*/    
    
    public function __construct($r_start=null, $r_end=null, $survey_start=null, $survey_end=null){
        //initialize the outer time boundaries for the output
        $this->report_start = isset($r_start) ? $r_start : self::get_last_save_time();
        $this->report_end   = isset($r_end)   ? $r_end   : time();
        
        //init otuer time bounds for the scan
        $this->survey_start = isset($survey_start) ? $survey_start : self::get_last_save_time();
        $this->survey_end   = isset($survey_end)   ? $survey_end   : time();
        
//        print_r($this);
//        die();
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
                                                mdl_enrol_ues_semesters 
                                            WHERE 
                                                classes_start < ? 
                                            AND 
                                                grades_due > ?'
                                        , array($time,$time));

        return $semesters;
    }
    

    /**
     * looks up the last timestamp for the last 
     * record inserted into the lmsenrollments table
     * Knowing this timestamp allows us to run this 
     * script arbitrarily without creating false data 
     * through repeated calculations.
     * 
     * @global type $DB
     * @return int unixtimestamp |false
     */
    public static function get_last_save_time(){
        global $DB;
        $sql = "SELECT 
                    MAX(timestamp) AS time 
                FROM 
                    mdl_lsureports_lmsenrollment";
        $last = $DB->get_record_sql($sql);
        
        return empty($last) ? false : (int)$last->time   ;
    }

    /**
     * This function is executed at the start of this script to determine 
     * whether or not we need to continue with the rest. It checks mdl_log for
     * the existence of a single course activity record. If it finds one, we 
     * assume that there has been activity since last run, and we can proceed; 
     * otherwise, there has been no activity, and there is no need to run.
     * 
     * TIME CONSIDERATIONS: this method returns true or false based on whether 
     * some activity record occurs in the logs between the survey start and stop 
     * object attributes
     * 
     * @global type $DB
     * @return boolean 
     */
    public function activity_check(){
        global $DB;
        $sql = "SELECT id, time 
                FROM mdl_log 
                WHERE 
                    course > 1 
                    AND
                    time > ?
                    AND 
                    time < ?
                ORDER BY time DESC 
                LIMIT 1";

        $activity = $DB->get_records_sql($sql, array($this->survey_start, $this->survey_end));
        $last = array_pop($activity);
        if(!empty($last)){
            return true;
        }
        return false;
    }    


    /**
     * this function is a utility method that helps optimize the overall 
     * routine by limiting the number of people we check
     * 
     * @param int $since unix timestamp for the minimum mdl_log.time value to check
     * this should usually be the time this service was last run
     * 
     */
    public function get_active_users(){
       global $DB;
      assert(is_int($this->survey_start));
       //get one userid for anyone in the mdl_log table that has done anything
       //in the temporal bounds
       //get, also, the timestamp of the last time they were included in this 
       //scan (so we keep a contiguous record of their activity)
       $sql =  "SELECT DISTINCT u.id
                FROM 
                    mdl_log log 
                        join 
                    mdl_user u 
                        on log.userid = u.id 
                WHERE 
                    log.time > ?;";
       $active_users = $DB->get_records_sql($sql, array($this->survey_start));
       $this->active_users = $active_users;
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
        
        //use the idnumbers of the active users to reduce the number of rows we're working with;
        $active_users = $this->get_active_users(self::get_last_save_time());
        
        if(!$active_users){
            return false;
        }

        $active_ids = array_keys($active_users);

        $sql = sprintf(
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
            FROM mdl_course AS c
                INNER JOIN mdl_context                  AS ctx  ON c.id = ctx.instanceid
                INNER JOIN mdl_role_assignments         AS ra   ON ra.contextid = ctx.id
                INNER JOIN mdl_user                     AS u    ON u.id = ra.userid
                INNER JOIN mdl_enrol_ues_sections       AS us   ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students       AS ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN mdl_enrol_ues_semesters      AS usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses        AS uc   ON uc.id = us.courseid
                
            WHERE 
                ra.roleid IN (5)
                AND usem.id in(%s)
                AND ustu.status = 'enrolled'
                AND u.id IN(%s)
            ORDER BY uniqueCourseSection"
                , implode(',',$semesterids)
                , implode(',', $active_ids)
                );
        $rows = $DB->get_records_sql($sql);
//        print_r($rows);
        return count($rows) > 0 ? $rows : false;
    }
    
    
    /**
     * for the input semesters, this method builds an 
     * internal tree-like data structure composed of 
     * nodes of type semester, section, user.
     * Note that this tree contains only enrollment, no timespent data.
     * 
     * @param $semesters an array of active semesters, likely returned from 
     * @see get_active_ues_semesters
     */
    public function build_enrollment_tree($semesters){
        assert(!empty($semesters));

        //define the root of the tree
        $tree = array();
        
        //populate the first level of the tree
        foreach($semesters as $s){
        
            $o = new semester($s);
            $tree[$o->id] = $o;
        }
        
        //put enrollment records into semester arrays
        $enrollments = $this->get_semester_data(array_keys($semesters));
        //@TODO what hapens if this is empty???
        if(empty($enrollments)){
            return false;
        }
        assert(!empty($enrollments));
        
        //walk through each row returned from the db @see get_semester_data
        foreach($enrollments as $row => $e){

            //in populating the first level, above, we should have already 
            //allocated an array slot for every possible value here
            assert(array_key_exists($e->semesterid, $tree));
            
                $semester = $tree[$e->semesterid];
                if(!array_key_exists($e->sectionid, $semester->sections)){
                    //add a new section to the semester node's sections array
                    $section = new section();
                    $semester->sections[$e->ues_sectionid] = $section;
                    $section->cou_num    = $e->cou_number;
                    $section->department = $e->department;
                    $section->sec_num    = $e->sectionid;
                    $section->id         = $e->ues_sectionid;
                    $section->mdlid      = $e->mdl_courseid;
                    //add this row's student as the next element 
                    //of the current section's users array
                    $user     = new user();
                    $user->id = $e->studentid;
//                    $user->last_update = $e->last_update;
                    $section->users[$e->studentid] = $user;
                }else{
                    //the section already exists, so just add the user 
                    //to the semester->section->users array
                    $user = new user();
                    $user->id = $e->studentid;
//                    $user->last_update = $e->last_update;
                    $semester->sections[$e->sectionid]->users[$e->studentid] = $user;
                }
        }
//        print_r($tree);
        return $tree;
    }    

    
/*----------------------------------------------------------------------------*/    
/*                              Calculate Time Spent                          */    
/*----------------------------------------------------------------------------*/

    public function get_log_activity(){
        global $DB;
        $sql = vsprintf(
               "SELECT 
                   log.id AS logid
                   ,log.time AS time
                   ,log.userid
                   ,log.course
                   ,log.action
                   ,usect.id AS sectionid
                   ,usect.semesterid AS semesterid
                FROM 
                    mdl_log log
                INNER JOIN 
                    mdl_course course on course.id = log.course
                LEFT JOIN
                    mdl_enrol_ues_sections usect on usect.idnumber = course.idnumber
                WHERE 
                    log.time > %s 
                    AND 
                    log.time < %s AND (log.course > 1 OR log.action = 'login')
                ORDER BY sectionid, log.time ASC;",array($this->report_start, $this->report_end));
        $activity_records = $DB->get_records_sql($sql);
//        die($sql);
        return empty($activity_records) ? false : $activity_records;
    }
    
    /**
     * take the flat log records and move them into place in the enrollment tree
     * @param array stdClass $logs
     */
    public function populate_activity_tree($logs, $tree){
        
        foreach($logs as $log){
            $tree[$log->semesterid]->sections[$log->sectionid]->users[$log->userid]->activity[] = $log;
        }
        return $tree;
    }
    
    public function calculate_time_spent($tree){
        //walk the tree
        global $DB, $CFG;
        
        $enrollment = array();
        assert(!empty($tree));
        
        //get last access information from previous run
        //only if it falls within the time period
        // now() - $CFG->sessiontimeout
        //any activity before this cutoff does not need to be included
        //if the time of last access is greater than $CFG->sessiontimeout,
        //we should expect that the first activity may be a login event
        //this will not be true in the case where: 
        //a student has multiple courses AND this is not the first course they visit
        $sql = vsprintf("SELECT 
                            CONCAT(userid,'-',sectionid)
                            , max(lastaccess) lastaccess 
                            , timestamp
                         FROM 
                            mdl_lsureports_lmsenrollment
                         WHERE lastaccess >= %s;"
                ,array(time() - $CFG->sessiontimeout));
        die($sql);
        $active_sessions = $DB->get_records_sql($sql);
        print_r($active_sessions);
        foreach($tree as $semester){
                foreach($semester->sections as $section){
                    /**
                     * begin per-user section timespent calculation
                     * 
                     */
                    foreach($section->users as $user){
                        if(empty($user->activity)){
                            continue;
                        }else{
                            /**
                             * NOTE: we are parsing events as a stream, 
                             * so order matters; this algorithm assumes that 
                             * activity records are sorted least to greatest 
                             */
                            sort($user->activity);
                            $accumulator = 0;
                            
                            $prior_session = $active_sessions[$user->id.'-'.$section->id];
                            if(isset($prior_session)){
                                print_r($prior_session);
                                $user->last_access = $prior_session->lastaccess;
                            }
                            
                            foreach($user->activity as $act){
                                
                                if($act->action == 'login'){
                                    $user->time_spent += $accumulator;
                                    unset($user->last_access);
                                    $accumulator = 0;
                                }
                                
                                if(!isset($user->last_access)){
                                    $user->last_access = $act->time;
                                }else{
                                    $accumulator += ($act->time - $user->last_access);
                                    $user->last_access = $act->time;
                                }
                                
                            }
                            $user->time_spent += $accumulator;
                            $e= new lsureports_lmsenrollment_record();
                            $e->lastaccess = $user->last_access;
                            $e->sectionid  = $section->id;
                            $e->semesterid = $semester->id;
                            $e->timespent  = $user->time_spent;
                            $e->timestamp  = time();
                            $e->userid     = $user->id;
                            
                            $enrollment[]  = $e;
                            
                        }
                    }
                }
            }

        return $enrollment;
    }
    
    
    
/*----------------------------------------------------------------------------*/    
/*                              Persist Data                                  */    
/*----------------------------------------------------------------------------*/    

    /**
     * Relies on $this->tree having already been populated
     * @global type $DB
     * @return \lsureports_lmsenrollment_record
     */
//    public function prepare_enrollment_activity_records(){
//        global $DB;
//        
//        $activity_logs = $this->get_log_activity();
//        $this->populate_activity_tree($activity_logs);
//
//        $activity = array();
//        
//        foreach($sorted_logs as $log => $user_rec){
//            foreach($user_rec as $enrolment_rec){
//                $activity[] = $enrolment_rec;
//            }
//        }
//    return $activity;
//
//    }
    
    
    
    /**
     * save enrollment data, prepared from the enrollment tree, to the database
     * in our dedicated table. This is the last step in the creation of enrollment data.
     * @see prepare_enrollment_records
     * @global type $DB
     * @param array $records of type lmsEnrollmentRecord
     * @return array errors
     */
    public function save_enrollment_activity_records($records){
        
        global $DB;
        $errors = array();
        foreach($records as $record){
            $record->timestamp = time();
            if(!isset($record->semesterid) or !isset($record->sectionid)){
                continue;
            }
            $result = $DB->insert_record('lsureports_lmsenrollment', $record, true, true);
            if(false == $result){
                $errors[] = $record->id;
            }
        }
        return $errors;
    }
    
/*----------------------------------------------------------------------------*/    
/*                              Fetch/report-building methods                 */    
/*----------------------------------------------------------------------------*/
    
    /**
     * fetches time spent from the lsuonlinreports_lmsenrollments 
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
                , sum(len.timespent) AS timespent
                , len.lastaccess 
                , ucourse.department AS department
                , ucourse.cou_number AS coursenumber
                , usect.sec_number AS sectionid
                , 'A' as status
                , NULL AS extensions
                FROM mdl_lsureports_lmsenrollment len
                    LEFT JOIN mdl_user u
                        on len.userid = u.id
                    LEFT JOIN mdl_enrol_ues_sections usect
                        on len.sectionid = usect.id
                    LEFT JOIN mdl_course c
                        on usect.idnumber = c. idnumber
                    LEFT JOIN mdl_enrol_ues_courses ucourse
                        on usect.courseid = ucourse.id
                    LEFT JOIN mdl_enrol_ues_semesters usem
                        on usect.semesterid = usem.id
                WHERE 
                    len.timestamp > %s and len.timestamp <= %s
                GROUP BY len.sectionid"
                ,array($this->report_start, $this->report_end)
                );
        
        $enrollments = $DB->get_records_sql($sql);
//        die(strftime('%F %T',$start)."--".strftime('%F %T',$end));
//        echo sprintf("using %s and %s as start and end", $start, $end);
        print_r($enrollments);
        return count($enrollments) > 0 ? $enrollments : false;
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
        return $doc;
    }
    
    
    
    
/*----------------------------------------------------------------------------*/    
/*               WRAPPER / CONVENIENCE METHODS                                */
/*----------------------------------------------------------------------------*/
    



    
    /**
     * This is the main API call. 
     * Further, this method invokes a 
     * re-scanning of mdl_log and the calculation of latest data
     */
    public function survey_enrollment(){

        $semesters = $this->get_active_ues_semesters();
        mtrace("getting active semesters\n");
        
        $tree = $this->build_enrollment_tree($semesters);
        mtrace("building enrollment tree\n");
        
        $logs = $this->get_log_activity();
        
        $tree = $this->populate_activity_tree($logs, $tree);
        
        $records = $this->calculate_time_spent($tree);
        
//        $records = $this->prepare_enrollment_activity_records();
        $errors = $this->save_enrollment_activity_records($records);
        if($records == false){
            return "no records";
        }
        if(!empty($errors)){
            echo sprintf("got errors %s", print_r($errors));
        }else{
            $xml = $this->get_enrollment_xml();
            if($this->create_file($xml)){
                return $xml;
            }else{
                return "error saving file";
            }
            
        }
                
    }
    
    
    /**
     * main getter for enrollment data as xml
     * @return string
     */
    public function get_enrollment_xml(){
        
        $records = $this->get_enrollment_activity_records();
        $xml = $this->buildXML($records);
        return $xml->saveXML();
    }
    
    
    /**
     * @TODO learn how to do this the Moodle way
     * NOTE: this is a destructive operation in the 
     * sense that the old file, if exists, will be overwritten WITHOUT
     * warning. This is by design, as we never want more than 
     * one disk copy of this data around.
     */
    public function create_file($contents)  {
        
        global $CFG;
        $file = $CFG->dataroot.'/test.txt';
        $handle = fopen($file, 'w');
        assert($handle !=false);
        $success = fwrite($handle, $contents);
        fclose($handle);
        return $success ? true : false;
   
    }
    

}


class semester{
    public $year; //int
    public $name; //string
    public $sections; //array of section
    
    public function  __construct($obj){
        $this->year = $obj->year;
        $this->name = $obj->name;
        $this->id   = $obj->id;
        $this->sections = array();
    }

}

class section{
    public $id; //int
    public $mdlid; //the moodle course id
    public $department; //string
    public $cou_num; //int
    public $sec_num; //int
    public $users; //array  of user
}

class user{
    public $id;    
    public $last_update;
    public $time_spent;
    public $activity;//log records
}

/**
 * models the structure of the corresponding db table
 */
class lsureports_lmsenrollment_record{
//    public $user; //a user isntance
    public $lastaccess; //timest
    public $timespent; //int
    public $semesterid; //ues semester id
    public $sectionid; //unique ues section id
    public $userid; //mdl user id
    public $timestamp; //mdl user id
    
}

class engagementReport extends lsuonlinereport{
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
