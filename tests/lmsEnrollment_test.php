<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';


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
                    $stu->activity = lmsEnrollment_testcase::generate_user_activity_segment($stu->mdl_user->id, $timespent, rand(1,99), $c->mdl_course->id);
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



class lmsEnrollment_testcase extends advanced_testcase{
    
    public $sp;
    public $numRows;
    public $start;
    public $eight_am_yesterday;
    public $zero_hour_yesterday;
    public $enrollment;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function toTime($in){
        return strftime('%F %T', $in);
    }
    
    public function dump_time_member(){
        $rs = $this->sp->report_start;
        $re = $this->sp->report_end;
        $ss = $this->sp->survey_start;
        $se = $this->sp->survey_end;
        mtrace(sprintf("Dumping vars for lmsEnrollment:\nreport_start = %s\nreport_end = %s\nsurvey_start = %s\nsurvey_end = %s"
                ,$this->toTime($rs)
                ,$this->toTime($re)
                ,$this->toTime($ss)
                ,$this->toTime($se)
                ));
    }
    
    public function setUp(){
        $this->midnight_this_morning = strtotime(strftime('%F', time()));
        $this->zero_hour_yesterday = $this->midnight_this_morning -86400;
        $this->eight_am_yesterday = strtotime("+8 hours", $this->zero_hour_yesterday);
//        $dump = vsprintf("time now is %d (%s)\nmidnight last night was %d (%s)\neight am yesterday was %d (%s)",array(time(), $this->toTime(time()), $midnight_this_morning, $this->toTime($midnight_this_morning), $eight_am, $this->toTime($eight_am)));
//        die($dump);
        $this->start = strtotime('-5 day');
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
        
        $this->enrollment = new enrollment_generator();
        print_r($this->enrollment->generate(true));
        print_r($this->enrollment->enrollment->verify);
//        die();
    }
    
    public static function gen_id(){
        return rand(1,9999);
    }
    
    public static function gen_idnumber(){
        return rand(111111111,999999999);
    }
    
    
    private static function get_workhours_activity_timestamps_for_yesterday($total_time, $count, $start=null, $end=null){
        $times = array();
        $zero = strtotime(strftime('%F', time())) - 86400;
        $start = isset($start) ? $start : strtotime("+8 hours",$zero); //yesterday 0:00
        
        
        
        $random_start_time = rand($start, strtotime(strftime('%F', time())) - $total_time);
        $times[0] = $random_start_time;
        $avg = (int)$total_time/$count;
        $i=1;
        $cum =0;
        assert($count > 0);
        
        $lcount = $count;
        while($lcount >1 and $cum <= $total_time){
            $cum += (int)$avg;
            $times[$i] = $times[$i-1]+(int)$avg;
            $lcount--;
            $i++;
        }
        if($cum < $total_time){
            $diff = $total_time-$cum;
            $cum+=$diff;
            $times[$count -1] = $times[$i-1]+($diff);
        }
        assert(count($times) == $count);
        print_r($times);
        assert($cum == $total_time);
        assert($times[$count-1] - $times[0] == $total_time);
        return $times;
    }
    
    private function generate_lastaccess(){
        return rand($this->zero_hour_yesterday-86400, $this->zero_hour_yesterday);
    }
    
    private function generate_previous_cum_timespent(){
        return rand(0, 1000000);
    }
    
    public static function generate_log_event($time, $userid, $courseid, $is_login=false){
        $action = $is_login ? 'login' : 'view';
        return array('time'=>$time, 'userid'=>$userid,'course'=>$courseid, 'action'=>$action);
    }
    
    public static function generate_user_activity_segment($userid, $cum_time_spent, $event_count, $courseid){
        
        $ts=  self::get_workhours_activity_timestamps_for_yesterday($cum_time_spent, $event_count);
        
        //log the user in such that we don't take away from the known time spent
        $usr_logs = array(self::generate_log_event($ts[0]-10, $userid, $courseid, true));
        foreach($ts as $usr1_act){
            $usr_logs[] = self::generate_log_event($usr1_act, $userid, $courseid);
        }
        return $usr_logs;
    }
    
    private function create_ues_semester($id, $year, $name, $campus, $start=null, $grades_due=null){
        $start = isset($start) ? $start : strtotime("-7 days", time());
        $end   = isset($end)   ? $end   : strtotime("+7 days", time());
        return array('id' =>$id, 'year'=>$year,'name'=>$name, 'campus'=>$campus,'classes_start'=> $start, 'grades_due'=>$end);
    }
    
    
    
