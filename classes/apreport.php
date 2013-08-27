<?php
require_once('dbal.php');
class apreport_util{
    /**
     * This method derives timestamps for the beginning and end of yesterday
     * @return array int contains the start and end timestamps
     * @deprecated since version 2013052809
     */
    public static function get_yesterday($rel=null){
        $today = new DateTime($rel);
        $midnight = new DateTime($today->format('Y-m-d'));
        $end = $midnight->getTimestamp();
        
        //now subtract one day from today's first second
        $today->sub(new DateInterval('P1D'));
        $yesterday = new DateTime($today->format('Y-m-d'));
        $start = $yesterday->getTimestamp();
        return array($start, $end);
    }
    
    /**
     * @param string $ds relative date
     * @return int[] timestamp representing the first second of the 
     * day containing $ts and the first second of the following day
     */
    public static function get_day_span($s, $e=""){
        $ds = isNull($ds) ? 'today' : $ds;
        
        $date = new DateTime();
        $date->add(DateInterval::createFromDateString($ds));
        $day = new DateTime($ds);
        $midnight = new DateTime($day->format('Y-m-d'));
        $s = $midnight->getTimestamp();
        
        //now add one day from day's first second
        $day->add(new DateInterval('P1D'));
        $next_day = new DateTime($day->format('Y-m-d'));
        $e = $next_day->getTimestamp();
        return array($s,$e);
    }
    
    public static function get_earliest_semester_start(){
        global $DB;
        //SELECT id FROM {enrol_ues_semesters} WHERE 
        $sql = "
            select 
                classes_start 
            FROM {enrol_ues_semesters} 
            WHERE classes_start < UNIX_TIMESTAMP(NOW()) 
                AND  grades_due > UNIX_TIMESTAMP(NOW()) 
            ORDER BY classes_start ASC 
            LIMIT 1;";

            $r = $DB->get_record_sql($sql);
            return (int)$r->classes_start;
        
    }
    public static function microtime_toString($mt){
        
        //default microtime()
        $mta = explode(' ',$mt);
        if(count($mta)!=2){
            $fl = explode('.',(string)$mt);
            if(count($fl)!=2){
                return $mt;;
            }
            return sprintf("%s.%s",strftime("%F %T",$fl[0]),$fl[1]);
            
        }
        return sprintf("%s.%s",strftime("%F %T",$mta[1]),substr($mta[0],2,6));
    }
}

/**
 * parent class for all user-facing data records. 
 * Contains formatting methods that ensure adherence to the end-user XML schema
 */
class apreportRecord extends tbl_model{
    public $enrollmentid;
    public $sectionid;
    public $groupid;
    public $studentid;
    public $courseid;
    public $extensions;
    
    public static function format_year($y){
        return substr($y, strlen($y) - 2);
    }
    
    public static function format_section_number($s){
        return sprintf("%03u",(int)$s);
    }
    
    public static function format_department($d){
        return sprintf("%-6s",$d);
    }
    
    public static function format_enrollmentid($y,$sid, $cid, $snum){
//        $year_part  = self::format_year($y);
        $mdlcourseid= self::format_5d_courseid($cid);
        $snum = self::format_section_number($snum);
        return $y.$sid.$mdlcourseid.$snum;
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

class lmsEnrollmentRecord extends apreportRecord{
    
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
    
    public $lastaccess;
    public $timespent;
    public $sectionnumber;
    public $coursenumber;
    public $department;
    
    
    
    public $enrollmentid;
    public $studentid;
    public $courseid;
    public $sectionid;
    public $startdate;
    public $enddate;
    public $status;
    public $lastcourseaccess;
    public $timespentinclass;
    public $uid;        //mld_user.id
    public $usectid;    //mdl_enrol_ues_sections.id
    public $usemid;

    public static $camels = array(
        'enrollmentid'      => 'enrollmentId',
        'studentid'         => 'studentId',
        'courseid'          => 'courseId',
        'sectionid'         => 'sectionId',
        'startdate'         => 'startDate',
        'enddate'           => 'endDate',
        'status'            => 'status',
        'lastcourseaccess'  => 'lastCourseAccess',
        'timespentinclass'  => 'timeSpentInClass',
        'extensions'        => 'extensions'
        );

    
    

    /**
     * @TODO refactor this to take advantage of the tbl_model::camelize() method
     */
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

class lmsGroupMembershipRecord extends apreportRecord{
  
  public static $camels = array(
      'sectionid'=>'sectionId',
      'groupid'=>'groupId',
      'studentid'=>'studentId',
      'extensions'=>'extensions');
}

class lmsSectionGroupRecord extends apreportRecord{
    
    public  $groupname,
            $primaryinstructorid,
            $primaryinstructorfname,
            $primaryinstructorlname,
            $primaryinstructoremail,
            $coachid,
            $coachfirstname,
            $coachlastname,
            $coachemail;
    
    public static $camels = array(
        'sectionid'             =>'sectionId',
        'groupid'               =>'groupId',
        'groupname'             =>'groupName',
        'courseid'              =>'courseId',
        'primaryinstructorid'   =>'primaryInstructorId',
        'primaryinstructorfname'=>'primaryInstructorFName',
        'primaryinstructorlname'=>'primaryInstructorLName',
        'primaryinstructoremail'=>'primaryInstructorEmail',
        'coachid'               =>'coachId',
        'coachfirstname'        =>'coachFirstName',
        'coachlastname'         =>'coachLastName',
        'coachemail'            =>'coachEmail',
        'extensions'            =>'extensions'
        );
    
    
}

class lmsCourseworkRecord extends apreportRecord{
    public $enrollmentid,
            $studentid,
            $courseid,
            $sectionid,
            $itemtype,
            $itemid, 
            $itemname, 
            $duedate, 
            $datesubmitted,
            $pointspossible,
            $pointsreceived,
            $gradecategory,
            $categoryweight,
            $extensions;
    public static $camels = array(
        'enrollmentid'      =>'enrollmentId',
        'studentid'         =>'studentId',
        'courseid'          =>'courseId',
        'sectionid'         =>'sectionId',
        'itemtype'          =>'itemType',
        'itemid'            =>'itemId',
        'itemname'          =>'itemName',
        'duedate'           =>'dueDate',
        'datesubmitted'     =>'dateSubmitted',
        'pointspossible'    =>'pointsPossible',
        'pointsreceived'    =>'pointsReceived',
        'gradecategory'     =>'gradeCategory',
        'categoryweight'    =>'categoryWeight',
        'extensions'        =>'extensions'
    );
}
?>