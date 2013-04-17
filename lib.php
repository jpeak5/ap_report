<?php

require_once(dirname(__FILE__) . '/../../config.php');
require_once("$CFG->libdir/externallib.php");
require_once('classes/apreport.php');
require_once('classes/dbal.php');
require_once('classes/enrollment.php');


function local_ap_report_cron(){
    global $CFG;
    if($CFG->apreport_with_cron != 1){
        return true;
    }
    $current_hour = (int)date('H');

    $acceptable_hour = (int)$CFG->apreport_daily_run_time_h;

    $reports = array('lmsEnrollment','lmsGroupMembership', 'lmsSectionGroup','lmsCoursework');

    if($current_hour == $acceptable_hour){
        foreach($reports as $r){
            print ("Begin {$r} report...");
            $report = new $r();

            if($r == 'lmsEnrollment'){
                print (sprintf("Getting activity statistics for time range: %s -> %s",
                    strftime('%F %T',$report->start),
                    strftime('%F %T',$report->end)
                    ));
            }
            $report->run();

            add_to_log(1, $r, 'cron');
            print("done.");
        }
    }
    return true;
}

abstract class apreport {
    
    public $data;
    public $start;
    public $end;
    public $filename;
    public static $internal_name;
    public $job_status;



    /**
     * @TODO learn how to do this the Moodle way
     * NOTE: this is a destructive operation in the 
     * sense that the old file, if exists, will be overwritten WITHOUT
     * warning. This is by design, as we never want more than 
     * one disk copy of this data around.
     * 
     * @param DOMDocument $contents
     */
    public function create_file($contents, $filepath)  {
        
        global $CFG;
        $contents->formatOutput = true;
        $handle = fopen($filepath, 'w');
        assert($handle !=false);
        $success = fwrite($handle, $contents->saveXML());
        fclose($handle);
        if(!$success){
            add_to_log(1, 'ap_reports', sprintf('error writing to filesystem at %s', $filepath));
            return false;
        }
        return true;
   
    }
    
    /**
     * @param apreport_status $stat
     */
    public function update_job_status($comp, $stage, $status, $info=null, $sub=null) {

        $subcomp  = isset($sub) ? '_'.$sub  : null;
        $info     = isset($info)? '  : '.$info : null;
        set_config('apreport_'.$comp.$subcomp, $stage.':  '.$status.$info);
    }
    
}

class apreport_error_severity{
    const INFO   = 0;
    const WARN   = 1;
    const SEVERE = 2;
    const FATAL  = 3;
}

class apreport_job_status{
    const SUCCESS    = 'success';
    const EXCEPTION  = 'exception(s)';
    const FAILURE    = 'failure';
}
class apreport_job_stage{
    const INIT       = 'initialized';
    const BEGIN      = 'begun';
    const QUERY      = 'query';
    const PERSIST    = 'persist new data';
    const RETRIEVE   = 'retrieve data';
    const COMPLETE   = 'complete';
    const ABORT      = 'aborted';
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
        global $CFG;
        
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
        
        $file = $CFG->dataroot.$this->filename;
        if(!$this->create_file($xml, $file)){
            add_to_log(1, 'ap_reports', 'error create_file');
            return false;
        }
        
        return $xml;
    }    

    
    

}




/**
 * The LMS group membership file contains data from the LMS system matching up students with the
section groups to which they belong. A student may belong to one or more groups within a section.
This data feed should include the group assignments for all active students recruited by Academic
Partnerships for the previous, current, and upcoming terms.
 */
class lmsGroupMembership extends apreport{
    /**
     *
     * @var enrollment_model 
     */
    public $enrollment;
    
    public function __construct($e = null){
        $this->enrollment = (isset($e) and get_class($e) == 'enrollment_model') ? $e : new enrollment_model();
    }
    
    public function getXML(){

        $objects = array();
        foreach($this->enrollment->group_membership_records as $key=>$records){
            foreach($records as $record){
                $objects[] = lmsGroupMembershipRecord::instantiate($record);
            }
        }
        
        assert(count($objects) > 0);
        $xdoc = lmsGroupMembershipRecord::toXMLDoc($objects, 'lmsGroupMembers', 'lmsGroupMember');

        return $xdoc;
    }
    
    
    public function run(){
        
        global $CFG;
        $this->enrollment->get_group_membership_report();
        $content = $this->getXML();
        $content->format = true;
        $file = $CFG->dataroot.'/groups.xml';
        return $this->create_file($content, $file) ? $content : false;
    }
    
}