    private function make_dummy_data(){
        global $DB;
        
        $this->resetAfterTest();
//        $DB->delete_records('mdl_user');
        $DB->delete_records('log');
        
        $logs = $DB->get_records('log');
        $this->assertEmpty($logs);
        
        $user1 = 354;
        $user2 = 654;
        
        $usr1_timespent = 700;
        $usr2_timespent = 450;
        
        $usr1_evt_ct = 40;
        $usr2_evt_ct = 20;
        
        $ues_sectionid1 = 6666;
        $ues_sectionid2 = 3445;
        
        $ues_courseid1 = 55;
        $ues_courseid2 = 66;
        
        $courseid1 = 6545;
        $courseid2 = 7798;
        
        $course1_ctx_id = 77;
        $course2_ctx_id = 88;
        
        $semesterid = 5;
        
        $usr1_activity_logs = $this->generate_user_activity_segment($user1, $usr1_timespent, $usr1_evt_ct, $courseid1);
        $usr2_activity_logs = $this->generate_user_activity_segment($user1, $usr2_timespent, $usr2_evt_ct, $courseid1);
        $logs = array_merge($usr1_activity_logs,$usr2_activity_logs);
        

        $semester = $this->create_ues_semester('5', '2013', 'Spring', 'LSU');
        print_r($this->enrollment->ues_semesters);
//                die();
                
                
        $data = array(
                    'enrol_ues_courses' => $this->enrollment->ues_courses,
                    'user' => $this->enrollment->mdl_users,
                    'enrol_ues_students' => $this->enrollment->ues_students,
                    'enrol_ues_semesters' => $this->enrollment->ues_semesters,
                    'enrol_ues_sections' => $this->enrollment->ues_sections,
                    'course' => $this->enrollment->mdl_courses,
                    'context' => $this->enrollment->mdl_contexts,
                    'role_assignments' => $this->enrollment->mdl_role_ass,
                    'log' => $this->enrollment->mdl_logs,
                    'apreport_enrol' => array(
                        array('timestamp'=> $this->zero_hour_yesterday,'lastaccess' => $this->generate_lastaccess(),'agg_timespent'=>456, 'cum_timespent'=>1515,'userid'=>$user1, 'sectionid'=>$ues_sectionid1, 'semesterid'=>$semesterid)
                    )

                );
        
        $dataset = $this->createArrayDataSet($data);
        $this->loadDataSet($dataset);

        $semesters_rows = $DB->get_records('enrol_ues_semesters');
        print_r($semesters_rows);
//        echo strftime('%F %T', $semesters_rows[5]->classes_start);
////        die();
        $this->assertNotEmpty($semesters_rows);
        

        $logs_check = $DB->get_records('log');
        $this->assertNotEmpty($logs_check);
//        print_r($logs_check);
    }
    
