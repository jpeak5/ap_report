<?php
require_once('dbal.php');
class apreport_util{
        /**
     * This method derives timestamps for the beginning and end of yesterday
     * @return array int contains the start and end timestamps
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
}

/**
 * parent class for all user-facing data records.
 * Contains formatting methods that ensure adherence to the end-user XML schema
 */
class apreportRecord{
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

class lmsGroupMembershipRecord extends tbl_model{
  public $sectionid;
  public $groupid;
  public $studentid;
  public $extensions;
  
  
  public static $camels = array(
      'sectionid'=>'sectionId',
      'groupid'=>'groupId',
      'studentid'=>'studentId',
      'extensions'=>'extensions');
  
  /**
   * 
   * @param lmsGroupMembershipRecord $object
   */
  public static function camelize($object){
      $camel = new stdClass();
      foreach(get_object_vars($object) as $k=>$v){
          if(array_key_exists($k,self::$camels)){
              $caseProperty = self::$camels[$k];
              $camel->$caseProperty = $v;
          }
      }
      return $camel;
  }
  
  /**
   * 
   * @param stdClass $object
   */
  public static function toXML($object){
      $doc = new DOMDocument('1.0', 'UTF-8');
      $f = $doc->createElement('lmsGroupMember');
      
      foreach(get_object_vars($object) as $key => $value){
          if(in_array($key,array_values(self::$camels))){
              $x = $doc->createElement($key, $value);
              assert(get_class($x) == 'DOMElement');
              $f->appendChild($x);
              mtrace(sprintf("adding new node %s with value %s", $key, $value));
             
          }
      }
      $doc->appendChild($f);
      mtrace(get_class($doc));
      assert(get_class($doc) == 'DOMDocument');
      return $doc;
      
  }
  
}
?>