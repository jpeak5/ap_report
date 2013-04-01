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
    
    public static $logid =0;

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
        $this->start = strtotime('-5 day');
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
        
        $this->resetAfterTest();
        $this->make_dummy_data();
//        $this->enrollment = new enrollment_generator();
//        $this->enrollment->generate(true);
//        $this->active_users = $this->sp->get_active_users();
//        print_r($this->enrollment->enrollment->verify);
        
    }
    
    public static function gen_id($low=null, $high=null){
        $low  = isset($low)  ? $low  : 1;
        $high = isset($high) ? $high : 9999;
        return rand($low, $high);
    }
    
    public static function gen_idnumber(){
        return rand(111111111,888999999);
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
        $record = array('time'=>$time, 'userid'=>$userid,'course'=>$courseid, 'action'=>$action);
        
        return $record;
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
        self::$logid =0;
        global $DB;
        $this->resetAllData();
        $this->resetAfterTest(true);
//        $DB->delete_records('mdl_user');
        $DB->delete_records('log');
        
        $logs = $DB->get_records('log');
        $this->assertEmpty($logs);
        
        
        
//        $data = array(
//                    'enrol_ues_courses' => $this->enrollment->ues_courses,
//                    'user' => $this->enrollment->mdl_users,
//                    'enrol_ues_students' => $this->enrollment->ues_students,
//                    'enrol_ues_semesters' => $this->enrollment->ues_semesters,
//                    'enrol_ues_sections' => $this->enrollment->ues_sections,
//                    'course' => $this->enrollment->mdl_courses,
//                    'context' => $this->enrollment->mdl_contexts,
//                    'role_assignments' => $this->enrollment->mdl_role_ass,
//                    'log' => $this->enrollment->mdl_logs,
//                    'apreport_enrol' => array(
//                        array(
//                            'timestamp'=> $this->zero_hour_yesterday,
//                            'lastaccess' => $this->generate_lastaccess(),
//                            'agg_timespent'=>456, 
//                            'cum_timespent'=>1515,
//                            'userid'=>354, 
//                            'sectionid'=>6666, 
//                            'semesterid'=>5
//                            )
//                    )
//
//                );
        
        
        
//        $dataset = $this->createArrayDataSet($data);
        $dataset = $this->createXMLDataSet('tests/dataset.xml');
//        die(print_r($dataset));
        $this->loadDataSet($dataset);

//        die('finished loading');
//        $this->enrollment->mdl_logs = $DB->get_records('log');
//
//
//
//        $semesters_rows = $DB->get_records('enrol_ues_semesters');
//        $this->assertNotEmpty($semesters_rows);
//        foreach($semesters_rows as $sr){
//            $this->assertTrue(!empty($sr->id));
//        }
//        
//        $ues_section_rows = $DB->get_records('enrol_ues_sections');
//        $this->assertNotEmpty($ues_section_rows);
//        foreach($ues_section_rows as $usr){
//            $this->assertTrue(isset($usr->id));
//        }
//        
//        $ues_students_rows = $DB->get_records('enrol_ues_students');
//        $this->assertNotEmpty($ues_students_rows);
//        
//
//        $logs_check = $DB->get_records('log');
//        $this->assertNotEmpty($logs_check);
//        print_r($logs_check);
    }
    
    public function test_make_dummy_data(){
        
        $this->resetAfterTest();
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
        
        
/*----------------------------------------------------------------------------*/        
        
/*----------------------------------------------------------------------------*/        
        
        /**
         * for sanity's sake, let's be sure that the only records in the 
         * db are those we expect: either we have created them, or we and unit 
         * have created them...
         */
        
        
        
        //test context count
        $contexts = $DB->get_records('context');
        $this->assertNotEmpty($contexts);
        
        
        $semesters = $DB->get_records('enrol_ues_semesters');
        $this->assertNotEmpty($semesters);
        $this->assertEquals(1, count($semesters));
        
        //check section count
        $sections = $DB->get_records('enrol_ues_sections');
        $this->assertNotEmpty($sections);
        $this->assertEquals(2, count($sections));
        
        
        //test role-assignments count
        $ras = $DB->get_records('role_assignments');
        $this->assertNotEmpty($ras);
        
        //test mdl_courses count
        $mdl_courses_count = $DB->get_records('course');
        //course 1 always exists in moodle
        $this->assertEquals(3,count($mdl_courses_count));
        
        //test mdl_user count
        $mdl_user_count = $DB->get_records('user');
        $this->assertEquals(6,count($mdl_user_count));
        
        //test mdl_logs count
        $mdl_logs = $DB->get_records('log');
        $this->assertNotEmpty($mdl_logs);
        $this->assertGreaterThan(11,count($mdl_logs));
        
        //@TODO add table-records counts checks
        
        //check user enrollment count        
        $user_enrollment_7227 = $DB->count_records('enrol_ues_students', array('sectionid'=>7227));
        $this->assertEquals(1, $user_enrollment_7227);
        $user_enrollment_743 = $DB->count_records('enrol_ues_students', array('sectionid'=>743));
        $this->assertEquals(4, $user_enrollment_743);
        
        $ra_ct = $DB->count_records('role_assignments', array('userid'=>465));
        $this->assertEquals(2, $ra_ct);
        
        //check log activity exists
        $log_sql = "SELECT * FROM {log} WHERE time > ? and time < ?";
        $logs = $DB->get_records_sql($log_sql, array($this->sp->start, $this->sp->end));
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
        print_r($roles);
        
        
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
        print_r($mondo);

        
//        $this->assertEquals(count($mondo), count($users));
        
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
        
//            $this->make_dummy_data();
            
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
//        $this->make_dummy_data();
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
        $this->assertNotEmpty($unit->ues_semester->name);
        $this->assertNotEmpty($unit->ues_semester->year);
        $this->assertNotEmpty($unit->ues_semester->id);
        $this->assertTrue(is_numeric($unit->ues_semester->id));
        
        return $foo;
    }
    
    public function test_build_user_tree(){
        $this->make_dummy_data();
        $semesters = $this->sp->get_active_ues_semesters();
        $units = $this->sp->build_user_tree();
        $this->assertTrue(!empty($units));
    }

    
    /**
     * 
     * 
     */
    public function test_get_semester_data(){

        $this->make_dummy_data();
        
//        $active_users = $this->sp->get_active_users();
//        $this->assertNotEmpty($active_users);
        $semesters = $this->sp->get_active_ues_semesters();
        
        //make sure the test data matches what we expect
        $this->assertEquals(1, count($semesters));

        $units = $this->sp->get_semester_data(array_keys($semesters));

        $this->assertTrue(($units !=false), 'no semester data returned; is there any data to return?');
        $this->assertNotEmpty($units);
        $this->assertEquals(2,count($units));
        return $units;

    }
