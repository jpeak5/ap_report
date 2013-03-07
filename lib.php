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
    
    
    /**
     * @TODO finish refactoring the query into bite-sized, optimizable parts
     * @global type $DB
     * @param type $limit
     * @return type
     */
    public function getDataOpt($limit = 10) {
        global $DB;

        //get semester objects from the enrolment system;
        //for convenience, store a list of semester ids in inSems for use in DB query
        
        list($semesters, $inSems) = $this->get_semesters();
 
        $sql = sprintf(
            "SELECT
                CONCAT(usem.year,usem.name, uc.department, uc.cou_number, u.id, us.sec_number) AS 'key',
                usem.year, 
                usem.name, 
                uc.department, 
                uc.cou_number, 
                u.idnumber AS studentId,
                us.sec_number AS sectionId,
                usem.classes_start AS startDate,
                usem.grades_due AS endDate
            FROM mdl_course AS c
                INNER JOIN mdl_context AS           ctx ON c.id = ctx.instanceid
                INNER JOIN mdl_role_assignments AS  ra ON ra.contextid = ctx.id
                INNER JOIN mdl_user                 u ON u.id = ra.userid
                INNER JOIN mdl_enrol_ues_sections   us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students   ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN mdl_enrol_ues_semesters  usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses    uc ON uc.id = us.courseid
            WHERE 
            ra.roleid IN (5)
            AND usem.id IN {$inSems}
            AND 
            ustu.status = 'enrolled'
            ORDER BY usem.year, usem.name, uc.department, uc.cou_number, sectionId
            LIMIT %s", $limit);

            $raw = $DB->get_records_sql($sql);

            $seamless = array();
            
            foreach($raw as $r){
                $new = new stdClass();
                $new->enrollmentId        = implode('_', array($r->year, $r->name, $r->department, $r->cou_number, $r->sectionid, $r->studentid));
                $new->studentId           = $r->studentid;
                $new->courseId            = implode(' ', array($r->department, $r->cou_number));
                $new->sectionId           = $r->sectionid;
                $new->startdate           = $r->startdate;    //@TODO we can get this elsewhere
                $new->enddate             = $r->enddate;      //@TODO we can get this elsewhere
                $new->status              = 'A';
                $new->uniqueCourseSection = implode('_', array($r->year, $r->name, $r->department, $r->cou_number, $r->sectionid));

                $seamless[] = $new;
                $records[] = $seamless;
            }
            return $records;
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
        
        $inSems = sprintf("(%s)", implode(',',$sems));
        return array($semesters, $inSems);
        
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