/**
 * The LMS section group file contains data from the LMS system regarding the sections that have been
setup within the course sections. A section typically consists of two or more groups. However, an entire
section may be contained within a single group, or a section may not contain any groups. In the latter
case, a section group record should be sent with empty group id and group name fields.
The data captured in the LMS section group file includes the id and name of the group, the section the
group belongs to, the id, name, and email address of the primary instructor for the section, as well as
the id, name, and email address of the instructor, teacher assistant, or coach assigned to the group.
This data feed should include data for all of the sections which contain students recruited by Academic
Partnerships for the previous, current, and upcoming terms.
 */
class lmsSectionGroup extends apreport{
    public $enrollment;
    
    public function __construct($e = null){
        $this->enrollment = (isset($e) and get_class($e) == 'enrollment_model') ? $e : new enrollment_model();
    }
    
    public function run(){
        global $CFG;
        $xdoc = lmsSectionGroupRecord::toXMLDoc($this->get_section_groups(), 'lmsSectionGroups', 'lmsSectionGroup');
        if(($xdoc)!=false){
            if($this->create_file($xdoc, $CFG->dataroot.'/sectionGroup.xml')!=false){
                return $xdoc;
            }
        }
        return false;
    }
    
    public function merge_instructors_coaches(){
        $instructors = $this->enrollment->get_groups_primary_instructors();
        $coaches = $this->enrollment->get_groups_coaches();
        $section_groups = array();
        
        if(!$instructors){
            return false;
        }elseif(!$coaches){
            return $instructors;
        }
        
        foreach($instructors as $inst){
            if(array_key_exists($inst->groupid, $coaches)){
                $inst->coachid          = $coaches[$inst->groupid]->coachid;
                $inst->coachfirstname   = $coaches[$inst->groupid]->coachfirstname;
                $inst->coachlastname    = $coaches[$inst->groupid]->coachlastname;
                $inst->coachemail       = $coaches[$inst->groupid]->coachemail;
            }
            $section_groups[] = $inst;
        }
        return $section_groups;
    }
    
    public function get_section_groups(){
        return $this->merge_instructors_coaches();
    }
    
}


/**
 *  The LMS coursework file contains data from the LMS system tracking each student’s progress with
    assigned tasks over the term of a course. A separate data record exists for each
    section/student/coursework item combination in the LMS. For each coursework item, It includes the id
    and name of the item, due date and submitted date, the number of points possible and points received,
    and the grade category and category weight.
    This data feed should include the coursework for all active students recruited by Academic Partnerships
    for the previous, current, and upcoming terms.
 * 
 */
class lmsCoursework extends apreport{
    
    const QUIZ = 'quiz';
    const ASSIGN = 'assignment';
    const ASSIGN22 = 'assignment_2_2';
    const DATABASE = 'database';
    const FORUM = 'forum';
    const FORUMNG = 'forum_ng';
    const GLOSSARY = 'glossary';
    const HOTPOT    = 'hotpot';
    const KALVIDASSIGN    = 'kaltvidassign';
    const LESSON    = 'lesson';
    
    const INTERNAL_NAME = 'lmsCoursework';
    
    public static $subreports = array(
        'quiz',
        'assign',
        'assignment',
        'database', 
        'forum',
        'forumng',
        'glossary',
        'hotpot',
        'kalvidassign',
        'lesson',
        'scorm'
    );



    public $courses;
    public $errors;
    public $new_records;
    
    public function __construct(){
        $this->courses = enrollment_model::get_all_courses(enrollment_model::get_active_ues_semesters(null, true), true);
    }
    
    public function coursework_get_subreport_dataset($cids,$qry, $type){
        global $DB;
        $recs = array();
        foreach($cids as $cid){
            $sql = sprintf($qry, $cid,$cid);
                    $recs = array_merge($recs,$DB->get_records_sql($sql));
        }
        
        //calculate SCORM date complete
        if($type == 'scorm'){
            foreach($recs as $rec){
                if(isset($rec->timeelapsed) && isset($rec->datestarted)){
                    $rec->datesubmitted = lmsCoursework::get_scorm_datesubmitted($rec->datestarted, $rec->timeelapsed);
                }
            }
        }
        return $recs;
    }
    
