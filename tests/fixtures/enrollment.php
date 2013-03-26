<?php

class user_activity_segment{
    public $userid;
    public $apreport_enrol_record;  //current timespent record for user
    public $enrollment_record;      //ues details
    public $logs;
    
    
    
    public function __contstruct($id, $ap, $en, $ts, $evct){
        $this->userid = $id;
        $this->apreport_enrol_record = $ap;
        $this->enrollment_record = $en;
        $this->logs = lmsEnrollment_testcase::generate_user_activity_segment($this->userid, $ts, $evct, $this->enrollment_record->courseid);
    }
}

/**
 * models the table definition of ues_sections
 */
class ues_sectiontest{
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
class ues_coursetest{
    public $id;
    public $department;
    public $cou_number;
    public $fullname;
}
/**
 * models the table definition for ues_semesters
 */
class ues_semestertest{
    public $id;
    public $year;
    public $name;
    public $campus;
    public $session_key;
    public $classes_start;
    public $grades_due;
    
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
    public function __construct($i, $y, $n, $c, $s=null, $cl=null, $gd=null){
        $this->id = $i;
        $this->year = $y;
        $this->name = $n;
        $this->campus = $c;
        $this->session_key   = isset($s)    ? $s    : null;
        $this->grades_due    = isset($gd)   ? $gd   : strtotime("+7 days", time());
        $this->classes_start = isset($cl)   ? $cl   : strtotime("-7 days", time());

    }
}
/**
 * models the table definition for ues_students
 */
class ues_studenttest{
    public $id;
    public $userid;
    public $sectionid;
    public $credit_hours;
    public $status;
}
/**
 * models the table definition of mdl_course
 */
class mdl_course{
    public $id;
    public $idnumber;

}
/**
 * models the table definition of mdl_context
 */
class mdl_context{
    public $id;
    public $instanceid;
    
    /**
     * 
     * @param int $mdl_course_id constructs a new mdl_context record
     * bound to the course_id given as input parameter
     */
    public function __construct($mdl_course_id){
        $this->instanceid = $mdl_course_id;
        $this->id = rand(0,9999);
    }
    
}
/**
 * models the table definition of mdl_role_assignment
 */
class mdl_role_assignment{
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
    public function __construct($roleid, $contextid, $userid){
        $this->id = lmsEnrollment_testcase::gen_id();
        $this->contextid = $contextid;
        $this->userid = $userid;
        $this->roleid = $roleid;
    }
}

/**
 * models the table definition of mdl_user
 */
class mdl_user{
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
class student {
    public $ues_studenttest;
    public $mdl_user;
    public $activity; //array of activity logs
    