    public function test_make_dummy_data(){
        
        global $DB;
        $this->make_dummy_data();
        
        
        
        //check log activity exists
        $log_sql = "SELECT * FROM {log} WHERE time > ? and time < ?";
        $logs = $DB->get_records_sql($log_sql, array($this->sp->start, $this->sp->end));
        mtrace("dumping logs");
        print_r($logs);
        $this->assertNotEmpty($logs);


        $ues_course= "SELECT 
                        CONCAT(usect.id,'-',ustu.userid) AS id
                        , usect.id AS sectionid
                        , usem.id AS semesterid
                        , ucourse.fullname as coursename
                        , c.id AS mdl_courseid
                        , c.idnumber
                        , ustu.id as studentid
                        , ustu.userid 
                        , u.username
                      FROM 
                        {enrol_ues_sections} AS usect
                        LEFT JOIN {enrol_ues_semesters}  AS usem
                            ON usem.id = usect.semesterid
                        LEFT JOIN {enrol_ues_courses} AS ucourse
                            ON ucourse.id = usect.courseid
                        LEFT JOIN {course} c 
                            ON c.idnumber = usect.idnumber
                        LEFT JOIN {enrol_ues_students} ustu
                            ON ustu.sectionid = usect.id
                        LEFT JOIN {user} u
                            ON ustu.userid = u.id";

        
        $ues_courses = $DB->get_records_sql($ues_course);
        $this->assertNotEmpty($ues_courses);
        
        mtrace("dumping sections");
//        print_r($ues_courses);  
        
        
        $check_user_sql = "SELECT 
                            CONCAT(u.id,'-',ustu.sectionid) AS uniqeid
                            , u.id
                            , u.username 
                            , ustu.sectionid AS ues_sectionid
                           FROM 
                            {user} u
                            INNER JOIN {enrol_ues_students} ustu
                                ON ustu.userid = u.id";
        $users = $DB->get_records_sql($check_user_sql);
        $this->assertNotEmpty($users);
        
        mtrace("dumping users");
//        print_r($users);        
        

        
        $all_contexts_sql = 'SELECT * FROM {context};';
        $all_contexts = $DB->get_records_sql($all_contexts_sql);
        $this->assertNotEmpty($all_contexts);
//        $this->assertEquals(2, count($all_contexts));
        
        $check_course_context_sql = "SELECT 
                                        CONCAT(ctx.id,'-',ra.id) AS id
                                        , c.id AS mdl_courseid
                                        , ctx.id AS contextid
                                        , ra.id AS roleassid
                                        , u.username
                                     FROM 
                                        {course}                    AS c
                                     INNER JOIN {context}           AS ctx on c.id = ctx.instanceid
                                     INNER JOIN {role_assignments}  AS ra on ra.contextid = ctx.id
                                     INNER JOIN {user}              AS u ON u.id = ra.userid";
        
        $roles = $DB->get_records_sql($check_course_context_sql);
        $this->assertNotEmpty($roles);

        
        mtrace("dumping roles");
//        print_r($roles);
        
        
        $mondo_sql =             "SELECT
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number, '_', u.idnumber) AS enrollmentId,
                u.id AS studentId, 
                usem.id AS semesterid,
                usem.year,
                usem.name,
                uc.department,
                uc.cou_number,
                us.sec_number AS sectionId,
                c.id AS mdl_courseid,
                us.id AS ues_sectionid,
                'A' AS status,
                CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number) AS uniqueCourseSection
            FROM {course} AS c
                INNER JOIN {context}                  AS ctx  ON c.id = ctx.instanceid
                INNER JOIN {role_assignments}         AS ra   ON ra.contextid = ctx.id
                INNER JOIN {user}                     AS u    ON u.id = ra.userid
                INNER JOIN {enrol_ues_sections}       AS us   ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students}       AS ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN {enrol_ues_semesters}      AS usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses}        AS uc   ON uc.id = us.courseid";
        
        $mondo = $DB->get_records_sql($mondo_sql);
        $this->assertNotEmpty($mondo);
        mtrace("dumping mondo");
//        print_r($mondo);

        
    }
    
    public function test_get_yesterday(){
        
        list($start, $end) = lmsEnrollment::get_yesterday();
        //first of all, have values been set for today?
        $this->assertTrue(isset($start));
        $this->assertTrue(isset($end));
        
        //what time is it now?
        $now = time();
        
        //compute midnight ($end) by an alternate method
        $midnight_this_morning = strtotime(strftime('%F', $now));
        $this->assertEquals($midnight_this_morning, $end);
        
        //we always work on yesterday's data;
        //$end better be less than time()
        $this->assertTrue($now > $end);
        
        //we are always working on a 24hour time period
        //start better be 86400 seconds before end
        $this->assertEquals($end,$start + 86400);
//        mtrace(vsprintf("start = %s, end = %s", array($start, $end)));

    }

    

  