    public function run(){
        global $DB,$CFG;
        $this->update_job_status_all(apreport_job_stage::BEGIN, apreport_job_status::SUCCESS);
        if(empty($this->courses)){
            //this could happen on a day where there are zero semesters in session
            $this->set_status();
            $this->update_job_status_all(apreport_job_stage::BEGIN, apreport_job_status::EXCEPTION, 'no courses');
            return true;
        }
        $enr = new enrollment_model();

        //get records, one report at a time with completion status
        foreach(coursework_queries::$queries as $type => $query){
            $records[$type] = array();
            $records = $this->coursework_get_subreport_dataset($this->courses, $query, $type);

            if(count($records)<1){
                $this->update_job_status_one($type, apreport_job_stage::ABORT, apreport_job_status::EXCEPTION, "empty resultset");

            }else{
                $this->update_job_status_one($type, apreport_job_stage::QUERY, apreport_job_status::SUCCESS);

                //save to db
                if($this->clean_db($type)){
                    $persist_success = $this->persist_db_records($records,$type);
                }
                if($persist_success > 0){
                    $this->update_job_status_one($type, apreport_job_stage::PERSIST, apreport_job_status::SUCCESS);
                }else{
                    $this->update_job_status_one($type, apreport_job_stage::PERSIST, apreport_job_status::FAILURE);
                    continue;
                }
                $this->update_job_status_one($type, apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS);
            }
        }
        //set status message about the loop exit
        $this->update_job_status(self::INTERNAL_NAME, apreport_job_stage::QUERY, apreport_job_status::SUCCESS);

        //read back from db
        $dataset = $DB->get_records('apreport_coursework');
        if(!empty($dataset)){
            $this->update_job_status(self::INTERNAL_NAME, apreport_job_stage::RETRIEVE, apreport_job_status::SUCCESS);
        }else{
            $this->update_job_status(self::INTERNAL_NAME, apreport_job_stage::RETRIEVE, apreport_job_status::EXCEPTION, "no rows");
            mtrace("dataset is empty");;
            return false;
        }

        $cwks = array();
        foreach($dataset as $d){
            $cwks[] = lmsCourseworkRecord::instantiate($d);
        }

        //make xml
        $xdoc = lmsCourseworkRecord::toXMLDoc($cwks, 'lmsCourseworkItems', 'lmsCourseworkItem');

        //write the DB dataset to a FILE
        if(($this->create_file($xdoc, $CFG->dataroot.'/coursework.xml')!=false)){
            $this->update_job_status(self::INTERNAL_NAME, apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS);
            return $xdoc;
        }else{
            $this->update_job_status(self::INTERNAL_NAME, apreport_job_stage::PERSIST, apreport_job_status::FAILURE, "error writing file");
            return false;
        }                    
    }


    /**
     * 
     * @param int $start unix timestamp
     * @param DateInterval $interval
     */
    public static function get_scorm_datesubmitted($start, $interval){
        $date = new DateTime(strftime('%F %T',$start));

        //remove microseconds...we don't care
        $int = new DateInterval(preg_replace('/\.[0-9]+S/', 'S', $interval));
        
        $end = $date->add($int);
        
        return $end->getTimestamp();
    }

    /**
     * 
     * @param string $msg
     * @param apreport_error_severity $sev
     */
    public function update_job_status_all($stage, $status, $info=null){
        foreach(self::$subreports as $type){
            $this->update_job_status(self::INTERNAL_NAME, $stage, $status, $info, $type);
        }
    }
    /**
     * 
     * @param string $msg
     * @param apreport_error_severity $sev
     */
    public function update_job_status_one($type,$stage, $status, $info=null){
        
            $this->update_job_status(self::INTERNAL_NAME, $stage, $status, $info, $type);
    }

    private function clean_db($itemtype){
        global $DB;
        return $DB->delete_records('apreport_coursework', array('itemtype'=>$itemtype));
    }
    
    public function persist_db_records($records) {
        global $DB;
        $ids =array();
        foreach($records as $rec){
            $rec->created = time();
            if($rec->gradecategory == '?')
                $rec->gradecategory = 'root';
            $ids[] = $DB->insert_record('apreport_coursework', $rec, true,true);
        }
        return count($ids);
    }

}

