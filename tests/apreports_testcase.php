<?php

abstract class apreports_testcase extends advanced_testcase{
    protected function make_dummy_data(){
//        self::$logid =0;
        global $DB;
        $this->resetAllData();
        $this->resetAfterTest(true);
        $DB->delete_records('log');
        
        $logs = $DB->get_records('log');
        $this->assertEmpty($logs);

        $dataset = $this->createXMLDataSet('tests/fixtures/dataset.xml');

        $this->loadDataSet($dataset);
    }

    /**
     * helper funct to assert that an  array is both an array and not empty
     * @param array $a
     */
    protected function nonempty_array($a){
        $this->assertTrue(is_array($a));
        $this->assertNotEmpty($a);
    }
    
    public function setup(){
        $this->resetAfterTest();
        $this->make_dummy_data();
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
        $this->nonempty_array($ras);
        $this->assertEquals(7, count($ras));
        
        //test mdl_courses count
        $mdl_courses_count = $DB->get_records('course');
        //course 1 always exists in moodle
        $this->assertEquals(3,count($mdl_courses_count));
        
        //test mdl_user count
        $mdl_user_count = $DB->get_records('user');
        $this->assertEquals(9,count($mdl_user_count));
        foreach($mdl_user_count as $u){
            
            $this->assertTrue(in_array($u->id, array(1,2,465,8251,9584,9541,999,555,5566)), sprintf("userid %d not found in array of expected values", $u->id));
        }
        
        //test mdl_logs count
        $mdl_logs = $DB->get_records('log');
        $this->assertNotEmpty($mdl_logs);
        $this->assertGreaterThan(11,count($mdl_logs));
        
        //test mdl_groups
        $groups = $DB->get_records('groups');
        $this->nonempty_array($groups);
        $this->assertEquals(3, count($groups));
        
        //test mdl_groups_members
        $gmember = $DB->get_records('groups_members');
        $this->nonempty_array($gmember);
        $this->assertEquals(5,count($gmember));
        
        
        //test group membership
        $members_01 = $DB->get_records('groups_members', array('groupid'=>666));
        $this->assertEquals(4,count($members_01));
        foreach($members_01 as $m){
            $this->assertTrue(in_array($m->userid, array(465, 8251,999,555)));
        }
        
        //assert role relationships
        $students_sql= sprintf("SELECT 
                                    CONCAT(ra.userid,'-',ra.contextid,'-',ra.id) as fakeid,
                                    ra.userid AS ra_userid,
                                    c.id AS courseid
                                FROM
                                    {role_assignments} ra
                                    LEFT JOIN
                                        {context} ctx on ctx.id = ra.contextid
                                    LEFT JOIN
                                        {course} c ON c.id = ctx.instanceid
                                WHERE 
                                    ra.roleid = 5;
                                ");
        
        
        
        $students = $DB->get_records_sql($students_sql);
        $count_2326 = 0;
        foreach($students as $s){
            if($s->courseid == 2326){
                $count_2326++;
            }
        }
        $this->assertEquals(2, $count_2326);
        $this->nonempty_array($students);
        $this->assertEquals(4, count($students));
        //@TODO add table-records counts checks
        
        //check user enrollment count        
        $user_enrollment_7227 = $DB->count_records('enrol_ues_students', array('sectionid'=>7227));
        $this->assertEquals(1, $user_enrollment_7227);
        $user_enrollment_743 = $DB->count_records('enrol_ues_students', array('sectionid'=>743));
        $this->assertEquals(4, $user_enrollment_743);
        
        $ra_ct = $DB->count_records('role_assignments', array('userid'=>465));
        $this->assertEquals(2, $ra_ct);
        
        //check log activity exists
        $logs = $DB->get_records('log');
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
        
        
        
        $qzs = $DB->get_records('quiz');
        $this->nonempty_array($qzs);

    }
}
?>
