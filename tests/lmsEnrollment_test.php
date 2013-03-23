<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';
require_once('fixtures/enrollment.php');



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
        assert($count > 0);
        $times = array();
        $zero = strtotime(strftime('%F', time())) - 86400; //0:00 yesterday
        $start = isset($start) ? $start : strtotime("+8 hours",$zero); //yesterday 08:00
        $end   = isset($end)   ? $end   : strtotime("+8 hours",$start); //yesterday 16:00
        
        
        
        $random_start_time = rand($start, $end - $total_time); //start in business hours and finish before end
        
        $times[0] = $random_start_time;
        $times[$count-1] = $random_start_time + $total_time;
        $avg = (int)$total_time/($count-1);
        
        //this loop just fills in the gaps
        $i = $count -2;
        while($i >0){
            
            $times[$i] = $times[$i+1]-(int)$avg;
            $i--;
        }
        assert(count($times) == $count);
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
        assert($event_count >= 2);
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
        
        //unit does not, aparently blow away the default mdl contexts
        $default_contexts = $DB->get_records('context');
        $this->assertNotEmpty($default_contexts);
        
        
        //unit, aparently, does not completely remove all courses; perhaps course 1 always remains
        $default_courses = $DB->get_records('course');
        $this->assertNotEmpty($default_courses);
        
        //unit always gives us two users
        $default_users = $DB->get_records('user');
        $this->assertNotEmpty($default_users);
        $this->assertEquals(2, count($default_users));
        
/*----------------------------------------------------------------------------*/        
        $this->make_dummy_data();
/*----------------------------------------------------------------------------*/        
        
        /**
         * for sanity's sake, let's be sure that the only records in the 
         * db are those we expect: either we have created them, or we and unit 
         * have created them...
         */
        
        
        
        //test context count
        $contexts = $DB->get_records('context');
        $this->assertEquals(count($this->enrollment->mdl_contexts) + count($default_contexts), count($contexts));
        
        //test role-assignments count
        $ras = $DB->get_records('role_assignments');
        $this->assertEquals(count($this->enrollment->mdl_role_ass), count($ras));
        
        //test mdl_courses count
        $mdl_courses_count = $DB->get_records('course');
        $this->assertEquals(count($mdl_courses_count), count($this->enrollment->mdl_courses) + count($default_courses));
        
        //test mdl_user count
        $mdl_user_count = $DB->get_records('user');
        $this->assertEquals(count($default_users)+count($this->enrollment->mdl_users), count($mdl_user_count));
        
        //test mdl_logs count
        $mdl_logs = $DB->get_records('log');
        $this->assertEquals(count($mdl_logs), count($this->enrollment->mdl_logs));
        
        //@TODO add table-records counts checks
        
        
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
        $this->assertNotEmpty($tree);
        $units = $this->sp->calculate_time_spent($tree);
        $this->assertTrue($units!=false);
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