//    
//
//    
//    /**
//     * @depends test_get_semester_data
//     */
//    public function test_build_enrollment_tree($sem_data){
//
//        $this->make_dummy_data();
//        
//        
//        $this->assertTrue($sem_data != false);
//        
//        $semesters = $this->sp->get_active_ues_semesters();
//        //make sure the test data matches what we expect
//        $this->assertEquals(count($this->enrollment->ues_semesters), count($semesters));
//        
//        $tree = $this->sp->build_enrollment_tree();
//        
////        $this->assertTrue(is_array($tree));
//        $this->assertNotEmpty($tree);
//        
//        $index = array_keys($tree->semesters);
//        
//        $semester = $tree->semesters[$index[0]];
//        $this->assertInstanceOf('semester', $semester);
//        
//        $this->assertTrue(is_array($semester->courses));
//        
//        foreach($tree->semesters as $sem){
//            
//            if(!empty($sem->courses)){
//                $index = array_keys($sem->courses);
//                $section = $sem->courses[$index[0]];
//                $this->assertInstanceOf('course', $section);
//                //ensure that test data has been recreated in production structure
//                foreach($this->enrollment->ues_sections as $usec){
//                    $this->assertTrue(array_key_exists($usec['id'], $sem->courses));
//                }
//            }
//        }
//        
//        $some_sections = array();
//        foreach($tree->semesters as $sem){
//            if(!empty($sem->courses)){
//                $some_sections = array_merge($some_sections, $sem->courses);
//            }
//        }
//        
//        $this->assertNotEmpty($some_sections);
//        
//
//
//        return $tree;
//    }
//    
//    
    public function test_get_activity_logs(){
        $this->make_dummy_data();
        
        $units = $this->sp->get_log_activity();
        $this->assertTrue($units!=false);
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        $this->assertGreaterThan(10, count($units));
        
        return $units;
    }
