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

    
        public $active_semesters;
    
/*----------------------------------------------------------------------------*/    
/*                  Establish time parameters                                 */    
/*----------------------------------------------------------------------------*/    
    
    public function __construct(){
        
        list($this->start, $this->end) = self::get_yesterday();
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

        return $this->active_semesters = $semesters;
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
       
//       mtrace("START = ".$this->start);
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
       $active_users = $DB->get_records_sql($sql);
//       mtrace($sql);
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
        $active_users = $this->get_active_users();
        
        if(!$active_users){
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
                apen.id AS apid,
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number) AS uniqueCourseSection
            FROM {course} AS c
                INNER JOIN {context}                  AS ctx  ON c.id = ctx.instanceid
                INNER JOIN {role_assignments}         AS ra   ON ra.contextid = ctx.id
                INNER JOIN {user}                     AS u    ON u.id = ra.userid
                INNER JOIN {enrol_ues_sections}       AS us   ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students}       AS ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN {enrol_ues_semesters}      AS usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses}        AS uc   ON uc.id = us.courseid
                LEFT  JOIN {apreport_enrol}           AS apen ON apen.userid = u.id
                
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
        
        mtrace("dumping semester data sql");
//        print_r($rows);
//        die();
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
                    $user->apid = $e->apid;
//                    $user->last_update = $e->last_update;
                    $section->users[$e->studentid] = $user;
                }else{
                    //the section already exists, so just add the user 
                    //to the semester->section->users array
                    $user = new user();
                    $user->id = $e->studentid;
                    $user->apid = $e->apid;
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
//        die($sql);
        return empty($activity_records) ? false : $activity_records;
    }
    
    /**
     * take the flat log records and move them into place in the enrollment tree
     * @param array stdClass $logs
     */
    public function populate_activity_tree($logs, $tree){
        /**
         * unless we filter again now, we will clutter the tree with
         * unwanted section data from the log table
         */
        foreach($logs as $log){
            if(isset($tree[$log->semesterid]) and (get_class($tree[$log->semesterid]) == 'semester')){
                if(isset($tree[$log->semesterid]->sections[$log->sectionid]) and get_class($tree[$log->semesterid]->sections[$log->sectionid]) == 'section'){
                    if(isset($tree[$log->semesterid]->sections[$log->sectionid]->users[$log->userid]) and (get_class($tree[$log->semesterid]->sections[$log->sectionid]->users[$log->userid]) == 'user')){
                        $tree[$log->semesterid]->sections[$log->sectionid]->users[$log->userid]->activity[] = $log;
                    }
                }
            }
        }
        
        return $tree;
    }
    
    public function calculate_time_spent($tree){
        //walk the tree
        global $DB, $CFG;
        
        $enrollment = array();
        assert(!empty($tree));
        

        foreach($tree as $semester){
            assert(!empty($tree));
            
                foreach($semester->sections as $section){
                    assert(get_class($section) == 'section');
                    /**
                     * begin per-user section timespent calculation
                     * 
                     */
                    foreach($section->users as $user){
//                        assert(get_class($section) == 'section');
//                        assert(get_class($user) == 'user');
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
                            $e= new ap_report_table();
                            $e->lastaccess = $user->last_access;
                            $e->sectionid  = $section->id;
                            $e->semesterid = $semester->id;
                            $e->agg_timespent  = $user->time_spent;
                            $e->timestamp  = time();
                            $e->userid     = $user->id;
                            $e->id         = $user->apid;
                            
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
            if(!isset($record->id)){
                $result = $DB->insert_record('apreport_enrol', $record, true, true);
            }else{
                $result = $DB->update_record('apreport_enrol', $record, true, true);
            }
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
                FROM mdl_apreport_enrol len
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
//        die($sql);
        $enrollments = $DB->get_records_sql($sql);
//        die(strftime('%F %T',$start)."--".strftime('%F %T',$end));
//        echo sprintf("using %s and %s as start and end", $start, $end);
//        print_r($enrollments);
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
    
    
    public function update_timespent($record){
        $record->cum_timespent += $record->agg_timespent;
        return $record;
    }
    
    public function reset_agg_timespent($record){
        $record->agg_timespent = 0;
        return $record;
    }
    
    public function update_reset_db(){
        global $DB;
        $timespent_records = $DB->get_records('apreport_enrol');
        $error = 0;
        foreach($timespent_records as $record){
            $updated = $this->update_timespent($record);
            $reset   = $this->reset_agg_timespent($updated);
            $success = $DB->update_record('apreport_enrol', $reset, false);
            if($success != true){
                $error++;
            }
            return $error > 0 ? false : true;
        }
        
        
        
        
        
        return $error == 0 ? true : false;
    }
    
    public function calculate_ts(){
        return true;
    }
    
    public function make_output(){
        return true;
    }
    
    public static function run(){
        $enrol = new lmsEnrollment();
        $db_ok = $enrol->update_reset_db();
        if(!$db_ok){
            die("db update not ok");
        }
        
        $calc_ok = $enrol->calculate_ts();
        if(!$calc_ok){
            die("calc not ok!");
        }
        
        $make_out = $enrol->make_output();
        if(!$make_out){
            die("error making output file");
        }
        return true;
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
    public $apid; //FK into apreports_enrol table
    public $last_update;
    public $time_spent;
    public $activity;//log records
}

/**
 * models the structure of the corresponding db table
 */
class ap_report_table{
//    public $user; //a user isntance
    public $lastaccess;     //timest
    public $agg_timespent;  //int
    public $cum_timespent;  //int
    public $semesterid;     //ues semester id
    public $sectionid;      //unique ues section id
    public $userid;         //mdl user id
    public $timestamp;      //time()
    
    public function __construct($id, $sid, $ats, $cts, $last, $ts, $sem){
        $this->userid = $id;
        $this->sectionid = $sid;
        $this->agg_timespent = $ats;
        $this->cum_timespent = $cts;
        $this->lastaccess = $last;
        $this->timestamp = $ts;
        $this->semesterid = $sem;
    }
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
