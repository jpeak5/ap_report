<?php

class tbl_model{
    public static function instantiate($params){
        $inst = new static();
        $keys = get_object_vars($inst);
        if(!is_array($params)){
            $params = (array)$params;
        }
        foreach($params as $k=>$v){
            if(array_key_exists($k, $keys)){
                $inst->$k = $v;
            }
        }
        return $inst;
    }
}


/**
 * models the structure of the corresponding db table
 */
class ap_report_table extends tbl_model{
//    public $user; //a user isntance
    public $lastaccess;     //timest
    public $agg_timespent;  //int
    public $cum_timespent;  //int
    public $semesterid;     //ues semester id
    public $sectionid;      //unique ues section id
    public $userid;         //mdl user id
    public $timestamp;      //time()
    
//    public function __construct($id, $sid, $ats, $cts, $last, $ts, $sem){
//        $this->userid = $id;
//        $this->sectionid = $sid;
//        $this->agg_timespent = $ats;
//        $this->cum_timespent = $cts;
//        $this->lastaccess = $last;
//        $this->timestamp = $ts;
//        $this->semesterid = $sem;
//    }
}
/**
 * models the table definition for ues_students
 */
class ues_students_tbl extends tbl_model{
    public $id;
    public $userid;
    public $sectionid;
    public $credit_hours;
    public $status;
}

/**
 * models the table definition of ues_sections
 */
class ues_sections_tbl extends tbl_model{
    public $id;
    public $courseid;
    public $semesterid;
    public $idnumber;
    public $sec_number;
    public $status;
}
/**
 * models table definition for ues_courses
 */
class ues_courses_tbl extends tbl_model{
    public $id;
    public $department;
    public $cou_number;
    public $fullname;
}

/**
 * models the table definition for ues_semesters
 */
class ues_semester_tbl extends tbl_model{
    public $id;
    public $year;
    public $name;
    public $campus;
    public $session_key;
    public $classes_start;
    public $grades_due;
    
    
    public static function instantiate($params){
        $inst = new self();
        if(!is_array($params)){
            $params = (array)$params;
        }
        $keys = array_keys($params);
        $fields = get_class_vars('ues_semester_tbl');
        foreach($keys as $k){
            if(array_key_exists($k,$fields)){
                $inst->$k = $params[$k];
            }
        }
        return $inst;
    }
    
    
    /**
     * 
     * @param int $i id
     * @param int $y year
     * @param string $n name, eg Spring, Fall
     * @param string $c campus eg LSU, LAW
     * @param string $s session_key, eg 'A', 'B', etc 
     * The session key may be specified in testing cases specific to a given semester
     * @param timestamp $cl classes_start for testing purposes, this value, 
     * if not specified, is initialized to time() - 7 days
     * @param timestamp $gd grades due for testing purposes, this value, 
     * if not specified, is initialized to time() - 7 days
     */
    public static function make_test_instance($i, $y, $n, $c, $s=null, $cl=null, $gd=null){
        $inst = new self();
        $inst->id = $i;
        $inst->year = $y;
        $inst->name = $n;
        $inst->campus = $c;
        $inst->session_key   = isset($s)    ? $s    : null;
        $inst->grades_due    = isset($gd)   ? $gd   : strtotime("+7 days", time());
        $inst->classes_start = isset($cl)   ? $cl   : strtotime("-7 days", time());
        return $inst;

    }
}


/**
 * models the table definition of mdl_course
 */
class mdl_course extends tbl_model{
    public $id;
    public $idnumber;

}
/**
 * models the table definition of mdl_context
 */
class mdl_context extends tbl_model{
    public $id;
    public $instanceid;
    
    /**
     * 
     * @param int $mdl_course_id constructs a new mdl_context record
     * bound to the course_id given as input parameter
     */
    public static function make_test_instance($mdl_course_id){
        $inst = new self();
        $inst->instanceid = $mdl_course_id;
        $inst->id = rand(0,9999);
        return $inst;
    }
    
}
/**
 * models the table definition of mdl_role_assignment
 */
class mdl_role_assignment extends tbl_model{
    public $id;
    public $roleid;
    public $contextid;
    public $userid;
    
    /**
     * constructs a mdl_role_assignment record assigning a user to a role in a context
     * @param int $roleid roleid, eg 5 (surely this is not always true?) for student
     * @param int $contextid context id
     * @param int $userid mkdl_user.id
     */
    public static function make_test_instance($roleid, $contextid, $userid){
        $inst = new self();
        $inst->id = lmsEnrollment_testcase::gen_id();
        $inst->contextid = $contextid;
        $inst->userid = $userid;
        $inst->roleid = $roleid;
        return $inst;
    }
}

/**
 * models the table definition of mdl_user
 */
class mdl_user extends tbl_model{
    public $id;
    public $username;
    public $idnumber;
    public $email;
}

/**
 * @TODO repurpose/rename the ues_studenttest member 
 * to hold references to the corresponding recordsin ues_students
 * to enable bidirectional lookup
 * wrapper class composing mdl_user and ues_student
 */
class student extends tbl_model{
    public $courses;
    public $ues_studenttest;
    public $mdl_user;
    public $activity; //array of activity logs
    
