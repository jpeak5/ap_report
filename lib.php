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
    
    $today = new DateTime('today');
    $acceptable_hour = (int)$CFG->apreport_daily_run_time_h;
    $begin_acceptable_hour = $today->getTimestamp() + $acceptable_hour*3600;
    $end_acceptable_hour = $begin_acceptable_hour + 3600; 
    

    $reports = array('lmsEnrollment','lmsGroupMembership', 'lmsSectionGroup','lmsCoursework');

    if($begin_acceptable_hour < time() && $end_acceptable_hour > time()){
        $args = null;
        foreach($reports as $r){
            mtrace("Begin {$r} report...");
            $report = new $r();

            if($r == 'lmsEnrollment'){
                $timeStart = strftime('%F %T',$report->report_start);
                $timeEnd   = strftime('%F %T',$report->report_end);
                mtrace(sprintf("Getting activity statistics for time range: %s -> %s",
                    $timeStart,
                    $timeEnd
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
        if(empty($contents)){
            return false;
        }
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



class lmsEnrollment extends apreport{
//    public $enrollment;
    const INTERNAL_NAME = 'lmsEnrollment';
    
    public $logs;

    
    public $all_enrolled_users;
    public $active_users;
    public $inactive_users;
    public $previously_active_users;
    public $mode;
    public $report_start;
    public $report_end;
    public $proc_start;
    public $proc_end;
    public $active_enrollments;
    public $earliest_active_semester_start;
    static $proc_modes = array('cron', 'reprocess', 'preview','backfill');
    static $view_modes = array('view_current', 'view_latest');
    
    public function __construct($mode='cron'){
        $this->mode = $mode;

        lmsEnrollment::update_job_status(apreport_job_stage::BEGIN, apreport_job_status::SUCCESS, $this->mode);
        $this->earliest_active_semester_start = apreport_util::get_earliest_semester_start();

        if(in_array($mode,self::$proc_modes)){
            if($this->mode =='preview'){
                $d = new DateTime('today');
                $this->proc_start = $this->report_start = $d->getTimestamp();
            }elseif($this->mode == 'reprocess' || $this->mode == 'cron'){
                $d = new DateTime('yesterday');
                $this->proc_start = $d->getTimestamp();
            }

            $this->proc_end   = $this->proc_start + 86400;

            $this->report_start = $this->earliest_active_semester_start;
            $this->report_end = $this->proc_end;
            //@TODO allow user to specify
        }elseif(in_array($mode, self::$view_modes)){
            $this->report_start = $this->earliest_active_semester_start;
            $when = $mode == 'view_current' ? 'today' : 'tomorrow';
            $end  = new DateTime($when);
            $this->report_end = $end->getTimestamp();
        }
        $this->filename = '/enrollment.xml';
        lmsEnrollment::update_job_status(apreport_job_stage::INIT, apreport_job_status::SUCCESS, $this->mode);        
    }
    
    public function getEnrollment(){
        
        global $DB;
        $sql = sprintf("
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
                AND usem.classes_start < %s
                AND usem.grades_due > %s
                AND ustu.status = 'enrolled'
            GROUP BY enrollmentId
            ",$this->proc_start, $this->proc_start);
        
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
               ,log.timecreated
               ,log.userid
               ,log.courseid
               ,log.action
            FROM 
                {enrol_ues_sections} usect
            LEFT JOIN
                {course} course ON course.idnumber = usect.idnumber
            LEFT JOIN
                {logstore_standard_log} log on course.id = log.courseid
            WHERE
                log.timecreated > %s
                AND
                log.timecreated < %s AND (log.courseid > 1 OR log.action = 'loggedin')
            GROUP BY logid
            ORDER BY sectionid, log.timecreated ASC
            ;"
                ,array($this->proc_start, $this->proc_end));
        $logs = $DB->get_records_sql($sql);
        
        $ulogs =array();
        
        foreach($logs as $log){
            if(!isset($ulogs[$log->userid.'-'.$log->courseid])){
                $ulogs[$log->userid.'-'.$log->courseid] = array();
            }

            $ulogs[$log->userid.'-'.$log->courseid][] = $log;
        }
        
        return $this->logs = $ulogs;
    }
    
    /**
     * Find the set of records in the apreport_enrol table that contains uniqe
     * entries for userid + sectionid
     * @global object $DB
     * @return stdClass[] array of result objects, one for each unique
     * user/sectionid combination; any user that has viewed a course will be
     * represented here.
     */
    public function getPriorRecords(){
        
        global $DB;
        $sql = "SELECT
                    DISTINCT CONCAT(ap.uid, ap.usectid) AS uniq,
                    ap.uid AS userid,
                    c.id AS courseid
                    FROM
                        {apreport_enrol} AS ap
                            INNER JOIN {enrol_ues_sections} AS usect on usect.id = ap.usectid
                            INNER JOIN {course} AS c ON c.idnumber = usect.idnumber;";

        $enrlmnts = $DB->get_records_sql($sql);
        $priors = array();
        foreach($enrlmnts as $e){
            //each element is gauranteed unique by the DISTINCT query
            $priors[$e->userid.'-'.$e->courseid] = $e;
        }
        return $priors;
    }
    
    public function db_save_records($rows) {
        global $DB;
        foreach($rows as $row){
            $row->timestamp = time();
            $DB->insert_record('apreport_enrol',$row,false,true);
        }
        
    }

    /**
     * monster query that returns rich lmsEnrollment records
     * @global object $DB
     * @return object[] db result
     */
    public function get_db_records(){
        
        global $DB;
        $sql = sprintf("
            SELECT
                CONCAT(ap.uid,'-',ap.usectid) AS uniq,
                CONCAT(usem.year,u.idnumber,LPAD(c.id,8,'0'),usect.sec_number) AS enrollmentId,
                ap.id, 
                ap.uid, 
                ap.usectid, 
                ap.usemid, 
                ap.timespentinclass, 
                ap.lastcourseaccess, 
                ap.timestamp,
                u.idnumber AS studentid,
                usect.sec_number AS sectionid,
                CONCAT(RPAD(ucrs.department,4,' '),'  ',ucrs.cou_number) AS courseId,
                usem.classes_start AS startDate,
                usem.grades_due AS endDate,'A' AS status

            FROM {apreport_enrol} AS ap
                INNER JOIN 
                        (
                            SELECT max(timestamp) timestamp, usectid, uid
                            FROM {apreport_enrol}
                            WHERE lastcourseaccess < %s OR lastcourseaccess IS NULL
                            GROUP BY usectid,uid
                        ) AS latest
                USING(timestamp,usectid,uid)
                    LEFT JOIN {enrol_ues_sections} AS usect ON usect.id = ap.usectid
                    LEFT JOIN {user} AS u ON ap.uid = u.id
                    LEFT JOIN {enrol_ues_courses} AS ucrs ON ucrs.id = usect.courseid
                    LEFT JOIN {enrol_ues_semesters} AS usem ON ap.usemid = usem.id
                    LEFT JOIN {course} AS c on c.idnumber = usect.idnumber
                WHERE   (
                            ap.lastcourseaccess > %s
                            AND
                            ap.lastcourseaccess < %s
                        )
                        OR
                        ap.lastcourseaccess IS NULL;",
                $this->report_end,
                $this->report_start,
                $this->report_end
            );
        $recs = $DB->get_records_sql($sql);

        return $recs;
    }

    public function get_db_sums(){
        
        global $DB;
        $sql = sprintf("
            SELECT 
                CONCAT(ap.uid,'-',ap.usectid) AS uniq,
                sum(ap.timespentinclass) AS time
            FROM
                {apreport_enrol} AS ap
            WHERE   (
                        ap.lastcourseaccess > %s
                        AND
                        ap.lastcourseaccess < %s
                    )
                    OR
                    ap.lastcourseaccess IS NULL
            GROUP BY
                ap.uid,ap.usectid",
                $this->report_start,
                $this->report_end
                );
        return $DB->get_records_sql($sql);
    }

    public function fetchUserLogsKeysByUserid($keyUserId){
        return preg_grep("/{$keyUserId}\-[0-9]+/", array_keys($this->logs));
    }

    public function fetchUserLogsByUserid($keyUserId){
        $arrUserLogs = array();

        //make one array containing log row objects for all of the student's courses
        foreach($this->fetchUserLogsKeysByUserid($keyUserId) as $keyUserLogs){
            $arrUserLogs = array_merge($arrUserLogs,$this->logs[$keyUserLogs]);
        }

        //sort by time
        usort($arrUserLogs,function($a,$b){
            if($a->timecreated == $b->timecreated){
                return 0;
            }else{
                return $a->timecreated < $b->timecreated ? -1 : 1;
            }
        });
        return $arrUserLogs;
    }

    public function calculate_timespent($keyUserId){
        $keyActiveUserCourse    = null;
        $arrOutput              = array();

        //build summary report records per user/course
        $arrUserLogs = $this->fetchUserLogsByUserid($keyUserId);
        foreach($arrUserLogs as $objLog){

            $keyCurrentUserCourse       = $objLog->userid.'-'.$objLog->courseid;
            $blnToIgnore                = $objLog->courseid == 1 && $objLog->action  !== "loggedin";
            $blnIsLogin                 = $objLog->courseid == 1 && $objLog->action  == 'loggedin';
            $blnIsNewSequence           = !$blnIsLogin && !isset($keyActiveUserCourse);
            $blnIsSessionContinuation   = !$blnIsLogin &&  isset($keyActiveUserCourse);


            //handle login events; throw away other course=1 events
            if($blnIsLogin || $blnToIgnore){
                if($blnToIgnore){
                    continue; //should rarely happen
                }
                $arrUserLogsKeys = $this->fetchUserLogsKeysByUserid($keyUserId);
                foreach($arrUserLogsKeys as $keyUserLogs){
                    if(array_key_exists($keyUserLogs,$arrOutput) && isset($arrOutput[$keyUserLogs]->lastcounter)){
                        unset($arrOutput[$keyUserLogs]->lastcounter);
                    }
                }
                unset($keyActiveUserCourse);
                continue;
            }elseif(!array_key_exists($keyCurrentUserCourse, $arrOutput)){
                if(array_key_exists($keyCurrentUserCourse, $this->all_enrolled_users)){
                    $arrOutput[$keyCurrentUserCourse] = $this->all_enrolled_users[$keyCurrentUserCourse];
                    $arrOutput[$keyCurrentUserCourse]->timespentinclass = 0;
                }else{
                    //user has log activity in a course where (s)he is not enrolled
                    continue; //@TODO catch this further up the chain
                }
            }
            
            if($blnIsNewSequence){
                $keyActiveUserCourse = $keyCurrentUserCourse;
                if(!isset($arrOutput[$keyActiveUserCourse]->timespentinclass)){
                    $arrOutput[$keyActiveUserCourse]->timespentinclass = 0;
                }
            }
            elseif($blnIsSessionContinuation){
                if($keyActiveUserCourse == $keyCurrentUserCourse){ //same sequence
                    $arrOutput[$keyActiveUserCourse]->timespentinclass += $objLog->timecreated - $arrOutput[$keyActiveUserCourse]->lastcounter;
                }else{
                    unset($arrOutput[$keyActiveUserCourse]->lastcounter);
                $keyActiveUserCourse  = $keyCurrentUserCourse;
                }
            }
            else{
                Throw new Exception("Unhandled case");
            }

            $arrOutput[$keyActiveUserCourse]->lastcounter = $arrOutput[$keyActiveUserCourse]->lastcourseaccess = $objLog->timecreated;
        }
        return $arrOutput;
    }
    
    public function processUsers($all, $logs, $priors){

        $r = array();

        $needCalc     = array_intersect_key($all, $logs);
        $onRecord     = array_intersect_key($all, $priors);
        $needZeros    = array_diff_key($all, $onRecord, $needCalc);
        assert($needCalc + $needZeros + $onRecord == $this->all_enrolled_users);
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
                return (int)$out[0];
            }, $needCalcKeys);
            $uuid = array_unique($uids);
        foreach($uuid as $uid){
            $r = array_merge($r,$this->calculate_timespent($uid));
        }
        
        
        return $r;
    }
    
    public function drop_existing_for_timerange(){

        global $DB;
        $select = sprintf("lastcourseaccess >= %s AND lastcourseaccess <= %s;",$this->proc_start, $this->proc_end);
        $DB->delete_records_select('apreport_enrol', $select);
    }
    
    public function make_report(array $params=null){

        if(!isset($this->report_start) || !isset($this->report_end) || $this->report_end <= $this->report_start){
            throw new coding_exception("this method requires a valid timerange for the report");
        }
        $sums = $this->get_db_sums($this->report_start,$this->report_end);
        $rows = $this->get_db_records($this->report_start,$this->report_end);
        $arrFullRecords  = array();

        foreach($rows as $k=>$v){
            $objLmsEnrollment = lmsEnrollmentRecord::instantiate($v);
            $objLmsEnrollment->timespentinclass = array_key_exists($k, $sums) ? $sums[$k]->time : 0;
            $arrFullRecords[] = $objLmsEnrollment;
        }
        return $arrFullRecords;
    }
    
    public function get_report(array $params=null){
        $rep  = $this->make_report();
        $xdoc = lmsEnrollmentRecord::toXMLDoc($rep,'lmsEnrollments', 'lmsEnrollment');
        lmsEnrollment::update_job_status(apreport_job_stage::RETRIEVE, apreport_job_status::SUCCESS, $this->mode);

        lmsEnrollment::update_job_status(apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS, $this->mode.' '.  apreport_util::microtime_toString(microtime()));
        return !$xdoc ? new DOMDocument() : $xdoc;    
    }
    
    public function run(){
        if(in_array($this->mode, self::$proc_modes)){
            $this->drop_existing_for_timerange();
            $newRecs = $this->processUsers(
                $this->getEnrollment(),
                $this->getLogs(),
                $this->getPriorRecords()
            );

            if(count($newRecs)>0){
                lmsEnrollment::update_job_status(apreport_job_stage::QUERY, apreport_job_status::SUCCESS, $this->mode);
            }else{
                lmsEnrollment::update_job_status(apreport_job_stage::QUERY, apreport_job_status::EXCEPTION, $this->mode);
                //nothing more to do
                return new DOMDocument();
            }

            //save to db
            $this->db_save_records($newRecs);
            lmsEnrollment::update_job_status(apreport_job_stage::PERSIST, apreport_job_status::SUCCESS, $this->mode);
        }
        
        //get all recs
        $rep  = $this->make_report();
        $xdoc = lmsEnrollmentRecord::toXMLDoc($rep,'lmsEnrollments', 'lmsEnrollment');
        lmsEnrollment::update_job_status(apreport_job_stage::RETRIEVE, apreport_job_status::SUCCESS, $this->mode);

        if($this->mode == 'cron' || $this->mode == 'reprocess'){
            self::create_file($xdoc);
            lmsEnrollment::update_job_status(apreport_job_stage::SAVE_XML, apreport_job_status::SUCCESS, $this->mode);
        }
        
        lmsEnrollment::update_job_status(apreport_job_stage::COMPLETE, apreport_job_status::SUCCESS, $this->mode.' '.  apreport_util::microtime_toString(microtime()));
        return !$xdoc ? new DOMDocument() : $xdoc;    }

    public static function backfill() {
        $tsCurrentDayStart  = $tsBackfillStart = apreport_util::get_earliest_semester_start();
        $dttmBackfillStop   = new DateTime('yesterday');
        $todayFmt           = $dttmBackfillStop->format('Y-m-d H:M:s');

        while($tsCurrentDayStart < $dttmBackfillStop->getTimestamp()){
            mtrace(sprintf('Beginning run for %s stopping at %s<br/>', strftime('%F', $tsCurrentDayStart), $todayFmt));
            $objLmsEnrollment = new self;
            $objLmsEnrollment->proc_start = $tsCurrentDayStart;
            $objLmsEnrollment->proc_end   = $objLmsEnrollment->proc_start + 86400;

            $objLmsEnrollment->report_start = $tsBackfillStart;
            $objLmsEnrollment->report_end = $objLmsEnrollment->proc_end;
            $objLmsEnrollment->run();
            $tsCurrentDayStart += 86400;
        }
        $objLmsEnrollment->report_start = $tsBackfillStart;
        $objLmsEnrollment->report_end = $dttmBackfillStop->getTimestamp();

        $rep  = $objLmsEnrollment->make_report();
        $domDoc = lmsEnrollmentRecord::toXMLDoc($rep,'lmsEnrollments', 'lmsEnrollment');

        lmsEnrollment::update_job_status(apreport_job_stage::RETRIEVE, apreport_job_status::SUCCESS, $objLmsEnrollment->mode);
        self::create_file($domDoc);

        lmsEnrollment::update_job_status(apreport_job_stage::SAVE_XML, apreport_job_status::SUCCESS, $objLmsEnrollment->mode);
        return true;
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
    
    /**
     * For each course id, execute the query and return the result;
     * SCORM is a special case and is the reason for the type param
     * @global type $DB
     * @param int[] $arrCourseIds courseids used as query input params
     * @param string $strQuery basically raw SQL
     * @param string $strActivityType used to determine whether we need to take 
     * special actions for special cases, like SCORM
     * @return stdClass[] query result rows
     */
    public function coursework_get_subreport_dataset($arrCourseIds,$strQuery, $strActivityType){
        global $DB;
        $records = array();
        foreach($arrCourseIds as $cid){
            $sql = sprintf($strQuery, $cid,$cid);
                    $records = array_merge($records,$DB->get_records_sql($sql));
        }
        
        //calculate SCORM date complete
        if($strActivityType == 'scorm'){
            foreach($records as $record){
                if(isset($record->timeelapsed) && isset($record->datestarted)){
                    $record->datesubmitted = lmsCoursework::get_scorm_datesubmitted($record->datestarted, $record->timeelapsed);
                }
            }
        }
        return $records;
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
                cats.categoryWeight AS categoryWeight,
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
                        cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
                cats.categoryWeight AS categoryWeight,
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
    cats.categoryWeight AS categoryWeight,
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
