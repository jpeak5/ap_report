<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';



class lmsEnrollment_testcase extends advanced_testcase{
    
    public $sp;
    public $numRows;
    public $start;

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
        $this->start = strtotime('-5 day');
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
        
    }
    
    private function make_dummy_data(){
                global $DB;
        
        
        $this->resetAfterTest();
        $DB->delete_records('log');
        
        $logs = $DB->get_records('log');
        $this->assertEmpty($logs);
        
        $user1 = 354;
        $user2 = 654;
        $ues_sectionid1 = 6666;
        $ues_sectionid2 = 3445;
        
        $courseid1 = 6545;
        $courseid2 = 7798;
        
        $semesterid = 5;
        
        $data = array(
                    'enrol_ues_courses' => array(
                        array('id'=>55,'department'=>'CSC','cou_number'=>'7404','fullname'=>'fullnameof_CSC  7404'),
                        array('id'=>66,'department'=>'BIOL','cou_number'=>'1001','fullname'=>'fullnameof_BIOL 1001'),
                    ),
                    'user' => array(
                        array('username'=>'jdoe1', 'email'=>'jdoe1@example.com', 'id'=>$user1, 'idnumber'=>87687324),
                        array('username'=>'jdoe2', 'email'=>'jdoe2@example.com', 'id'=>$user2, 'idnumber'=>87684545),
                    ),
                    'enrol_ues_students' => array(
                        array('userid'=>$user1, 'sectionid'=>$ues_sectionid1,'status'=>'enrolled'), //jdoe1
                        array('userid'=>$user2, 'sectionid'=>$ues_sectionid2,'status'=>'enrolled'),  //jdoe2
                        array('userid'=>$user2, 'sectionid'=>$ues_sectionid1,'status'=>'enrolled')  //jdoe2
                    ),
                    'enrol_ues_semesters' => array(
                        array('id' =>$semesterid, 'year'=>2013,'name'=>'Spring', 'campus'=>'LSU','classes_start'=> 1358143200, 'grades_due'=>1369458000),
                    ),
                    'enrol_ues_sections' => array(
                        array('id'=>$ues_sectionid1,'courseid'=>55,'semesterid'=>$semesterid,'idnumber'=>'2013SPRINGCSC7404', 'sec_number'=>'001'),  //jdoe1
                        array('id'=>$ues_sectionid2,'courseid'=>66,'semesterid'=>$semesterid,'idnumber'=>'2013SPRINGBIOL1001', 'sec_number'=>'002'), //jdoe2
                    ),
                    'course' => array(
                        array('id'=>$courseid1,'idnumber'=>'2013SPRINGCSC7404'),  //jdoe1
                        array('id'=>$courseid2,'idnumber'=>'2013SPRINGBIOL1001'), //jdoe2
                    ),
                    'context' => array(
                        array('id'=>77,'instanceid'=>$courseid1), //jdoe1
                        array('id'=>88,'instanceid'=>$courseid2)  //jdoe2
                    ),
                    'role_assignments' => array(
                        array('contextid'=>77,'userid'=>$user1, 'roleid'=>5), //jdoe1
                        array('contextid'=>77,'userid'=>$user2, 'roleid'=>5), //jdoe2
                        array('contextid'=>88,'userid'=>$user2, 'roleid'=>5)  //jdoe2
                    ),
                    'log' => array(
                        array('time'=>1363791600, 'userid'=>$user1,'course'=>$courseid1, 'action'=>'login'),
                        array('time'=>1363791610, 'userid'=>$user1,'course'=>$courseid1, 'action'=>'view'),
                        array('time'=>1363791620, 'userid'=>$user1,'course'=>$courseid1, 'action'=>'view'),
//                        array('time'=>1363791630, 'userid'=>$user1,'course'=>$courseid1, 'action'=>'view'),
                        //jdoe1 has spent 20 seconds in course $courseid1
                        array('time'=>1363791700, 'userid'=>$user2,'course'=>$courseid2, 'action'=>'login'),
                        array('time'=>1363791710, 'userid'=>$user2,'course'=>$courseid2, 'action'=>'view'),
                        array('time'=>1363791720, 'userid'=>$user2,'course'=>$courseid2, 'action'=>'view'),
                        array('time'=>1363791740, 'userid'=>$user2,'course'=>$courseid2, 'action'=>'view'),
                        //jdoe1 has spent 30 seconds in course $ues_sectionid2
                        
                    ),
                    'apreport_enrol' => array(
                        array('timestamp'=>1363791920,'lastaccess' => 1363791620,'agg_timespent'=>456, 'cum_timespent'=>1515,'userid'=>$user1, 'sectionid'=>$ues_sectionid1, 'semesterid'=>$semesterid)
                    )

                );
        $dataset = $this->createArrayDataSet($data);
        $this->loadDataSet($dataset);


        
        $this->assertTrue($DB->record_exists('user', array('username'=>'jdoe1')));
        $this->assertTrue($DB->record_exists('user', array('username'=>'jdoe2')));
        $this->assertTrue($DB->record_exists('enrol_ues_students', array('userid'=>$user1)));
        $this->assertTrue($DB->record_exists('enrol_ues_students', array('userid'=>'654')));
        $this->assertTrue($DB->record_exists('enrol_ues_semesters', array('id'=>'5')));
        $this->assertTrue($DB->record_exists('enrol_ues_courses', array('id'=>55)));
        $this->assertTrue($DB->record_exists('enrol_ues_courses', array('id'=>66)));
        $this->assertTrue($DB->record_exists('enrol_ues_sections', array('id'=>$ues_sectionid1)));
        $this->assertTrue($DB->record_exists('enrol_ues_sections', array('id'=>$ues_sectionid2)));
        $this->assertTrue($DB->record_exists('enrol_ues_sections', array('idnumber'=>'2013SPRINGCSC7404')));
        $this->assertTrue($DB->record_exists('course', array('id'=>$courseid1)));
        $this->assertTrue($DB->record_exists('course', array('id'=>$courseid2)));
        $this->assertTrue($DB->record_exists('role', array('id'=>5)));
        $this->assertTrue($DB->record_exists('context', array('id'=>77)));
        $this->assertTrue($DB->record_exists('context', array('id'=>88)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>77)));
        $this->assertTrue($DB->record_exists('role_assignments', array('contextid'=>88)));
    }
    
    public function test_make_dummy_data(){
        
        global $DB;
        $this->make_dummy_data();
        
        
        
        //check log activity exists
        $log_sql = "SELECT * FROM {log} WHERE time > ? and time < ?";
        $logs = $DB->get_records_sql($log_sql, array($this->sp->start, $this->sp->end));
        $this->assertNotEmpty($logs);
        mtrace("dumping logs");
//        print_r($logs);

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
        
        $all_roles_sql = 'SELECT * from {role_assignments}';
        $all_roles = $DB->get_records_sql($all_roles_sql);
        $this->assertNotEmpty($all_roles);
        $this->assertEquals(3,count($all_roles));
        
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
        
        //check that count matches
        $ct_roles = $DB->count_records('role_assignments');
        $this->assertEquals(3, $ct_roles);
        
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