class coursework_queries{
    public static $queries = array('quiz' =>
        "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,                
                mm.id AS itemId,                
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'quiz' AS itemType,
                mm.name AS itemName,
                mm.timeclose AS dueDate,
                mma.timefinish AS dateSubmitted,
                mm.grade AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_quiz mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'quiz' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_quiz_attempts mma ON mm.id = mma.quiz AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = %d
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",
        
        'assign' =>
                    "SELECT
                        DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                        mma.id AS modAttemptId,
                        mm.id AS courseModuleId,
                        mgi.id AS gradeItemid,
                        mm.id AS itemId,
                        CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                        u.username as pawsId,
                        u.idnumber AS studentId,
                        CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                        us.sec_number AS sectionId,
                        'assign' AS itemType,
                        mm.name AS itemName,
                        mm.duedate AS dueDate,
                        mma.timemodified AS dateSubmitted,
                        mm.grade AS pointsPossible,
                        mgg.finalgrade AS pointsReceived,
                        mgc.fullname AS gradeCategory,
                        (cats.categoryWeight * 100) AS categoryWeight,
                        NULL AS extensions
                    FROM mdl_course c
                        INNER JOIN mdl_assign mm ON mm.course = c.id
                        INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                        INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                        INNER JOIN mdl_user u ON ustu.userid = u.id
                        INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                        INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                        INNER JOIN mdl_grade_items mgi ON
                            mgi.courseid = c.id AND
                            mgi.itemtype = 'mod' AND
                            mgi.itemmodule = 'assign' AND
                            mgi.iteminstance = mm.id
                        INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                        LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                        LEFT JOIN mdl_assign_submission mma ON mm.id = mma.assignment AND u.id = mma.userid
                        LEFT JOIN
                            (SELECT
                                mgi2.courseid AS catscourse,
                                mgi2.id AS catsid,
                                mgi2.iteminstance AS catcatid,
                                mgi2.aggregationcoef AS categoryWeight
                            FROM mdl_grade_items mgi2
                                INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                                AND mgi2.itemtype = 'category')
                            cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
                    WHERE c.id = '%d'",

        'assignment' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'assignment' AS itemType,
                mm.name AS itemName,
                mm.timedue AS dueDate,
                mma.timemodified AS dateSubmitted,
                mm.grade AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_assignment mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'assignment' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_assignment_submissions mma ON mm.id = mma.assignment AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",

        'database' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'database' AS itemType,
                mm.name AS itemName,
                mm.timeavailableto AS dueDate,
                mma.timemodified AS dateSubmitted,
                mm.scale AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_data mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'data' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_data_records mma ON mm.id = mma.dataid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",
        
        'forum' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'forum' AS itemType,
                mm.name AS itemName,
                mm.assesstimefinish AS dueDate,
                mmap.modified AS dateSubmitted,
                mm.scale AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_forum mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'forum' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                INNER JOIN mdl_forum_discussions mma ON mm.id = mma.forum
                LEFT JOIN mdl_forum_posts mmap ON mma.id = mmap.discussion AND u.id = mmap.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",
        
        'forumng' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'forumng' AS itemType,
                mm.name AS itemName,
                mm.ratinguntil AS dueDate,
                mmap.modified AS dateSubmitted,
                mm.ratingscale AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_forumng mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'forumng' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                INNER JOIN mdl_forumng_discussions mma ON mm.id = mma.forumngid
                LEFT JOIN mdl_forumng_posts mmap ON mma.id = mmap.discussionid AND u.id = mmap.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",
        
        'glossary' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'glossary' AS itemType,
                mm.name AS itemName,
                mm.assesstimefinish AS dueDate,
                mma.timemodified AS dateSubmitted,
                mm.scale AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_glossary mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'glossary' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_glossary_entries mma ON mm.id = mma.glossaryid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",

        'hotpot' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'hotpot' AS itemType,
                mm.name AS itemName,
                mm.timeclose AS dueDate,
                mma.timefinish AS dateSubmitted,
                mm.gradeweighting AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_hotpot mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'hotpot' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_hotpot_attempts mma ON mm.id = mma.hotpotid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",

        'kalvidassign' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'kalvidassign' AS itemType,
                mm.name AS itemName,
                mm.timedue AS dueDate,
                mma.timemodified AS dateSubmitted,
                mm.grade AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_kalvidassign mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'kalvidassign' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_kalvidassign_submission mma ON mm.id = mma.vidassignid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                        AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
            WHERE c.id = '%d'",
        
        'lesson' =>
            "SELECT
                DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
                mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
                u.username as pawsId,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                us.sec_number AS sectionId,
                'lesson' AS itemType,
                mm.name AS itemName,
                mm.deadline AS dueDate,
                mma.timeseen AS dateSubmitted,
                mm.grade AS pointsPossible,
                mgg.finalgrade AS pointsReceived,
                mgc.fullname AS gradeCategory,
                (cats.categoryWeight * 100) AS categoryWeight,
                NULL AS extensions
            FROM mdl_course c
                INNER JOIN mdl_lesson mm ON mm.course = c.id
                INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
                INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN mdl_user u ON ustu.userid = u.id
                INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
                INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
                INNER JOIN mdl_grade_items mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'lesson' AND
                    mgi.iteminstance = mm.id
                INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN mdl_lesson_attempts mma ON mm.id = mma.lessonid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM mdl_grade_items mgi2
                        INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
                    AND mgi2.itemtype = 'category')
                    cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
                    WHERE c.id = '%d'",
        