//
    /**
     * 
     * 
     */
    public function test_populate_activity_tree(){
        $this->make_dummy_data();
        $semesters = $this->sp->get_active_ues_semesters();
        $data = $this->sp->get_semester_data(array_keys($semesters));
        $this->sp->build_user_tree();
        
        $logs  = $this->sp->get_log_activity();
        $this->assertNotEmpty($logs);
        $tree = $units = $this->sp->populate_activity_tree();
        $this->assertTrue($units != false);
        
        $this->assertNotEmpty($units);
        return $tree;
    }
//    
    /**
     */
    public function test_calculate_time_spent(){
        $this->make_dummy_data();
        $semesters = $this->sp->get_active_ues_semesters();
        $this->sp->get_semester_data(array_keys($semesters));
        $this->sp->build_user_tree();
        $this->sp->populate_activity_tree();


        $enr = $this->sp->calculate_time_spent();
        print_r($enr);
        $this->assertTrue($enr!=false);
        $this->assertTrue(get_class($enr) == 'enrollment_model');
        $this->assertNotEmpty($enr);
        $this->assertNotEmpty($enr->students[465]);
        $this->assertNotEmpty($enr->students[465]->courses[2326]);
        $this->assertNotEmpty($enr->students[465]->courses[2326]->ap_report);
        
        //check correct values
        $ap = $enr->students[465]->courses[2326]->ap_report;
        $this->assertEquals(465,$ap->userid);
        $this->assertEquals(7227,$ap->sectionid);
        $this->assertEquals(5,$ap->semesterid);
        
        $this->assertEquals(1364744025,$ap->lastaccess);
        $this->assertEquals(63,$ap->agg_timespent);
        
        $ap2 = $enr->students[465]->courses[9850]->ap_report;
        $this->assertEquals(37, $ap2->agg_timespent);
        $this->assertEquals(1364744010, $ap2->lastaccess);
    }
    

    /**

     */
    public function test_save_enrollment_activity_records(){
        global $DB;
        $this->resetAfterTest(true);
        $this->make_dummy_data();
        $semesters = $this->sp->get_active_ues_semesters();
        $this->sp->get_semester_data(array_keys($semesters));
        $this->sp->build_user_tree();
        $this->sp->populate_activity_tree();
        $this->sp->calculate_time_spent();
        $inserts = $this->sp->save_enrollment_activity_records();
        $this->assertTrue(is_array($inserts));
        $this->assertNotEmpty($inserts);
        
        $records = $DB->get_records_list('apreport_enrol', 'userid', $inserts);
        
        foreach($records as $record){
            if($record->userid == 465){
                if($record->sectionid == 7227){
                    $this->assertEquals(1364304025,$record->lastaccess);
                }elseif($record->sectionid == 743){
                    $this->assertEquals(37, $record->agg_timespent);
                }
            }
        }  
    }
    

 /*----------------------------------------------------------------------------*/   
    

    /**
     * 
     */
    public function test_get_enrollment_activity_records(){
        
        $this->resetAfterTest(true);
        
        $semesters = $this->sp->get_active_ues_semesters();
        $this->sp->get_semester_data(array_keys($semesters));
        $this->sp->build_user_tree();
        $this->sp->populate_activity_tree();
        $this->sp->calculate_time_spent();
        $this->sp->save_enrollment_activity_records();
        
        $units = $this->sp->get_enrollment_activity_records();
        $this->assertTrue($units != false, sprintf("No activity records were returned; check to be sure that someone has done something in some course..."));
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        $keys = array_keys($units);

        foreach($units as $unit){
            $unit = new lmsEnrollmentRecord($units[$keys[0]]);
            $unit->validate();
            $this->assertInstanceOf('lmsEnrollmentRecord',$unit);
            $this->assertGreaterThanOrEqual(0,$unit->timeSpentInClass);
//            $this->assertTrue($unit->endDate != '12/31/1969');
//            $this->assertTrue($unit->startDate != '12/31/1969');
//            print_r($unit);
        }

        return $units;

    }
    
    /**
     * @depends test_get_enrollment_activity_records
     */
    public function test_get_enrollment_xml($records){
        
        $this->assertTrue(is_array($records));
        $this->assertNotEmpty($records);
        
        $xml = $this->sp->buildXML($records);
        $this->assertTrue($xml->schemaValidate('tests/lmsEnrollment.xsd'));      
        
        return $xml;
    }
    
