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
    
    
    public $tree; //tree of enrollment data
    
    
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

    
    public function get_activity_records($earliest, $latest=null){
        $latest = isset($latest) ? $latest : time();
        
//        $last_up_sql = 'SELECT max(timestamp) FROM mdl_lsureports_lmsenrollment WHERE'
        $earliest = isset($earliest) ? $earliest : $lastUpdate;
        
    }
    

    public function get_semester_data($semesterids){
        global $DB;
        
        //use the idnumbers of the active users to reduce the number of rows we're working with;
        //this could be done at several steps in the process
        //ie: alter the query
        //@TODO alter the query to only pull data for people who have recent activity
        $active_users = $this->get_active_users();
//        print_r($active_users);
        $active_ids = array_keys($active_users);

        
        $sql = sprintf(
            "SELECT
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number, '_', u.idnumber) AS enrollmentId,
                u.id AS studentId, 
                usem.id semesterid,
                usem.year,
                usem.name,
                uc.department,
                uc.cou_number,
                us.sec_number AS sectionId,
                c.id as mdl_courseid,
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
        return $rows;
    }
    
    /**
     * this function is a utility method that helps optimize the overall 
     * routine by limiting the number of people we check
     */
    public function get_active_users(){
       global $DB;
      
       //get one userid for anyonein the mdl_log table that has done anything
       //in the temporal bounds
       //get, also, the timestamp of the last time they were included in this 
       //scan (so we keep a contiguous record of their activity)
       $sql =  "select distinct u.id
                    , max(len.timestamp)
                FROM 
                    mdl_log log 
                        join 
                    mdl_user u 
                        on log.userid = u.id 
                LEFT JOIN
                    mdl_lsureports_lmsenrollment len
                ON
                    log.userid = len.userid
                where 
                    log.action = 'login';";
       $active_users = $DB->get_records_sql($sql, array(0));

       return $this->active_users = $active_users;
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
    

    
    public function prepare_activity_records(){
        global $DB;
        $errors = array();
        $records = array();
        foreach($this->tree as $semester){  
            foreach($semester->sections as $sec){
                $sql = sprintf("select 
                            userid 
                            , max(time) - min(time) spent
                            , max(time) as last
                        from 
                            mdl_log 
                        where 
                            course = %d group by userid;"
                        ,$sec->mdlid);
                $results = $DB->get_records_sql($sql);
                
                foreach($sec->users as $u){
                    if(array_key_exists($u->id, $results)){
                        $activity = $results[$u->id];
                        $rec = new lsureports_lusenrollment_record();
                        $rec->lastaccess = $activity->last;
                        $rec->timespent  = $activity->spent;
                        $rec->sectionid  = $sec->id;
                        $rec->userid     = $activity->userid;
//                        die(print_r($rec));
                        $records[] = $rec;
                    }
                }
            }
        
        }
    return $records;

    }
    
    
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
    public $lastupdate; //timest - last time we calculated...  
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
        $this->courseId         = (int)$this->courseId;
        
    }
}


?>
