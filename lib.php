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
     * @param int $start timestamp for the start of the time range of the report
     * @param int $end   timestamp for the end   of the time range of the report
     * @param int $limit how many records to pull; default = 0
     * 
     * this is the main public call, 
     * and it should be implemented by inheriting classes
     * It return whatever the file sending program wants to send.
     * @return array
     */
    abstract protected function getData($limit=0);
    
    /**
     * persists the data
     * returns boolean 
     */
    abstract protected function saveData();

    /**
     * 
     * @return \DOMDocument this will be the document that is returned to the client
     */
    abstract protected function buildXML($records);


    /**
     * gives extending classes the opportunity to do last minute alterations
     * @param DOMDocument $doc
     * @return string
     */
    protected function postProcessXML($doc){
        $doc->formatOutput;
        return $doc->saveXML();
    }
    
}


class lmsEnrollment extends lsuonlinereport{

    
    /**
     * The enrollment status should accurately reflect the status of the student’s enrollment in the section. If
        the student enrolls and the enrollment is accepted, a new enrollment record should reflect that the
        student is actively enrolled in the course. If there is a reason that the student should no longer have
        access to the class, i.e., they drop the course, do not fulfill their financial obligation, etc., then the
        enrollment status should reflect this.
     */
    
    
    public $semesterids;
    public $sectionids;
    public $studentids;
    public $courseids;
    
    
    /**
     * 
     * @return array integer ids of active semesters
     */
    public function get_active_ues_semester_ids(){
        global $DB;
        $time = time();
        $semesterids = $DB->get_fieldset_select('enrol_ues_semesters', 'id', 'classes_start < ? and grades_due > ?', array($time,$time));

        return $semesterids;
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
    
    function get_time_spent_today_section($userid, $courseid, $last){
        global $DB;
        $sql = "select 
                    time 
                from 
                    mdl_log 
                where 
                    userid = ? and course = ? and time > ? order by time desc;";
        $params = array($userid,$courseid, $last);
        $times = array_keys($DB->get_records_sql($sql, $params));
        
        if(empty($times)){
            return -1;
        }
        $last = array_shift($times);
        $duration = $last - array_pop($times);
        

        return array('last'=>$last, 'duration'=>$duration);
    }
    

    
    public function saveTimeSpent($uid, $sec, $time, $last){
        global $DB;

        
        $record = new stdClass();
        $record->userid = $uid;
        $record->sectionid = $sec;
        $record->timespent = $time;
        $record->lastaccess = $last;
        $record->timestamp = time();
        if($DB->insert_record('lsureports_lmsenrollment', $record)){
            return true;
        }else{
            return false;
            mtrace("failure updating database;");
        }
        
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
    
    
    
    
    
    
    public function saveData() {
        
    }

    /**
     * @TODO include schema definition
     * @return DOMDocument
     */
    public function buildXML($records) {
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $root = $doc->createElement('lmsEnrollments');
        $root->setAttribute('university', '002010');
        
        foreach($records as $k=>$rec){
            $obj = new lmsEnrollmentRecord($rec);
            $obj->validate();
            $fields = array_diff(get_object_vars($obj), array('lmsEnrollments','lmsEnrollment'));
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

    /**
     * 
     * @TODO make use of the start/end timestamps
     * @TODO replace this query with one that leaves the concatenation to the script
     * @global type $DB the global moodle db
     * @param int $start smallest timestamp to return
     * @param int $end   latest timestamp to return 
     * @param int $limit how many records
     * @return array of record objects with fieds conformant to the schema
     * located in tests/lmsEnrollment.xsd
     */
    public function getData($limit = 0) {
        global $DB;
        
        $sql = sprintf(
            "SELECT
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number, '_', u.idnumber) AS enrollmentId,
                u.idnumber AS studentId,  
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                usem.classes_start AS startDate,
                usem.grades_due AS endDate,
                123 as lastcourseacccess,
                789 as timespentinclass,
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
                AND usem.classes_start < UNIX_TIMESTAMP(NOW())
                AND usem.grades_due > UNIX_TIMESTAMP(NOW())
                AND 
                ustu.status = 'enrolled'
            ORDER BY uniqueCourseSection
            LIMIT %s", $limit);
            
        $rows = $DB->get_records_sql($sql);
        $camels = array();
        foreach($rows as $r){
            $camel = new stdClass();

            $camel->enrollmentId        = $r->enrollmentid;
            $camel->studentId           = $r->studentid;
            $camel->courseId            = $r->courseid;
            $camel->sectionId           = $r->sectionid;
            $camel->startDate           = $r->startdate;
            $camel->endDate             = $r->enddate;
            $camel->status              = $r->status;
            $camel->lastCourseAccess    = $r->lastcourseacccess;
            $camel->timeSpentInClass    = $r->timespentinclass;
            $camel->extensions = "";
            
            $camels[] = $camel;
        }
        return $camels;
    }
    

    
    public function get_semesters(){
        global $DB;
        //get active semesters and their significant dates
        $time = time();
        $semesters = $DB->get_records_sql('SELECT * FROM mdl_enrol_ues_semesters WHERE classes_start < ? AND grades_due > ?', array($time, $time));
        
        //build an array of semester IDs for use in subsequent queries
        $ids = array();
        foreach($semesters as $s){
            $sids[] = $s->id;
        }

        return array($semesters, $sids);
        
    }

    /**
     * 
     * @param type $sids array of semester ids
     */
    public function get_sections($sids) {
        global $DB;
        $sections = array();
        foreach($sids as $sid){
            //this should be rewrtten to take advantage of the moodle API call: get_records_list
            $c = $DB->get_records_sql('SELECT * FROM mdl_enrol_ues_sections WHERE semesterid = ?', array($sid));
            $sections = array_merge($sections, $c);
        }
        $sec_ids = array();
        foreach($sections as $section){
            $sec_ids[] = $section->id;
        }
        
        return !empty($sections) ? array($sections,$sec_ids) : false;
    }
    
    /**
     * 
     * @global type $DB
     * @param array $sections section rows 
     * @return array returns a 2-element array: objects and their ids
     */
    public function get_courses($sections){
        global $DB;
        $courses = array();
        
        foreach($sections as $s){
            //this can't be efficient
            $c_tmp = $DB->get_records_sql('SELECT * FROM mdl_enrol_ues_courses WHERE id = ?', array($s->courseid));
            $courses = array_merge($courses, $c_tmp);
        }
        $cids = array();
        assert(!empty($courses));
        foreach($courses as $c){
            $cids = $c->id;
        }
        return !empty($courses) ? array($courses, $cids) : false;
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
                global $DB;
        
        $sql = sprintf(
            "SELECT 
                u.id AS userid,
                u.username,
                from_unixtime(l.time) AS rvisit,
                c.id AS rcourseid,
                c.fullname AS rcourse,
                agg.days AS days,
                agg.numdates,
                agg.numcourses,
                agg.numlogs
             FROM 
                mdl_log l INNER JOIN mdl_user u
                    ON l.userid = u.id
                INNER JOIN mdl_course c
                    ON l.course = c.id
                INNER JOIN ( 
                    SELECT
                        days,
                        userid,
                        max(time) AS maxtime,
                        count(DISTINCT date(from_unixtime(time))) AS 'numdates', 
                        count(DISTINCT course) AS numcourses,
                        count(*) AS numlogs
                    FROM 
                        mdl_log l INNER JOIN mdl_course c
                            ON l.course = c.id
                        INNER JOIN (
                            SELECT 1 AS days
                       ) var 
                    WHERE 
                        l.time > (unix_timestamp() - ((60*60*24)*days))
                        AND c.format != 'site'
                    GROUP BY userid) agg
              ON l.userid = agg.userid
              WHERE 
                l.time = agg.maxtime 
                AND c.format != 'site'
              GROUP BY userid
              ORDER BY l.time DESC");
            
        return $DB->get_records_sql($sql);
    }

    protected function saveData() {
        
    }
}

class lmsEnrollmentRecord {
    
    //see schema @ tests/enrollemnts.xsd for source of member names
    public $enrollmentId;
    public $studentId;
    public $courseId;
    public $sectionId;
    public $startDate;
    public $endDate;
    public $status;
    public $lastCourseAccess;
    public $timeSpentInClass;
    public $extensions;
    
    public function __construct($record){
        
        if(!is_array($record)){
            $record = (array)$record;
        }
        
        $fields = get_object_vars($this);
        
        foreach($fields as $field => $value){
            if(array_key_exists($field, $record)){
                $this->$field = $record[$field];
            }
        }
  
    }
    
    public function validate(){
        $this->enrollmentId     = (int)$this->enrollmentId;
        $this->studentId        = (int)$this->studentId;
        $this->sectionId        = (int)$this->sectionId;
        $this->timeSpentInClass = (int)$this->timeSpentInClass;
        $this->startDate        = strftime('%m/%d/%Y', $this->startDate);
        $this->endDate          = strftime('%m/%d/%Y',$this->endDate);
        
    }
}


?>