//    /**
//     * @depends test_get_enrollment_activity_records
//     */
//    public function test_transform_year($records){
//
//    }
    

    /**
     * @depends test_get_enrollment_xml
     * @global type $CFG
     */
    public function test_write_file($xml){
        global $CFG;
        $file = $CFG->dataroot.'/test.txt';
        $handle = fopen($file, 'a');
        $this->assertTrue($handle !=false);
        $this->assertGreaterThan(0,fwrite($handle, $xml->saveXML()));
        print_r($xml->saveXML());
        fclose($handle);
  
    }
    
    public function test_get_enrollment(){

        $got_data = $this->sp->get_enrollment();
        $this->assertTrue($got_data);
    }
    
//
//    public function test_reset_agg_timespent(){
//        global $DB;
//        $this->make_dummy_data();
//        
//        $original = $DB->get_records('apreport_enrol');
//        $this->assertNotEmpty($original);
//        $sample = array_pop($original);
//        
//        $cum = $sample->cum_timespent;
//        $agg = $sample->agg_timespent;
//        mtrace(sprintf("orig cum value is %s, agg is %s", $cum, $agg));
//        
//        $reset = $this->sp->reset_agg_timespent($sample);
//        
//        $this->assertEquals(0,$reset->agg_timespent);
//        mtrace(sprintf("reset agg is %s", $reset->agg_timespent));
//    }
//    
//    public function test_update_timespent(){
//        global $DB;
//        $this->make_dummy_data();
//        
//        $original = $DB->get_records('apreport_enrol');
//        $this->assertNotEmpty($original);
//        
//        $sample = array_pop($original);
//        $cum = $sample->cum_timespent;
//        $agg = $sample->agg_timespent;
//        mtrace(sprintf("orig cum value is %s, agg is %s", $cum, $agg));
//        
//        $updated = $this->sp->update_timespent($sample);
//        
//        $this->assertEquals($cum+$agg,$updated->cum_timespent);
//        mtrace(sprintf("update cum value is %s", $updated->cum_timespent));
//    }
//    
//    public function test_update_reset_db(){
//        global $DB;
//        $this->make_dummy_data();
//
//        $result = $this->sp->update_reset_db();
//        $this->assertTrue($result);
//        
//        $reset_records = $DB->get_records('apreport_enrol');
//        foreach($reset_records as $rr){
//            $this->assertEquals(0,$rr->agg_timespent);
//        }
//    }
//
//    public function test_calculate_ts(){
//        
//        $result = $this->sp->calculate_ts();
//        $this->assertTrue($result);
//    }
//
//    public function test_make_output(){
//        
//        $result = $this->sp->make_output();
//        $this->assertTrue($result);
//    }
//        
    public function test_run(){
        global $CFG;
        $xml = $this->sp->run();
        $this->assertInstanceOf('DOMDocument', $xml);
        $this->assertNotEmpty($xml->schemaValidate('tests/lmsEnrollment.xsd'));
        
        $this->assertNotEmpty($CFG->apreport_job_start);
        $this->assertNotEmpty($CFG->apreport_job_complete);
        
        $this->assertGreaterThan($CFG->apreport_job_start,$CFG->apreport_job_complete);
        
    }
}


?>
