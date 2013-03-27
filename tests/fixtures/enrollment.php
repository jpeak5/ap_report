<?php
require_once('classes.php');

class user_activity_segment{
    public $userid;
    public $apreport_enrol_record;  //current timespent record for user
    public $enrollment_record;      //ues details
    public $logs;
    
    
    
    public function __construct($id, $ap, $en, $ts, $evct){
        $this->userid = $id;
        $this->apreport_enrol_record = $ap;
        $this->enrollment_record = $en;
        $this->logs = lmsEnrollment_testcase::generate_user_activity_segment($this->userid, $ts, $evct, $this->enrollment_record->courseid);
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
        global $DB;
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
                    'id'        =>$c->ues_course->id,
                    'fullname'  =>$c->ues_course->fullname,
                    'department'=>$c->ues_course->department,
                    'cou_number'=>$c->ues_course->cou_number,
                    );
                
                $this->mdl_courses[] = array(
                    'id'        =>$c->mdl_course->id, 
                    'idnumber'  =>$c->mdl_course->idnumber);
                
                $this->ues_sections[] = array(
                    'id'        =>$c->ues_section->id,
                    'idnumber'  =>$c->ues_section->idnumber, 
                    'courseid'  =>$c->ues_section->courseid,
                    'semesterid'=>$c->ues_section->semesterid,
                    'sec_number'=>$c->ues_section->sec_number);  //jdoe1
                
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
        
        $sem     = ues_semester_tbl::make_test_instance(5, 2013, 'Spring', 'LSU');
        $c1      = course::make_test_instance('BIOL', 1335);
        $c2      = course::make_test_instance('AGRI', 4009);
        $courses = array($c1, $c2);
        
        foreach($courses as $c){
            $i=0;
            while($i< rand(1,count($students))){
                $c->enrol_student($students[$i]);
                $i++;
                mtrace(sprintf("adding student idnumber %d to course id %s", $students[$i]->mdl_user->idnumber,$c->mdl_course->id));
            }
        }
        
        $semestertest = semester::make_test_instance($sem, $courses);
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
            $u = student::make_test_instance('student-'.$i);
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
                    $v->courseid = $c->ues_course->department." ".$c->ues_course->cou_number;
                    $v->sectionid = $c->ues_section->sec_number;
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
