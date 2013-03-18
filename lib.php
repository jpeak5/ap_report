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
     * @param string $format type of output to return ('xml')
     * @param int $limit
     * @return string
     */
    public function getReport($format='XML', $limit=0){
        $records = $this->getData($limit);
        
        $report="build".$format;
        $doc = $this->$report($records);
        
        $post = "postProcess".$format;
        $string = $this->$post($doc);
        return $string;
    }
    


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
    
    
    public $tree; //tree of enrollment data
    public $start;
    
    public function __construct(){
        $this->start = strtotime('-1 day');
    }
    
    /**
     * 
     * @return array integer ids of active semesters
     */
    public function get_active_ues_semester_ids(){
        global $DB;
        $time = time();
        //moodle data api calls are not testable without generating test data
//        $semesterids = $DB->get_fieldset_select('enrol_ues_semesters', 'id', 'classes_start < ? and grades_due > ?', array($time,$time));
            $semesterids = $DB->get_fieldset_select('enrol_ues_semesters', 'id', 'classes_start < ? and grades_due > ?', array($time,$time));
        return $semesterids;
    }
    /**
     * 
     * @return array integer ids of active semesters
     */
    public function get_active_ues_semesters(){
        global $DB;
        $time = time();
        $semesters = $DB->get_records_sql('SELECT * FROM mdl_enrol_ues_semesters WHERE classes_start < ? and grades_due > ?', array($time,$time));

        return $semesters;
    }
    
    /**
     * 
     * @param int $semesterid semester id
     * @return array section ids for the given semester
     */
    public function get_active_section_ids(array $semesterid){
        global $DB;
        $sectionids = $DB->get_records_list('enrol_ues_sections','semesterid', $semesterid,'','id');
        return array_keys($sectionids);
    }
    
    /**
     * 
     * @param int $sectionid section id
     * @return array student ids for a given section
     */
    public function get_studentids_per_section($sectionid){
        global $DB;
        $studentids = $DB->get_records_select('enrol_ues_students','sectionid = ? and status = ?', array($sectionid, 'enrolled'),'','userid');
        return array_keys($studentids);
    }
    
    /**
     * 
     * @param int $sectionid a ues section id 
     * @return array moodle course id numbers
     */
    public function get_moodle_course_id($sectionid){
        global $DB;
        $sql = "select 
                    c.id moodle_courseid 
                from 
                    mdl_enrol_ues_sections usect 
                    join 
                        mdl_course c on c.idnumber = usect.idnumber 
                where usect.id = {$sectionid};";

        $courseids = $DB->get_records_sql($sql);
        $key = array_keys($courseids);
                
        return array_pop($key);
    }
    

    
    public function calculateTimeSpent(){
        global $DB;
        $this->semesterids = $this->get_active_ues_semester_ids();
        $this->sections = $this->get_active_section_ids($this->semesterids);
        $start = time();
        $inner = 0;
        mtrace(sprintf("got %s sections", count($this->sections)));
        $section_count = 0;
        foreach($this->sections as $s){
            $section_count++;
            $students = $this->get_studentids_per_section($s);
            $courseuid = $this->get_moodle_course_id($s);
            foreach($students as $st){
                
                $today      = strtotime('today'); //12am this morning

                $last_sql = 'Select timestamp from {lsureports_lmsenrollment} where userid = ? and sectionid = ? order by timestamp desc limit 1';
                $params = array($st, $s);

                $last_update = array_keys($DB->get_records_sql($last_sql, $params));
                $last_update_ts = empty($last_update) ? $today : array_pop($last_update);
                
                
                $time = $this->get_time_spent_today_section($st, $courseuid, $last_update_ts);

                $inner++;
                if($time['duration'] > 0){
                    
                    $this->saveTimeSpent($st, $s, $time['duration'], $time['last']);
 
                }
            }
            if(time() - $start > 120){
                die(sprintf("one minute has elapsed; we have processed %d", $inner));
            }
        }
        echo sprintf("that took %f seconds and we processed %d students in %d sections", time() - $start, $inner, $section_count);
        
        
    }
    
    
    
    protected function getData($limit = 0) {
        
    }
    
    public static function get_last_run_time(){
        global $DB;
        $sql = "SELECT MAX(timestamp) AS time FROM mdl_lsureports_lmsenrollment";
        $last = $DB->get_record_sql($sql);
        return $last->time;
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

    /**
     * @TODO include schema definition
     * @return DOMDocument
     */
    public function buildXML($records) {
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

//            $fields = array_diff(get_object_vars($rec), array('lmsEnrollments','lmsEnrollment'));
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


    

    public function get_semester_data($semesterids){
        global $DB;
        
        //use the idnumbers of the active users to reduce the number of rows we're working with;
        //this could be done at several steps in the process
        //ie: alter the query
        //@TODO alter the query to only pull data for people who have recent activity
        $active_users = $this->get_active_users(self::get_last_run_time());
        
        if(!$active_users){
            return false;
        }
//        print_r($active_users);
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
                INNER JOIN mdl_context AS ctx ON c.id = ctx.instanceid
                INNER JOIN mdl_role_assignments AS ra ON ra.contextid = ctx.id
                INNER JOIN mdl_user AS u ON u.id = ra.userid
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
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
//        die(print_r($rows));
        return count($rows) > 0 ? $rows : false;
    }
    
    /**
     * this function is a utility method that helps optimize the overall 
     * routine by limiting the number of people we check
     */
    public function get_active_users($since){
       global $DB;
      
       //get one userid for anyonein the mdl_log table that has done anything
       //in the temporal bounds
       //get, also, the timestamp of the last time they were included in this 
       //scan (so we keep a contiguous record of their activity)
       $sql =  "select distinct u.id
                FROM 
                    mdl_log log 
                        join 
                    mdl_user u 
                        on log.userid = u.id 
                where 
                    log.time > ?;";
       $active_users = $DB->get_records_sql($sql, array($since));
       $this->active_users = $active_users;
       return count($this->active_users) > 0 ? $this->active_users : false;
    }
    
    /**
     * 
     * @param $semesters 
     */
    public function build_enrollment_tree($semesters){
        
        
        $enrollments = $this->get_semester_data(array_keys($semesters));
        
        $enrollment = array();
        
        //populate the first level of the tree
        foreach($semesters as $s){
        
            $o = new semester($s);
            $enrollment[$o->id] = $o;
        }
        
        //put enrollment records into semester arrays
        foreach($enrollments as $row => $e){

            assert(array_key_exists($e->semesterid, $enrollment));
            
                $semester = $enrollment[$e->semesterid];
                if(!array_key_exists($e->sectionid, $semester->sections)){
                    $section = new section();
                    $semester->sections[$e->ues_sectionid] = $section;
                    $section->cou_num    = $e->cou_number;
                    $section->department = $e->department;
                    $section->sec_num    = $e->sectionid;
                    $section->id         = $e->ues_sectionid;
                    $section->mdlid      = $e->mdl_courseid;
                    
                    $user     = new user();
                    $user->id = $e->studentid;
                    $section->users[$e->studentid] = $user;
                }else{
                    
                    $user = new user();
                    $user->id = $e->studentid;
                    $semester->sections[$e->sectionid]->users[$e->studentid] = $user;
                }
         
        }
//        print_r($enrollment);
        return $this->tree = $enrollment;
    }
    

    /**
     * Relies on $this->tree having already been populated
     * @global type $DB
     * @return \lsureports_lusenrollment_record
     */
    public function prepare_activity_records(){
        global $DB;
        $errors = array();
        $records = array();
        foreach($this->tree as $semester){  
            foreach($semester->sections as $sec){
                $sql = sprintf("select 
                                    log.userid 
                                    , min(log.time) earliest_action
                                    , max(log.time) as latest_action
                                    , max(len.timestamp) as lastupdate
                                from 
                                    mdl_log log
                                LEFT JOIN 
                                    mdl_lsureports_lmsenrollment len
                                ON 
                                    len.userid = log.userid
                                where 
                                    course = %d
                                AND
                                    time > %d;"
                        ,$sec->mdlid
                        ,$this->start);
//                print_r($sql);
                $results = $DB->get_records_sql($sql);
                
                foreach($sec->users as $u){
                    if(array_key_exists($u->id, $results)){
                        $activity = $results[$u->id];
                        $rec = new lsureports_lusenrollment_record();
                        
                        $already_ran    = $activity->lastupdate  > $this->start  ? true : false;
                        $do_nothing     = $activity->lastupdate      > $activity->latest_action ? true : false;
                        $active_today   = $activity->earliest_action > $this->start ? true : false;
                        
                        if($already_ran){
//                            echo sprintf('already ran is true; last update %s is greater than today start %s'
//                                    , strftime('%F %T', $activity->lastupdate)
//                                    , strftime('%F %T', $this->start)
//                                    );
                            $spent = $activity->latest_action - $activity->lastupdate;
//                            echo sprintf("\n'latest_action' (%s) - 'last_update' (%s) =  %d - %d = %d"
//                                    
//                                    , strftime('%F %T',$activity->latest_action)
//                                    , strftime('%F %T',$activity->lastupdate)
//                                    , $activity->latest_action
//                                    , $activity->lastupdate
//                                    
//                                    , $activity->latest_action - $activity->lastupdate
//                                    );
                        }else{
                            
                            $spent = $active_today ? $activity->latest_action - $activity->earliest_action : 0;
                        }
                        
                        $rec->lastaccess = $activity->latest_action;
                        $rec->timespent  = $spent;
                        $rec->sectionid  = $sec->id;
                        $rec->semesterid = $semester->id;
                        $rec->userid     = $activity->userid;
                        $rec->timestamp  = time();
//                        echo sprintf('last update = %s', strftime('%F %T', $activity->lastupdate));
//                        print_r($rec);
                        $records[] = $rec;
                    }
                }
            }
        
        }
    return $records;

    }
    
    public function get_activity_records($start=null, $end=null){
        $start  = is_null($start) ? strtotime('-2 day') : $start;
        $end    = is_null($end)   ? time()              : $end;
        global $DB;
        $sql = "SELECT len.id AS enrollmentid
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
                    len.timestamp > ? and len.timestamp < ?
                GROUP BY len.sectionid";
        $enrollments = $DB->get_records_sql($sql,array($start, $end));
//        die(strftime('%F %T',$start)."--".strftime('%F %T',$end));
//        echo sprintf("using %s and %s as start and end", $start, $end);
//        print_r($enrollments);
        return count($enrollments) > 0 ? $enrollments : false;
    }
    
    public function prepare_xml_enrollment_records($start=null, $end=null){
        
        
        
    }
    
    /**
     * 
     * @global type $DB
     * @param array $records of type lmsEnrollmentRecord
     * @return array errors
     */
    public function save_activity_records($records){
        global $DB;
        $errors = array();
        foreach($records as $record){
            $record->timestamp = time();
            $result = $DB->insert_record('lsureports_lmsenrollment', $record, true, true);
            if(false == $result){
                $errors[] = $record->id;
            }
        }
        return $errors;
    }
    
    
    public static function survey_enrollment(){
        $report = new lmsEnrollment();
        $semesters = $report->get_active_ues_semesters();
        $report->build_enrollment_tree($semesters);
        $records = $report->prepare_activity_records();
        $errors = $report->save_activity_records($records);
        if(!empty($errors)){
            echo sprintf("got errors %s", print_r($errors));
        }
                
    }
    
    /**
     * main getter for enrollment data as xml
     * @return DOMDocument
     */
    public static function get_enrollment_xml(){
        $report = new lmsEnrollment();
        $records = $report->get_activity_records();
        $xml = $report->buildXML($records);
        return $xml;
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
}

class lsureports_lusenrollment_record{
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
        $this->lastaccess       = self::format_ap_date($this->lastaccess);
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