/*----------------------------------------------------------------------------*/
    
    /**
     * 
     * @return type
     */
    public function test_get_active_users(){
       
            $this->make_dummy_data();
            
            $units = $this->sp->get_active_users();

            $this->assertTrue((false !== $units),sprintf("empty set of active users returned from sp->get_active_users"));
            $this->assertTrue(is_array($units));
            $this->assertNotEmpty($units);
            $keys = array_keys($units);
            $vals = array_values($units);
            $unit = array_pop($keys);
            $this->assertTrue(is_int($unit));
            mtrace("dumping active users return val");
//            print_r($units);
            return $units;
       
    }
    
    public function test_get_active_ues_semesters(){
        $this->make_dummy_data();
        $foo = $units = $this->sp->get_active_ues_semesters();
        
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        
        $keys = array_keys($units);
        $string = implode(',', $keys);
        $this->assertTrue(is_string($string));
        $this->assertTrue(is_array($keys));
        $key  = array_pop($keys);
        $this->assertTrue(is_int($key));
        
        $vals = array_values($units);
        $unit = array_pop($vals);
        
//        $this->assertInstanceOf('semester', $unit);
        $this->assertNotEmpty($unit->name);
        $this->assertNotEmpty($unit->year);
        $this->assertNotEmpty($unit->id);
        $this->assertTrue(is_numeric($unit->id));
        
        return $foo;
    }

    
    /**
     * @depends test_get_active_users
     * 
     */
    public function test_get_semester_data($active_users){

        $this->make_dummy_data();
        
        $this->assertNotEmpty($active_users);
        $semesters = $this->sp->get_active_ues_semesters();
        mtrace('dumping semesters');
//        print_r($semesters);
        $units = $this->sp->get_semester_data(array_keys($semesters));
        mtrace('dumping semester data return');
//        print_r($units);
        $this->assertTrue(($units !=false), 'no semester data returned; is there any data to return?');
        $this->assertNotEmpty($units);
//        $this->assertEquals(10,count($units));
                
        return $units;

    }
    

    
    /**
     * @depends test_get_semester_data
     */
    public function test_build_enrollment_tree($sem_data){

        $this->make_dummy_data();
        
        $this->assertTrue($sem_data != false);
        
        $semesters = $this->sp->get_active_ues_semesters();
        
        $tree = $this->sp->build_enrollment_tree($semesters);
        
        $this->assertTrue(is_array($tree));
        $this->assertNotEmpty($tree);
        
        $index = array_keys($tree);
        
        $semester = $tree[$index[0]];
        $this->assertInstanceOf('semester', $semester);
        
        $this->assertTrue(is_array($semester->sections));
        
        foreach($tree as $sem){
            if(!empty($sem->sections)){
                $index = array_keys($sem->sections);
                $section = $sem->sections[$index[0]];
                $this->assertInstanceOf('section', $section);
            }
        }
        
        $some_sections = array();
        foreach($tree as $sem){
            if(!empty($sem->sections)){
                $some_sections = array_merge($some_sections, $sem->sections);
            }
        }
        
        $this->assertNotEmpty($some_sections);
        
        //check for students
        $users = array();
        $i=0;
        foreach($tree as $sem){
            if(!empty($sem->sections)){
                foreach($sem->sections as $sec){
                    if(!empty($sec->users)){
                        foreach($sec->users as $u){
                            $users[] = $u;
                            $i++;
                        }
                    }
                }
            }
        }
        
        $this->assertNotEmpty($users);
        $this->assertInstanceof('user', $users[0]);

        return $tree;
    }
    
    
    public function test_get_activity_logs(){
        $this->make_dummy_data();
        
        $units = $this->sp->get_log_activity();
        $this->assertTrue($units!=false);
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        return $units;
    }

    /**
     * 
     * @depends test_build_enrollment_tree
     */
    public function test_populate_activity_tree($tree){
        $this->make_dummy_data();
        
        $logs  = $this->sp->get_log_activity();
        $this->assertNotEmpty($logs);
        $units = $this->sp->populate_activity_tree($logs, $tree);
        $this->assertTrue($units != false);
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        return $tree;
    }
    
    /**
     * @depends test_populate_activity_tree
     */
    public function test_calculate_time_spent($tree){
        $this->make_dummy_data();
        
        $units = $this->sp->calculate_time_spent($tree);
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        return $tree;
    }
    

        /**
     * @depends test_get_active_ues_semesters
     * 
     * @return type
     */
//    public function test_prepare_enrollment_activity_records($semesters){
////die('git here');
//            
//            $this->make_dummy_data();
//            
//            $semesters = $this->sp->get_active_ues_semesters();
//            $this->sp->build_enrollment_tree($semesters);
////            print_r($this->sp->tree);
////            $records = $this->sp->prepare_enrollment_activity_records();
//            $this->assertTrue(is_array($records));
//            $this->assertNotEmpty($records,"no records returned from 'prepare_activity_records");
//            $keys = array_keys($records);
//            $this->assertGreaterThanOrEqual(1, count($keys));
//            $this->assertInstanceOf('lsureports_lmsenrollment_record', $records[$keys[0]]);
//            return $records;
//
//    }
//    
//
    /**
     * @depends test_populate_activity_tree
     */
    public function test_save_enrollment_activity_records($tree){
        global $DB;
        $this->resetAfterTest(true);
        $this->make_dummy_data();
        
        $records = $this->sp->calculate_time_spent($tree);
        
        $result = $this->sp->save_enrollment_activity_records($records);
        $this->assertEmpty($result);
        
        $activity_records = $DB->get_records('apreport_enrol');
        print_r($activity_records);
        foreach($activity_records as $ar){
            if($ar->userid = 354){
                $this->assertEquals(20, $ar->agg_timespent);
            }
            
            if($ar->userid = 654){
                $this->assertEquals(30, $ar->agg_timespent);
            }
        }
        
        
        return $result;
    }