    /**
     * generates a mdl_user based on the input username
     * input username should probably be auto generated
     * @param int $username
     */
    public function __construct($username){
        $this->mdl_user = new mdl_user();
        $this->mdl_user->username = $username;
        $this->mdl_user->email = $username.'@example.com';
        $this->mdl_user->idnumber = lmsEnrollment_testcase::gen_idnumber();
        $this->mdl_user->id = lmsEnrollment_testcase::gen_id(3);
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
class course{
    public $mdl_course;
    public $ues_sectiontest;
    public $ues_coursetest;
    public $ues_students;
    public $students;
    public $role_assignments;
    public $contexts;
    
    public function __construct($dept, $cou_num){
        $this->ues_sectiontest = new ues_sectiontest();
        $this->mdl_course      = new mdl_course();
        $this->mdl_course->id = lmsEnrollment_testcase::gen_id();
        
        
        
        $this->ues_coursetest             = new ues_coursetest();
        $this->ues_coursetest->department = $dept;
        $this->ues_coursetest->cou_number = $cou_num;
        $this->ues_coursetest->fullname   = $dept.$cou_num.$this->ues_sectiontest->sec_number;
        
        $this->ues_sectiontest->sec_number= "00".rand(0,9);
        $this->ues_sectiontest->idnumber  = $this->mdl_course->idnumber = $this->ues_coursetest->fullname.lmsEnrollment_testcase::gen_id();
        $this->ues_sectiontest->courseid  = $this->ues_coursetest->id = lmsEnrollment_testcase::gen_id();
        $this->ues_sectiontest->id        = lmsEnrollment_testcase::gen_id();
        
        $ctx = new mdl_context($this->mdl_course->id);
        $this->contexts = $ctx;
    }
    
    /**
     * This function describes the proper arrangement of ues_students records as
     * a component of ues_section and, by extension, @see mdl_course and @see course
     * @param array $students of type student
     * @TODO the assignment to $this->role_assignments[] assumes 
     * that mdl_roleid = 5; this needs to be checked.
     */
    public function enrol_student(student $student){
            $s     = new ues_studenttest();
            $s->id = lmsEnrollment_testcase::gen_id();
            $s->credit_hours = rand(0,6);
            $s->sectionid = $this->ues_sectiontest->id;
            $s->userid    = $student->mdl_user->id;
            $s->status    = 'enrolled';
            $this->ues_students[] = $s;

            $this->students[] = $student;
 
            $this->role_assignments[] = new mdl_role_assignment(
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
class semester_test{
    /**
     *
     * @var ues_semestertest 
     */
    public $ues_semestertest;   //ues record
    /**
     *
     * @var array course 
     */
    public $courses;        //array of courses
    
    /**
     * 
     * @param ues_semestertest $sem
     * @param type $courses
     */
    public function __construct($sem, $courses){
        $this->courses = $courses;
        $this->ues_semestertest = $sem;
        foreach($this->courses as $c){
            $c->ues_sectiontest->semesterid = $sem->id;
        }
    }
    
}

/**
 * top-level of this fixture data structure
 * There should be only one of these
 */
class enrollment{
    /**
     *
     * @var array semester 
     */
    public $semesters;
    /**
     *
     * @var array verification
     */
    public $verify;
    
    public function __construct($semestertests){
        $this->semesters = $semestertests;
    }
}

/**
 * This class models the final output data structure.
 * It is populated at fixture creation time and used 
 * to verify values calculated by production code
 */
class verification{
    public $enrollmentid;
    public $studentid;
    public $courseid;
    public $sectionid;
    public $start_date;
    public $end_date;
    public $status;
    public $lastcourseaccess;
    public $timespentinclass;
}

/**
 * possibly unnecessary further layer of 
 * abstraction above @see enrollment
 * and composing all fixture classes
 */
class enrollment_generator{
    /**
     *
     * @var enrollment 
     */
    public $enrollment;
    public $ues_sections;
    public $ues_courses;
    public $ues_students;
    public $ues_semesters;
    public $mdl_courses;
    public $mdl_contexts;
    public $mdl_role_ass;
    public $mdl_logs;
    public $mdl_users;
    
    /**
     * Main entry point for test classes
     * builds the enrollment data structure,
     * optionally, with user activity
     * @param bool $activity whether or not to 
     * generate user activity log records
     * @return enrollment
     */
    public function generate($activity=false){
        $students         = $this->generate_students();
        $this->enrollment = $this->generate_courses($students);
        
        if($activity){
            $this->generate_activity();
        }
        $this->populate_constituent_arrays();
        return $this->enrollment;
    }
    
    /**
     * this method walks the enrollment dtat structure and populates the 
     * class member arrays with table data ready for insertion into the 
     * db as test data
     */
    private function populate_constituent_arrays(){

        //semester loop
        foreach($this->enrollment->semesters as $semester){
            $this->ues_semesters[] = array(
                    'id'            =>$semester->ues_semestertest->id,
                    'year'          =>$semester->ues_semestertest->year,
                    'name'          =>$semester->ues_semestertest->name,
                    'campus'        =>$semester->ues_semestertest->campus,
                    'grades_due'    =>$semester->ues_semestertest->grades_due,    
                    'classes_start' =>$semester->ues_semestertest->classes_start
                    );
                    
            //course/section loop
            foreach($semester->courses as $c){
                $this->ues_courses[] = array(
                    'id'        =>$c->ues_coursetest->id,
                    'fullname'  =>$c->ues_coursetest->fullname,
                    'department'=>$c->ues_coursetest->department,
                    'cou_number'=>$c->ues_coursetest->cou_number,
                    );
                
                $this->mdl_courses[] = array(
                    'id'        =>$c->mdl_course->id, 
                    'idnumber'  =>$c->mdl_course->idnumber);
                
                $this->ues_sections[] = array(
                    'id'        =>$c->ues_sectiontest->id,
                    'idnumber'  =>$c->ues_sectiontest->idnumber, 
                    'courseid'  =>$c->ues_sectiontest->courseid,
                    'semesterid'=>$c->ues_sectiontest->semesterid,
                    'sec_number'=>$c->ues_sectiontest->sec_number);  //jdoe1
                
                $this->mdl_contexts[] = array('id'=>$c->contexts->id,'instanceid'=>$c->contexts->instanceid);
                //user loop
                foreach($c->students as $s){
                    $this->mdl_logs = empty($this->mdl_logs) ? $s->activity : array_merge($this->mdl_logs,$s->activity);        
                }
                
                foreach($c->ues_students as $ustu){
                    $this->ues_students[] = array(
                        'id'            =>$ustu->id,
                        'userid'        =>$ustu->userid, 
                        'status'        =>$ustu->status,
                        'sectionid'     =>$ustu->sectionid,
                        'credit_hours'  =>$ustu->credit_hours
                    );
                }
                
                foreach($c->role_assignments as $ra){
                    $this->mdl_role_ass[] = array(
                        'id'        =>$ra->id, 
                        'roleid'    =>$ra->roleid, 
                        'userid'    =>$ra->userid,
                        'contextid' =>$ra->contextid, 
                            );
                }
            }
        }
        
    }
    

    /**
     * 
     * @param array(student) $students
     * @return \enrollment
     */
    private function generate_courses($students){
        $sem     = new ues_semestertest(5, 2013, 'Spring', 'LSU');
        $c1      = new course('BIOL', 1335);
        $c2      = new course('AGRI', 4009);
        $courses = array($c1, $c2);
        
        foreach($courses as $c){
            $i=0;
            while($i< rand(1,count($students))){
                $c->enrol_student($students[$i]);
                $i++;
                mtrace(sprintf("adding student idnumber %d to course id %s", $students[$i]->mdl_user->idnumber,$c->mdl_course->id));
            }
        }
        
        $semestertest = new semester_test($sem, $courses);
        $enrollment   = new enrollment(array($semestertest));
        
        return $enrollment;
    }
    
    /**
     * convenience method to automate the creation of users
     * uses a simple name + serial number scheme to generate unique users
     * @return \student
     */
    private function generate_students(){
        $users = array();
        $i=0;
        while($i<10){
            $u = new student('student-'.$i);
            $users[] = $u;
            $this->mdl_users[] = array(
                'id'=>$u->mdl_user->id, 
                'email'=>$u->mdl_user->email,
                'idnumber'=>$u->mdl_user->idnumber,
                'username'=>$u->mdl_user->username
                );
            $i++;
        }
        return $users;
    }
    
    /**
     * generates log activity records based on the enrollment of a course
     * Also, this method build the class member array verify with data that 
     * should later appear in our output
     */
    public function generate_activity(){
        foreach($this->enrollment->semesters as $s){
            foreach($s->courses as $c){
                foreach($c->students as $stu){
                    $timespent = rand(0,30000);
                    $stu->activity = lmsEnrollment_testcase::generate_user_activity_segment($stu->mdl_user->id, $timespent, rand(2,99), $c->mdl_course->id);
                    $v = new verification();
                    $v->timespentinclass = $timespent;
                    $v->end_date = $s->ues_semestertest->grades_due;
                    $v->start_date = $s->ues_semestertest->classes_start;
                    $v->courseid = $c->ues_coursetest->department." ".$c->ues_coursetest->cou_number;
                    $v->sectionid = $c->ues_sectiontest->sec_number;
                    $v->status = 'A';
                    $v->studentid = $stu->mdl_user->idnumber;
                    $last = $stu->activity[count($stu->activity) - 1];
                    $v->lastcourseaccess = $last['time'];
                    $this->enrollment->verify[] = $v;
                }
            }
        }
    }
}


?>
