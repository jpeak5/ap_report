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


class ues_sectiontest{
    public $id;
    public $courseid;
    public $semesterid;
    public $idnumber;
    public $sec_number;
    public $status;
}
class ues_coursetest{
    public $id;
    public $department;
    public $cou_number;
    public $fullname;
}
class ues_semestertest{
    public $id;
    public $year;
    public $name;
    public $campus;
    public $session_key;
    public $classes_start;
    public $grades_due;
    
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

class ues_studenttest{
    public $id;
    public $userid;
    public $sectionid;
    public $credit_hours;
    public $status;
}
class mdl_course{
    public $id;
    public $idnumber;

}
class mdl_context{
    public $id;
    public $instanceid;
    
    public function __construct($mdl_course_id){
        $this->instanceid = $mdl_course_id;
        $this->id = rand(0,9999);
    }
    
}
class mdl_role_assignment{
    public $id;
    public $roleid;
    public $contextid;
    public $userid;
    
    public function __construct($roleid, $contextid, $userid){
        $this->id = lmsEnrollment_testcase::gen_id();
        $this->contextid = $contextid;
        $this->userid = $userid;
        $this->roleid = $roleid;
    }
}

class mdl_user{
    public $id;
    public $username;
    public $idnumber;
    public $email;
}



class student {
    public $ues_studenttest;
    public $mdl_user;
    public $activity; //array of activity logs
    
    public function __construct($username){
        $this->mdl_user = new mdl_user();
        $this->mdl_user->username = $username;
        $this->mdl_user->email = $username.'@example.com';
        $this->mdl_user->idnumber = lmsEnrollment_testcase::gen_idnumber();
        $this->mdl_user->id = lmsEnrollment_testcase::gen_id();
    }
}

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
        $this->mdl_course = new mdl_course();
        $this->mdl_course->id = lmsEnrollment_testcase::gen_id();
        
        $this->ues_sectiontest->sec_number = "00".rand(0,9);
        
        $this->ues_coursetest = new ues_coursetest();
        $this->ues_coursetest->department = $dept;
        $this->ues_coursetest->cou_number = $cou_num;
        $this->ues_coursetest->fullname = $dept.$cou_num.$this->ues_sectiontest->sec_number;
        
        $this->ues_sectiontest->idnumber = $this->mdl_course->idnumber = $this->ues_coursetest->fullname.lmsEnrollment_testcase::gen_id();
        $this->ues_sectiontest->courseid = $this->ues_coursetest->id = lmsEnrollment_testcase::gen_id();
        $this->ues_sectiontest->id = lmsEnrollment_testcase::gen_id();
        
        $ctx = new mdl_context($this->mdl_course->id);
        $this->contexts = $ctx;
    }
    
    /**
     * 
     * @param array $students of type student
     */
    public function enrol_student(student $student){
            $s = new ues_studenttest();
            $s->id = lmsEnrollment_testcase::gen_id();
            $s->credit_hours = rand(0,6);
            $s->sectionid = $this->ues_sectiontest->id;
            $s->userid = $student->mdl_user->id;
            $s->status = 'enrolled';
            $this->ues_students[] = $s;

            $this->students[] = $student;
            
            
            
            $this->role_assignments[] = new mdl_role_assignment(5, $this->contexts->id, $student->mdl_user->id);
        
    }
    
}


class semestertest{
    public $ues_semestertest;   //ues record
    public $courses;        //array of courses
    
    public function __construct($sem, $courses){
        $this->courses = $courses;
        $this->ues_semestertest = $sem;
        foreach($this->courses as $c){
            $c->ues_sectiontest->semesterid = $sem->id;
        }
    }
    
}

class enrollment{
    public $semestertests;
    public $verify;
    
    public function __construct($semestertests){
        $this->semestertests = $semestertests;
    }
}

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

class enrollment_generator{
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
    
    private function populate_constituent_arrays(){
        
        
        //semester loop
        foreach($this->enrollment->semestertests as $semester){
            $this->ues_semesters[] = array(
                    'id' =>$semester->ues_semestertest->id,
                    'year'=>$semester->ues_semestertest->year,
                    'name'=>$semester->ues_semestertest->name,
                    'campus'=>$semester->ues_semestertest->campus,
                    'classes_start'=> $semester->ues_semestertest->classes_start,
                    'grades_due'=>$semester->ues_semestertest->grades_due);
            //course/section loop
            foreach($semester->courses as $c){
                $this->ues_courses[] = array(
                    'id'=>$c->ues_coursetest->id,
                    'department'=>$c->ues_coursetest->department,
                    'cou_number'=>$c->ues_coursetest->cou_number,
                    'fullname'=>$c->ues_coursetest->fullname);
                
                $this->mdl_courses[] = array(
                    'id'=>$c->mdl_course->id, 
                    'idnumber'=>$c->mdl_course->idnumber);
                
                $this->ues_sections[] = array(
                    'id'=>$c->ues_sectiontest->id,
                    'courseid'=>$c->ues_sectiontest->courseid,
                    'semesterid'=>$c->ues_sectiontest->semesterid,
                    'idnumber'=>$c->ues_sectiontest->idnumber, 
                    'sec_number'=>$c->ues_sectiontest->sec_number);  //jdoe1
                
                $this->mdl_contexts[] = array('id'=>$c->contexts->id,'instanceid'=>$c->contexts->instanceid);
                //user loop
                foreach($c->students as $s){
                    $this->mdl_logs = empty($this->mdl_logs) ? $s->activity : array_merge($this->mdl_logs,$s->activity);        
                }
                
                foreach($c->ues_students as $ustu){
                    $this->ues_students[] = array(
                        'userid'=>$ustu->userid, 
                        'sectionid'=>$ustu->sectionid,
                        'status'=>$ustu->status,
                        'id'=>$ustu->id,
                        'credit_hours'=>$ustu->credit_hours
                    );
                }
                
                foreach($c->role_assignments as $ra){
                    $this->mdl_role_ass[] = array(
                        'contextid'=>$ra->contextid, 
                        'id'=>$ra->id, 
                        'roleid'=>$ra->roleid, 
                        'userid'=>$ra->userid
                            );
                }
            }
        }
        
    }
    

    
    private function generate_courses($students){
        $sem = new ues_semestertest(5, 2013, 'Spring', 'LSU');
        $c1  = new course('BIOL', 1335);
        $c2  = new course('AGRI', 4009);
        $courses = array($c1, $c2);
        foreach($courses as $c){
            $i=0;
            while($i< rand(1,count($students))){
                $c->enrol_student($students[$i]);
                $i++;
            }
        }
        $semestertest   = new semestertest($sem, $courses);
        $enrollment = new enrollment(array($semestertest));
        return $enrollment;
    }
    
    public function generate($activity=false){
        $students = $this->generate_students();
        $this->enrollment = $this->generate_courses($students);
        if($activity){
            $this->generate_activity();
        }
        $this->populate_constituent_arrays();
        return $this->enrollment;
    }
    
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
    
    public function generate_activity(){
        foreach($this->enrollment->semestertests as $s){
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