//    
//
// /*----------------------------------------------------------------------------*/   
//    
//
//    /**
//     * @depends test_save_enrollment_activity_records
//     * @return type
//     */
//    public function test_get_enrollment_activity_records($result){
//        
//        
////        die('git here');
//        
//        
//            $units = $this->sp->get_enrollment_activity_records();
//            $this->assertTrue($units != false, sprintf("No activity records were returned; check to be sure that someone has done something in some course..."));
//            $this->assertTrue(is_array($units));
//            $this->assertNotEmpty($units);
//            $keys = array_keys($units);
//
//            foreach($units as $unit){
//                $unit = new lmsEnrollmentRecord($units[$keys[0]]);
//                $unit->validate();
//                $this->assertInstanceOf('lmsEnrollmentRecord',$unit);
//                $this->assertGreaterThanOrEqual(0,$unit->timeSpentInClass);
//    //            $this->assertTrue($unit->endDate != '12/31/1969');
//    //            $this->assertTrue($unit->startDate != '12/31/1969');
//    //            print_r($unit);
//            }
//
//            return $units;
//
//    }
//    
//    /**
//     * @depends test_get_enrollment_activity_records
//     */
//    public function test_get_enrollment_xml($records){
//
//        $this->assertTrue(is_array($records));
//        $this->assertNotEmpty($records);
//        
//        $xml = $this->sp->buildXML($records);
//        $this->assertTrue($xml->schemaValidate('tests/lmsEnrollment.xsd'));        
//    }
//    
//    /**
//     * @depends test_get_enrollment_activity_records
//     */
//    public function test_transform_year($records){
//
//    }
//    
//    /**
//     * test everything
//     */
//    public function test_survey_enrollment(){
//        $this->resetAfterTest(true);
//        
//        $en = $this->sp;
//        
//        
//        
//        $semesters = $en->get_active_ues_semesters();
//        $this->assertTrue($semesters != false);
//        $this->assertTrue(is_array($semesters));
//        $this->assertNotEmpty($semesters);
//        
//        $tree1 = $en->build_enrollment_tree($semesters);
//        $this->assertNotEmpty($tree1);
//        
//        $logs = $this->sp->get_log_activity();
//        $this->assertNotEmpty($logs);
//        
//        $tree2 = $en->populate_activity_tree($logs,$tree1);
//        $this->assertNotEmpty($tree2);
//        
//        $records = $en->calculate_time_spent($tree2);
//        $this->assertNotEmpty($records);
//        
//        
//        
//        
//        $this->assertTrue($records != false);
//        $this->assertTrue(is_array($records));
//        $this->assertNotEmpty($records);
//        
//        $errors = $en->save_enrollment_activity_records($records);
//        $this->assertEmpty($errors);
//        
//    }
//
//    public function test_write_file(){
//        global $CFG;
//        $file = $CFG->dataroot.'/test.txt';
//        $handle = fopen($file, 'a');
//        $this->assertTrue($handle !=false);
//        $this->assertGreaterThan(0,fwrite($handle, 'hello world!'));
//        fclose($handle);
//        
//        
//    }

    public function test_reset_agg_timespent(){
        global $DB;
        $this->make_dummy_data();
        
        $original = $DB->get_records('apreport_enrol');
        $this->assertNotEmpty($original);
        $sample = array_pop($original);
        
        $cum = $sample->cum_timespent;
        $agg = $sample->agg_timespent;
        mtrace(sprintf("orig cum value is %s, agg is %s", $cum, $agg));
        
        $reset = $this->sp->reset_agg_timespent($sample);
        
        $this->assertEquals(0,$reset->agg_timespent);
        mtrace(sprintf("reset agg is %s", $reset->agg_timespent));
    }
    
    public function test_update_timespent(){
        global $DB;
        $this->make_dummy_data();
        
        $original = $DB->get_records('apreport_enrol');
        $this->assertNotEmpty($original);
        
        $sample = array_pop($original);
        $cum = $sample->cum_timespent;
        $agg = $sample->agg_timespent;
        mtrace(sprintf("orig cum value is %s, agg is %s", $cum, $agg));
        
        $updated = $this->sp->update_timespent($sample);
        
        $this->assertEquals($cum+$agg,$updated->cum_timespent);
        mtrace(sprintf("update cum value is %s", $updated->cum_timespent));
    }
    
    public function test_update_reset_db(){
        global $DB;
        $this->make_dummy_data();

        $result = $this->sp->update_reset_db();
        $this->assertTrue($result);
        
        $reset_records = $DB->get_records('apreport_enrol');
        foreach($reset_records as $rr){
            $this->assertEquals(0,$rr->agg_timespent);
        }
    }

    public function test_calculate_ts(){
        
        $result = $this->sp->calculate_ts();
        $this->assertTrue($result);
    }

    public function test_make_output(){
        
        $result = $this->sp->make_output();
        $this->assertTrue($result);
    }
        
    public function test_run(){
        
        $success = lmsEnrollment::run();
        $this->assertTrue($success);
    }
}


?>