    /**
     * generates a mdl_user based on the input username
     * input username should probably be auto generated
     * @param int $username
     */
    public static function make_test_instance($username){
        $inst = new self();
        $inst->mdl_user = new mdl_user();
        $inst->mdl_user->username = $username;
        $inst->mdl_user->email = $username.'@example.com';
        $inst->mdl_user->idnumber = lmsEnrollment_testcase::gen_idnumber();
        $inst->mdl_user->id = lmsEnrollment_testcase::gen_id(3);
        return $inst;
    }
}

/**
 * wrapper class composing 
 *  mdl_course
 *  ues_courses
 *  ues_students
 *  ues_sections
 *  mdl_user (as members of teh students array)
 *  mdl_role_assignments
 *  mdl_context (as members of the contexts array)
 * @TODO singularize contexts for clarity
 * 
 */
class course extends tbl_model{
    public $mdl_course;
    public $ues_section;
    public $ues_course;
    public $ues_students;
    public $students;
    public $role_assignments;
    public $contexts;
    public $mdl_logs;
    public $ap_report;
    
    public static function instantiate($params){
        $inst = new self();
        $keys = get_object_vars($inst);
        
        foreach($params as $k => $v){
            if(array_key_exists($k, $keys)){
                mtrace(sprintf("matched class key %s", $k));
                $inst->$k = $v;
            }
        }
        return $inst;
    }
    
    public static function make_test_instance($dept, $cou_num){
        $inst = new self();
        $inst->ues_section = new ues_sections_tbl();
        $inst->mdl_course      = new mdl_course();
        $inst->mdl_course->id = lmsEnrollment_testcase::gen_id();
        
        
        
        $inst->ues_course             = new ues_courses_tbl();
        $inst->ues_course->department = $dept;
        $inst->ues_course->cou_number = $cou_num;
        $inst->ues_course->fullname   = $dept.$cou_num.$inst->ues_section->sec_number;
        
        $inst->ues_section->sec_number= "00".rand(0,9);
        $inst->ues_section->idnumber  = $inst->mdl_course->idnumber = $inst->ues_course->fullname.lmsEnrollment_testcase::gen_id();
        $inst->ues_section->courseid  = $inst->ues_course->id = lmsEnrollment_testcase::gen_id();
        $inst->ues_section->id        = lmsEnrollment_testcase::gen_id();
        
        $ctx = mdl_context::make_test_instance($inst->mdl_course->id);
        $inst->contexts = $ctx;
        return $inst;
    }
    
    /**
     * This function describes the proper arrangement of ues_students records as
     * a component of ues_section and, by extension, @see mdl_course and @see course
     * @param array $students of type student
     * @TODO the assignment to $this->role_assignments[] assumes 
     * that mdl_roleid = 5; this needs to be checked.
     */
    public function enrol_student(student $student){
            $s     = new ues_students_tbl();
            $s->id = lmsEnrollment_testcase::gen_id();
            $s->credit_hours = rand(0,6);
            $s->sectionid = $this->ues_section->id;
            $s->userid    = $student->mdl_user->id;
            $s->status    = 'enrolled';
            $this->ues_students[] = $s;

            $this->students[] = $student;
 
            $this->role_assignments[] = mdl_role_assignment::make_test_instance(
                    5, 
                    $this->contexts->id, 
                    $student->mdl_user->id
                    );
        
    }
    
}
/**
 * wrapper class around ues_semesters and a 
 * collection of courses for a given semester;
 * 
 */
class semester{
    

    /**
     *
     * @var ues_semester_tbl 
     */
    public $ues_semester;   //ues record
    /**
     *
     * @var array course 
     */
    public $courses;        //array of courses
    
    /**
     * 
     * @param array $params keyed as follows: 
     * 'ues_semester' => ues_semester object,
     * 'courses' => array of course objects
     * 
     */
    public static function instantiate(array $params){
        $inst = new self();
        if(array_key_exists('ues_semester', $params)){
            $inst->ues_semester = ues_semester_tbl::instantiate($params['ues_semester']);
        }
        
        if(array_key_exists('courses', $params)){
            foreach($params['courses'] as $course){
                if(get_class($course) == 'course'){
                    $inst->courses[] = $course;
                }else{
                    mtrace("expected a course object, but got something different; handle this condition");
                }
                
            }
        }
        
        return $inst;
    }
    
    /**
     * 
     * @param ues_semester_tbl $sem
     * @param type $courses
     */
    public static function make_test_instance($sem, $courses){
        $inst = new self();
        $inst->courses = $courses;
        $inst->ues_semestertest = $sem;
        foreach($inst->courses as $c){
            $c->ues_section->semesterid = $sem->id;
        }
        return $inst;
    }
    
}

class enrollment_model {
    /**
     *
     * @var array(semester) 
     */
    public $semesters;
    /**
     *
     * @var array(course) 
     */
    public $courses;
    /**
     *
     * @var array(student) 
     */
    public $students;
    
    //table abstractions
//    public $ues_sections;
//    public $ues_courses;
//    public $ues_students;
//    public $ues_semesters;
//    public $mdl_courses;
//    public $mdl_logs;
//    public $mdl_users;
 
    
    public function get_enrollment_tree_student_leaves(){
        
    }
    public function get_enrollment_tree_course_leaves(){
        
    }
 
}
?>