<?php

require_once(dirname(__FILE__) . '/../../config.php');
require_once("$CFG->libdir/externallib.php");
require_once('classes/apreport.php');
require_once('classes/dbal.php');
require_once('classes/enrollment.php');


$_s = function($key,$a=null) {
    return get_string($key, 'local_ap_report', $a);
};


function local_ap_report_cron(){
    global $CFG;
    if($CFG->apreport_with_cron != 1){
        return true;
    }
    $current_hour = (int)date('H');
    $now = new DateTime();
    $begin_current_hour = strtotime($now->format('Y-m-d H'));

    $acceptable_hour = (int)$CFG->apreport_daily_run_time_h;

    $reports = array('lmsEnrollment','lmsGroupMembership', 'lmsSectionGroup','lmsCoursework');

    if(($current_hour == $acceptable_hour) and (time()-$begin_current_hour < 1800)){
        foreach($reports as $r){
            mtrace("Begin {$r} report...");
            $report = new $r();

            if($r == 'lmsEnrollment'){
                mtrace(sprintf("Getting activity statistics for time range: %s -> %s",
                    strftime('%F %T',$report->start),
                    strftime('%F %T',$report->end)
                    ));
            }
            $report->run();

            add_to_log(1, $r, 'cron');
            mtrace("done.");
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
    public static function create_file($contents)  {
        
        list($path,$filename) = static::get_filepath();
        if(!is_dir($path)){
            if(!mkdir($path, 0744, true)){
                return false;
            }
        }
        $file = $path.$filename;
        
        $contents->formatOutput = true;
        $handle = fopen($file, 'w');
        assert($handle !=false);
        $success = fwrite($handle, $contents->saveXML());
        fclose($handle);
        if(!$success){
            add_to_log(1, 'ap_reports', sprintf('error writing to filesystem at %s', $file));
            return false;
        }
        return true;
   
    }
    
    public static function get_filepath(){
        global $CFG;
        $dir = isset($CFG->apreport_dir_path) ? $CFG->apreport_dir_path : 'apreport';
        $filepath = $CFG->dataroot.DIRECTORY_SEPARATOR.$dir;
        return array($filepath.DIRECTORY_SEPARATOR,static::INTERNAL_NAME.'.xml');
    }
    
    /**
     * @param apreport_status $stat
     */
    public static function update_job_status($stage, $status, $info=null, $sub=null) {

        $subcomp  = isset($sub) ? '_'.$sub  : null;
        $info     = isset($info)? '  : '.$info : null;
        set_config('apreport_'.static::INTERNAL_NAME.$subcomp, $stage.':  '.$status.$info);
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
    const SAVE_XML   = 'Save XML';
    const COMPLETE   = 'done';
    const ABORT      = 'aborted';
}



class lmsE extends apreport{
//    public $enrollment;
    const INTERNAL_NAME = 'lmsEnrollment';
    
    public $logs;
    public $timespent_records;
    
    public $all_enrolled_users;
    public $active_users;
    public $inactive_users;
    public $previously_active_users;
    
    
    public $active_enrollments;
    
    public function __construct($mode=null){
        list($this->start, $this->end) = apreport_util::get_day_span(strftime('%F %T',(int)time()-86400));
        switch($mode){
            case 'preview':
                $this->start+= 86400;
                $this->end  += 86400;
                break;
            default:
                null;
                break;
        }
        //@TODO allow user to specify
        $this->filename          = '/enrollment.xml';
        
        $this->timespent_records = $this->getPriorRecords();
    }
    
    public function getEnrollment(){
        global $DB;
        $sql = "
            SELECT
                CONCAT(usem.year,u.idnumber,LPAD(c.id,8,'0'),usect.sec_number) AS enrollmentId,
                u.id AS uid,
                c.id AS cid,
                u.idnumber AS studentId,
                CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                usect.sec_number AS sectionId,
                usem.id AS usemid,
                usem.classes_start AS startDate,
                usem.grades_due AS endDate,
                usect.id as usectid,
                'A' AS status
            FROM {course} AS c
                INNER JOIN {context} AS ctx ON c.id = ctx.instanceid
                INNER JOIN {role_assignments} AS ra ON ra.contextid = ctx.id
                INNER JOIN {user} AS u ON u.id = ra.userid
                INNER JOIN {enrol_ues_sections} usect ON c.idnumber = usect.idnumber
                INNER JOIN {enrol_ues_students} ustu ON u.id = ustu.userid AND usect.id = ustu.sectionid
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = usect.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = usect.courseid
            WHERE ra.roleid IN (5)
                AND usem.classes_start < UNIX_TIMESTAMP(NOW())
                AND usem.grades_due > UNIX_TIMESTAMP(NOW())
                AND ustu.status = 'enrolled'
            GROUP BY enrollmentId
            ";
        
        $flat = $DB->get_records_sql($sql);
        
        $enrollments = array();
        foreach($flat as $f){

            if(!isset($enrollments[$f->uid.'-'.$f->cid])){
                $enrollments[$f->uid.'-'.$f->cid] = lmsEnrollmentRecord::instantiate($f);
            }
 
        }
        return $this->all_enrolled_users = $enrollments;
    }
    
    
    public function getLogs(){
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
            GROUP BY logid
            ORDER BY sectionid, log.time ASC
            ;"
                ,array($this->start, $this->end));
        $logs = $DB->get_records_sql($sql);
        
        $ulogs =array();
        
        foreach($logs as $log){
            
            
            if(!isset($ulogs[$log->userid.'-'.$log->course])){
                $ulogs[$log->userid.'-'.$log->course] = array();
            }
            
            $ulogs[$log->userid.'-'.$log->course][] = $log;
            
        }
        
        return $this->logs = $ulogs;
    }
    
    public function getPriorRecords(){
        global $DB;
        $sql = "select 
                    distinct CONCAT(ap.uid, ap.usectid) AS uniq, 
                    ap.uid, 
                    c.id 
                    from 
                        mdl_apreport_enrol ap 
                            INNER JOIN mdl_enrol_ues_sections usect on usect.id = ap.usectid 
                            INNER JOIN mdl_course c ON c.idnumber = usect.idnumber;";
        
        $enrlmnts = $DB->get_records_sql($sql);
        $priors = array();
        foreach($enrlmnts as $e){
            $priors[$e->uid.'-'.$e->id] = $e;
        }
        return $priors;
    }
    

    public function processUsers($all, $logs, $priors){
        $r = array();

        $needCalc     = array_intersect_key($all, $logs);
        $onRecord     = array_intersect_key($all, $priors);
        $needZeros    = array_diff_key($all, $onRecord, $needCalc);
        
        //set timespent to 0 for ach of these users
        array_walk($needZeros, function($a){
            $a->timespentinclass = 0;
        });
        $r += $needZeros;
        
        //calculate each user only once
        //get a list of user ids
        //send all the logs records with
        //keys that start with the user's id
        $needCalcKeys = array_keys($needCalc);
        $uids = array_map(function($a){
                $out = preg_split('/-/', $a);
                return $out[0];
            }, $needCalcKeys);

        foreach($uids as $uid){
            $r = array_merge($r,$this->calculate_timespent($uid));
        }
        
        
        return $r;
    }

    public function db_save_records($rows) {
        global $DB;
        foreach($rows as $row){
            $row->timestamp = time();
            $DB->insert_record('apreport_enrol',$row,false,true);
        }
        
    }

    public function get_db_records($s,$e){
        global $DB;
        $sql = sprintf("
            select 
                CONCAT(ap.uid,'-',ap.usectid) uniq,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,8,'0'),usect.sec_number) AS enrollmentId,
                ap.id, 
                ap.uid, 
                ap.usectid, 
                ap.usemid, 
                ap.timespentinclass, 
                ap.lastcourseaccess, 
                ap.timestamp, 
                u.idnumber studentid, 
                usect.sec_number sectionid, 
                CONCAT(RPAD(ucrs.department,4,' '),'  ',ucrs.cou_number) AS courseId,
                usem.classes_start AS startDate,
                usem.grades_due AS endDate,'A' AS status

                FROM 
                mdl_apreport_enrol ap 
                INNER JOIN 
                        (
                        SELECT max(timestamp) timestamp, usectid, uid 
                        FROM mdl_apreport_enrol 
                        GROUP BY usectid,uid
                        ) latest 
                USING(timestamp,usectid,uid)
                LEFT JOIN mdl_enrol_ues_sections usect ON usect.id = ap.usectid 
                LEFT JOIN mdl_user u ON ap.uid = u.id 
                LEFT JOIN mdl_enrol_ues_courses ucrs ON ucrs.id = usect.courseid 
                LEFT JOIN mdl_enrol_ues_semesters usem ON ap.usemid = usem.id
                LEFT JOIN mdl_course c on c.idnumber = usect.idnumber
                
                WHERE ap.lastcourseaccess > %s AND ap.lastcourseaccess < %s;",$s,$e);
        return $DB->get_records_sql($sql);
        
    }

    public function get_db_sums($s,$e){
        global $DB;
        $sql = sprintf("
            SELECT 
                CONCAT(ap.uid,'-',ap.usectid) uniq,
                sum(ap.timespentinclass) time
            FROM
                mdl_apreport_enrol ap
            WHERE ap.lastcourseaccess > %s AND ap.lastcourseaccess < %s
            GROUP BY
                ap.uid,ap.usectid",$s,$e);
        return $DB->get_records_sql($sql);
    }
    
    public function get_report($s=null,$e=null){
        if(is_null($s) and is_null($e)){
            $s = $this->start;
            $e = $this->end;
        }
        $sums = $this->get_db_sums($s,$e);
        $rows = $this->get_db_records($s,$e);
        $out  = array();
        
        foreach($rows as $k=>$v){
            $o = lmsEnrollmentRecord::instantiate($v);
            $o->timespentinclass = $sums[$k]->time;
            $out[] = $o;
        }
        return $out;
    }
    
    public function calculate_timespent($user){

        $activity   = array();
        $keys       = array_keys($this->logs);
        $ulogs      = preg_grep("/{$user}\-[0-9]+/", $keys);
        
        //make one array containing log row objects for all of the student's courses
        foreach($ulogs as $i){
            $activity = array_merge($activity,$this->logs[$i]);
        }
        
        $current = null;        //current course
        $out     = array();     //hold the records for the db
        
        ksort($activity);
        foreach($activity as $a){

            if($a->course == 1){
                if($a->action != 'login'){
                    continue;
                }else{
                    foreach(array_values($ulogs) as $ac){
                        if(array_key_exists($ac,$out) && isset($out[$ac]->lastcounter)){
                            unset($out[$ac]->lastcounter);
                        }
                    }
                }
            }
            $k = $a->userid.'-'.$a->course;
            //get objects into place
            if(!array_key_exists($k, $out) and $a->course != 1){
                $out[$k] = $this->all_enrolled_users[$k];
            }

            if($a->action != 'login'){
                if(isset($current) && $current != $k){ //switching
                   if( isset($out[$current]->lastcounter)){
                       unset($out[$current]->lastcounter);
                   }
                }
                //set to the new/current course
                $current  = $k;
                
                
                //first visit to the course for ths timespan
                if(!isset($out[$current]->timespentinclass)){
                    $out[$current]->timespentinclass = 0;
                }else{
                    $out[$current]->timespentinclass += $a->time - $out[$current]->lastcounter;
                }
                //set the pickup markers for the next iteration with this course
                $out[$current]->lastcounter = $out[$current]->lastcourseaccess = $a->time;
            }
            
        }

        
        return $out;
        
        
    }
    /**
     * 
     * @param tbl_model[] $records
     * @param string $root_name the name that the inheriting report uses as its XML root element
     * @param string $child_name name that the inheriting report uses as child container element
     * @return DOMDocument Description
     */
    public static function toXMLDoc($records, $root_name, $child_name){
        $xdoc = new DOMDocument();
        $root = $xdoc->createElement($root_name);
        $root->setAttribute('university', '002010');

        if(empty($records)){
            return false;
        }

        foreach($records as $record){
            $camel = self::camelize($record);

            $elemt = $xdoc->importNode(static::toXMLElement($camel,$child_name),true);
            $root->appendChild($elemt);
        }
        $xdoc->appendChild($root);
        return $xdoc;
    }
    
    public function drop_existing_for_timerange(){
        global $DB;
        $select = sprintf("lastcourseaccess > %s AND lastcourseaccess < %s;",$this->start, $this->end);
        $DB->delete_records_select('apreport_enrol', $select);
    }
    
    public function run(){
        $this->drop_existing_for_timerange();
        $newRecs = $this->processUsers(
                        $this->getEnrollment(), 
                        $this->getLogs(),
                        $this->getPriorRecords()
                    );
        //save to db
        $this->db_save_records($newRecs);
        
        //get all recs
        $rep = $this->get_report();
        $xdoc = lmsEnrollmentRecord::toXMLDoc($rep,'lmsEnrollments', 'lmsEnrollment');
        self::create_file($xdoc);
        return $xdoc;
    }
    
    public function get_span_report($span){
        switch ($span){
            case 'yesterday':
                $s = apreport::get_day_span(time()-86400);
                break;
        }
        $this->start = $s;
        $this->end   = $e;
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
    
    const INTERNAL_NAME = 'lmsGroupMembership';
    
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
    
    
    /**
     * main call point from cron, etc;
     * wraps main methods
     * @TODO additional conditionals to trap errors
     * @global type $CFG
     * @return boolean
     */
    public function run(){

        self::update_job_status(apreport_job_stage::INIT, apreport_job_status::SUCCESS,apreport_util::microtime_toString(microtime()));
        
        $this->enrollment->get_group_membership_report();
        self::update_job_status(apreport_job_stage::QUERY, apreport_job_status::SUCCESS);
        
        $content = $this->getXML();
        $content->format = true;
        if(!self::create_file($content)){
            self::update_job_status(apreport_job_stage::SAVE_XML, apreport_job_status::FAILURE);
            return false;
        }else{
            list($path,$file) = self::get_filepath();
            assert(file_exists($path.$file));
            self::update_job_status(apreport_job_stage::SAVE_XML, apreport_job_status::SUCCESS);
        }
        self::update_job_status(apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS,apreport_util::microtime_toString(microtime()));
        return $content;
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
    const INTERNAL_NAME = 'lmsSectionGroup';
    
    public function __construct($e = null){
        $this->enrollment = (isset($e) and get_class($e) == 'enrollment_model') ? $e : new enrollment_model();
    }
    
    public function run(){
        global $CFG;
        self::update_job_status(apreport_job_stage::INIT, apreport_job_status::SUCCESS, apreport_util::microtime_toString(microtime()));
        $xdoc = lmsSectionGroupRecord::toXMLDoc($this->get_section_groups(), 'lmsSectionGroups', 'lmsSectionGroup');
        if(($xdoc)!=false){
            self::update_job_status(apreport_job_stage::QUERY, apreport_job_status::SUCCESS);
            if(self::create_file($xdoc)!=false){
                self::update_job_status(apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS, apreport_util::microtime_toString(microtime()));
                return $xdoc;
            }
        }
        self::update_job_status(apreport_job_stage::ABORT, apreport_job_status::EXCEPTION,apreport_util::microtime_toString(microtime()));
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
        $this->update_job_status_all(apreport_job_stage::INIT, apreport_job_status::SUCCESS, apreport_util::microtime_toString(microtime()));
        if(empty($this->courses)){
            //this could happen on a day where there are zero semesters in session
            $this->set_status();
            $this->update_job_status_all(apreport_job_stage::ABORT, apreport_job_status::EXCEPTION, 'no courses');
            return true;
        }
        $enr = new enrollment_model();

        //get records, one report at a time with completion status
        foreach(coursework_queries::$queries as $type => $query){
            $records[$type] = array();
            $records = $this->coursework_get_subreport_dataset($this->courses, $query, $type);

            if(count($records)<1){
                self::update_job_status(apreport_job_stage::ABORT, apreport_job_status::EXCEPTION, "empty resultset",$type);

            }else{
                self::update_job_status(apreport_job_stage::QUERY, apreport_job_status::SUCCESS,null,$type);

                //save to db
                if($this->clean_db($type)){
                    $persist_success = $this->persist_db_records($records,$type);
                }
                if($persist_success > 0){
                    self::update_job_status(apreport_job_stage::PERSIST, apreport_job_status::SUCCESS,null,$type);
                }else{
                    self::update_job_status(apreport_job_stage::PERSIST, apreport_job_status::FAILURE,null,$type);
                    continue;
                }
                self::update_job_status(apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS, null,$type);
            }
        }
        //set status message about the loop exit
        self::update_job_status(apreport_job_stage::QUERY, apreport_job_status::SUCCESS);

        //read back from db
        $dataset = $DB->get_records('apreport_coursework');
        if(!empty($dataset)){
            self::update_job_status(apreport_job_stage::RETRIEVE, apreport_job_status::SUCCESS);
        }else{
            self::update_job_status(apreport_job_stage::RETRIEVE, apreport_job_status::EXCEPTION, "no rows");
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
        if((self::create_file($xdoc)!=false)){
            self::update_job_status(apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS, apreport_util::microtime_toString(microtime()));
            return $xdoc;
        }else{
            self::update_job_status(apreport_job_stage::PERSIST, apreport_job_status::FAILURE, "error writing file");
            return false;
        }                    
    }


    /**
     * compute an ending timestamp given a 
     * starting TS + a standard DateInterval
     * @param int $start unix timestamp
     * @param DateInterval $interval
     */
    public static function get_scorm_datesubmitted($start, $interval){
        $date = new DateTime(strftime('%F %T',$start));

        //remove microseconds...we don't care; php can't hand the microseconds
        $int = new DateInterval(preg_replace('/\.[0-9]+S/', 'S', $interval));
        
        $end = $date->add($int);
        
        return $end->getTimestamp();
    }

    /**
     * store status infomation in $CFG
     * @param apreport_job_stage $stage
     * @param apreport_job_status $status
     * @param string $info
     */
    public function update_job_status_all($stage, $status, $info=null){
        foreach(self::$subreports as $type){
            self::update_job_status($stage, $status, $info, $type);
        }
    }
//    /**
//     * 
//     * @param string $msg
//     * @param apreport_error_severity $sev
//     */
//    public function update_job_status_one($type,$stage, $status, $info=null){
//        
//            self::update_job_status(self::INTERNAL_NAME, $stage, $status, $info, $type);
//    }

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
            FROM {course} c
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {quiz} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'quiz' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {quiz_attempts} mma ON mm.id = mma.quiz AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = %d
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
                    FROM {course} c
                        INNER JOIN {assign} mm ON mm.course = c.id
                        INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                        INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                        INNER JOIN {user} u ON ustu.userid = u.id
                        INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                        INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                        INNER JOIN {grade_items} mgi ON
                            mgi.courseid = c.id AND
                            mgi.itemtype = 'mod' AND
                            mgi.itemmodule = 'assign' AND
                            mgi.iteminstance = mm.id
                        INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                        LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                        LEFT JOIN {assign_submission} mma ON mm.id = mma.assignment AND u.id = mma.userid
                        LEFT JOIN
                            (SELECT
                                mgi2.courseid AS catscourse,
                                mgi2.id AS catsid,
                                mgi2.iteminstance AS catcatid,
                                mgi2.aggregationcoef AS categoryWeight
                            FROM {grade_items} mgi2
                                INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {assignment} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'assignment' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {assignment_submissions} mma ON mm.id = mma.assignment AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {data} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'data' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {data_records} mma ON mm.id = mma.dataid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {forum} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'forum' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                INNER JOIN {forum_discussions} mma ON mm.id = mma.forum
                LEFT JOIN {forum_posts} mmap ON mma.id = mmap.discussion AND u.id = mmap.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {forumng} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'forumng' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                INNER JOIN {forumng_discussions} mma ON mm.id = mma.forumngid
                LEFT JOIN {forumng_posts} mmap ON mma.id = mmap.discussionid AND u.id = mmap.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {glossary} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'glossary' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {glossary_entries} mma ON mm.id = mma.glossaryid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {hotpot} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'hotpot' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {hotpot_attempts} mma ON mm.id = mma.hotpotid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {kalvidassign} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'kalvidassign' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {kalvidassign_submission} mma ON mm.id = mma.vidassignid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
            FROM {course} c
                INNER JOIN {lesson} mm ON mm.course = c.id
                INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
                INNER JOIN {user} u ON ustu.userid = u.id
                INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
                INNER JOIN {grade_items} mgi ON
                    mgi.courseid = c.id AND
                    mgi.itemtype = 'mod' AND
                    mgi.itemmodule = 'lesson' AND
                    mgi.iteminstance = mm.id
                INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
                LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
                LEFT JOIN {lesson_attempts} mma ON mm.id = mma.lessonid AND u.id = mma.userid
                LEFT JOIN
                    (SELECT
                        mgi2.courseid AS catscourse,
                        mgi2.id AS catsid,
                        mgi2.iteminstance AS catcatid,
                        mgi2.aggregationcoef AS categoryWeight
                    FROM {grade_items} mgi2
                        INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
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
FROM {course} c
    INNER JOIN {scorm} mm ON mm.course = c.id
    INNER JOIN {enrol_ues_sections} us ON c.idnumber = us.idnumber
    INNER JOIN {enrol_ues_students} ustu ON ustu.sectionid = us.id AND ustu.status = 'enrolled'
    INNER JOIN {user} u ON ustu.userid = u.id
    INNER JOIN {enrol_ues_semesters} usem ON usem.id = us.semesterid
    INNER JOIN {enrol_ues_courses} uc ON uc.id = us.courseid
    INNER JOIN {grade_items} mgi ON
        mgi.courseid = c.id AND
        mgi.itemtype = 'mod' AND
        mgi.itemmodule = 'scorm' AND
        mgi.iteminstance = mm.id
    INNER JOIN {grade_categories} mgc ON (mgc.id = mgi.iteminstance OR mgc.id = mgi.categoryid) AND mgc.courseid = c.id
    INNER JOIN {scorm_scoes} mms ON mm.id = mms.scorm
    LEFT JOIN {grade_grades} mgg ON mgi.id = mgg.itemid AND mgg.userid = u.id
    LEFT JOIN {scorm_scoes_track} mma ON mm.id = mma.scormid AND u.id = mma.userid AND mma.scoid = mms.id AND mma.element = 'cmi.score.raw'
    LEFT JOIN {scorm_scoes_track} mma1 ON mm.id = mma1.scormid AND u.id = mma1.userid AND mma1.scoid = mms.id AND mma1.element = 'cmi.total_time'
    LEFT JOIN {scorm_scoes_track} mma2 ON mm.id = mma2.scormid AND u.id = mma2.userid AND mma2.scoid = mms.id AND mma2.element = 'x.start.time'
    LEFT JOIN
        (SELECT
            mgi2.courseid AS catscourse,
            mgi2.id AS catsid,
            mgi2.iteminstance AS catcatid,
            mgi2.aggregationcoef AS categoryWeight
        FROM {grade_items} mgi2
            INNER JOIN {grade_categories} mgc2 ON mgc2.id = mgi2.iteminstance AND mgc2.courseid = '%d'
            AND mgi2.itemtype = 'category')
        cats ON cats.catscourse = c.id AND mgc.id = cats.catcatid
WHERE c.id = '%d'
GROUP BY modAttemptId",
        
        
        );
}

?>
