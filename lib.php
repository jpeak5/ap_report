<?php

defined('MOODLE_INTERNAL') || die;
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


class currentEnrollment extends lsuonlinereport{

    public function saveData() {
        
    }

    /**
     * @TODO do something with $format
     * @param string $format what format? ('json'|'xml'|'plain')
     * @return DOMDocument
     */
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

    /**
     * 
     * @TODO make use of the start/end timestamps
     * @TODO uncomment debug clauses of query
     * 
     * @global type $DB the global moodle db
     * @param int $start smallest timestamp to return
     * @param int $end   latest timestamp to return 
     * @param int $limit how many records
     * @return array
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
            
        return $DB->get_records_sql($sql);
    }
    
    
    /**
     * @TODO finish refactoring the query into bite-sized, optimizable parts
     * @global type $DB
     * @param type $limit
     * @return type
     */
    public function getDataOpt($limit = 10) {
        global $DB;

        $semSQL = "SELECT                 
                *
               FROM
                mdl_enrol_ues_semesters usem
               WHERE
                usem.classes_start < UNIX_TIMESTAMP(NOW())
                AND 
                usem.grades_due > UNIX_TIMESTAMP(NOW())";
        $semIds = array();
        
        $semesters = $DB->get_records_sql($semSQL);
        foreach($semesters as $s){
            $semIds[] = $s->id;
        }
        $inSems = sprintf("(%s)", implode(',',$semIds));
        
        
        $sql = sprintf(
            "SELECT
                usem.year, 
                usem.name, 
                uc.department, 
                uc.cou_number, 
                u.idnumber AS studentId,
                us.sec_number AS sectionId,
                usem.classes_start AS startDate,
                usem.grades_due AS endDate
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
            }
            return $seamless;
    }
}


class currentEnrollmentRecord {
    
    //see schema @ tests/enrollemnts.xsd for source of member names
    public $enrollmentid;
    public $studentid;
    public $courseid;
    public $sectionid;
    public $startdate;
    public $enddate;
    public $status;
    public $uniquecoursesection;
    
    public function __construct($record){
        
        $fields = get_object_vars($this);
        
        foreach($fields as $field => $value){
            if(array_key_exists($field, $record)){
                $this->$field = $record[$field];
            }
        }
  
    }
}


?>