        'scorm' =>
"SELECT
    DISTINCT(CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number,mm.id,'00000000',(IFNULL(mma.id, '0')))) AS uniqueId,
    CONCAT(u.idnumber, (IFNULL(mma.scoid,''))) AS modAttemptId,
            #    mma.id AS modAttemptId,
                mm.id AS courseModuleId,
                mgi.id AS gradeItemid,
                mm.id AS itemId,
    CONCAT(usem.year,u.idnumber,LPAD(c.id,5,'0'),us.sec_number) AS enrollmentId,
    u.username as pawsId,
    u.idnumber AS studentId,
    CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
    us.sec_number AS sectionId,
    'scorm' AS itemType,
    mm.name AS itemName,
    mm.timeclose AS dueDate,
    mma.timemodified AS dateStarted,
    mma1.value AS timeElapsed,
    mm.maxgrade AS pointsPossible,
    mgg.finalgrade AS pointsReceived,
    mgc.fullname AS gradeCategory,
    (cats.categoryWeight * 100) AS categoryWeight,
    NULL AS extensions
FROM mdl_course c
    INNER JOIN mdl_scorm mm ON mm.course = c.id
    INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
    INNER JOIN mdl_enrol_ues_students ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
    INNER JOIN mdl_user u ON ustu.userid = u.id
    INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
    INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
    INNER JOIN mdl_grade_items mgi ON
        mgi.courseid = c.id AND
        mgi.itemtype = 'mod' AND
        mgi.itemmodule = 'scorm' AND
        mgi.iteminstance = mm.id
    INNER JOIN mdl_grade_categories mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
    INNER JOIN mdl_scorm_scoes mms ON mm.id = mms.scorm
    LEFT JOIN mdl_grade_grades mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
    LEFT JOIN mdl_scorm_scoes_track mma ON mm.id = mma.scormid AND u.id = mma.userid AND mma.scoid = mms.id AND mma.element = 'cmi.score.raw'
    LEFT JOIN mdl_scorm_scoes_track mma1 ON mm.id = mma1.scormid AND u.id = mma1.userid AND mma1.scoid = mms.id AND mma1.element = 'cmi.total_time'
    LEFT JOIN mdl_scorm_scoes_track mma2 ON mm.id = mma2.scormid AND u.id = mma2.userid AND mma2.scoid = mms.id AND mma2.element = 'x.start.time'
    LEFT JOIN
        (SELECT
            mgi2.courseid AS catscourse,
            mgi2.id AS catsid,
            mgi2.iteminstance AS catcatid,
            mgi2.aggregationcoef AS categoryWeight
        FROM mdl_grade_items mgi2
            INNER JOIN mdl_grade_categories mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
            AND mgi2.itemtype = 'category')
        cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
WHERE c.id = '%d'
GROUP BY modAttemptId",
        
        
        );
}

?>
