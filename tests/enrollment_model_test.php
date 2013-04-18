<?php
global $CFG;
require_once($CFG->dirroot.'/local/ap_report/lib.php');
require_once('apreports_testcase.php');

class enrollment_model_testcase extends apreports_testcase{

    
    public function test_make_dummy_data(){
        global $DB;
        $this->resetAfterTest();
        
        $semesters = $DB->get_records('enrol_ues_semesters');
        $this->nonempty_array($semesters);
        $this->assertNotEmpty($semesters[5]);
        
        $sql = "SELECT 
                    usect.id            AS ues_sectionid,
                    usect.sec_number    AS ues_sections_sec_number,                    
                    c.id                AS mdl_courseid,
                    c.shortname         AS mdl_course_shortname,
                    
                    ucourse.department  AS ues_course_department,
                    ucourse.cou_number  AS ues_cou_number
                FROM {enrol_ues_sections} usect
                INNER JOIN {enrol_ues_courses} ucourse
                    on usect.courseid = ucourse.id
                INNER JOIN {enrol_ues_semesters} usem
                    on usect.semesterid = usem.id
                INNER JOIN {course} c
                    on usect.idnumber = c. idnumber
                WHERE 
                    usem.id = 5
                AND 
                    usect.idnumber <> ''";
        //verify courses
        $courses = $DB->get_records_sql($sql);
        
        $this->nonempty_array($courses);
        $this->assertEquals(2, count($courses));
        $this->assertTrue(array_key_exists(743, $courses));
        $this->assertTrue(array_key_exists(7227, $courses));
        
        //verify users
        $sql = "SELECT 
                    ustu.id,
                    ustu.sectionid,
                    ustu.userid,
                    ustu.credit_hours,
                    ustu.status,
                    usect.id AS sectionid,
                    user.id AS userid,
                    user.username
                FROM 
                    {enrol_ues_students} ustu
                        LEFT JOIN 
                            {enrol_ues_sections} usect 
                                ON ustu.sectionid = usect.id
                        LEFT JOIN
                            {user} user
                                ON ustu.userid = user.id
                WHERE 
                    usect.semesterid = 5
                    AND 
                    sectionid IS NOT NULL
                    ";
        $students = $DB->get_records_sql($sql);
        $this->nonempty_array($students);
        $this->assertEquals(5, count($students));
    }
    

    
    public function test_enrollment_model_construct(){
        
        $e = new enrollment_model();
        $this->assertInstanceOf('enrollment_model', $e);
        $this->assertEquals(2, count($e->courses));
        $this->assertInstanceOf('course', $e->courses[2326]);
        $this->assertInstanceOf('course', $e->courses[9850]);
        return $e;
    }
    
    /**
     * @depends test_enrollment_model_construct
     */
    public function test_get_active_ues_semesters($e){
        
        $ul = enrollment_model::get_active_ues_semesters();
        $this->nonempty_array($ul);
        $this->assertTrue(array_key_exists(5,$ul));
        $this->assertInstanceOf('semester', $ul[5]);
        
        return $e;
    }
    
    /**
     * @depends test_enrollment_model_construct
     * @return type
     */
    public function test_get_active_users($e){
            list($start, $end) = apreport_util::get_yesterday();
            $units = $e->get_active_users($start, $end);

            $this->assertTrue((false !== $units),sprintf("empty set of active users returned from sp->get_active_users"));
            $this->assertTrue(is_array($units));
            $this->assertNotEmpty($units);
            $keys = array_keys($units);
            $vals = array_values($units);
            $unit = array_pop($keys);
            $this->assertTrue(is_int($unit));

            return $e;
       
    }
    
    /**
     * @depends test_enrollment_model_construct
     * 
     */
    public function test_get_semester_data($e){
        $this->assertNotEmpty($e->active_users);
        $units = $e->get_semester_data(
                array_keys(enrollment_model::get_active_ues_semesters()), 
                array_keys($e->active_users)
                );

        $this->assertTrue(($units !=false), 'no semester data returned; is there any data to return?');
        $this->assertNotEmpty($units);
        $this->assertEquals(2,count($units));
        return $units;

    }
    
    public function test_get_groups_with_students(){
        global $DB;
        $this->assertEquals(3,count($DB->get_records('groups')));
        $e = new enrollment_model();
        $x = $e->get_groups_with_students();
        $this->nonempty_array($x);
//        mtrace(print_r($x));
    }
    

    
    public function test_coursework_get_quiz(){
        global $DB;
        $e = new enrollment_model();
        $semesters = $e->get_active_ues_semesters(null, true);
        $courses = $e->get_all_courses($semesters, true);
        
        //ensure that the dataset includes at least one quiz
        $qrec = $DB->get_records('quiz');
        $this->nonempty_array($qrec);
        
        //ensure grade items
//        $mgi_cols       = array('id','courseid', 'itemtype', 'itemmodule', 'iteminstance', 'aggregationcoef', 'categoryid');
//        $mgc_cols       = array('id','fullname','courseid');
//        $mgg_cols       = array('finalgrade', 'userid', 'itemid');
//        
//        $mgi_rows = $mgc_rows = $mgg_rows = array();
//        
//        $mgi_rows[]     = array(1,2326,'mod','quiz',1,1,9);
//        $mgc_rows[]     = array(432,'categ fullname',9);
//        $mgg_rows[]     = array(95,465,1);
        
        $mgi = $DB->get_records('grade_items');
        $this->nonempty_array($mgi);
        $this->assertGreaterThan(0,count($mgi));
        
        $mgc = $DB->get_records('grade_categories');
        $this->nonempty_array($mgc);
        $this->assertGreaterThan(0,count($mgc));
//        mtrace(print_r($mgc));
        
        $mgg = $DB->get_records('grade_grades');
        $this->assertGreaterThan(0,count($mgg));
        
        
//        $qzs = $e->coursework_get_quiz($courses);
//        $this->nonempty_array($qzs);
//        $keys = array_keys($qzs);
//        $sample = $qzs[$keys[0]];
//        $this->assertTrue(object_property_exists($sample, 'itemname'));
        
    }
    
    public function test_get_scorm_datesubmitted(){
        $t = time();
        $sec = 24;
        
        $start = new DateTime(strftime('%F %T',$t));
        $intvl = new DateInterval(preg_replace('/\.[0-9]+S/', 'S', 'PT24.0S'));
        
//        mtrace(preg_replace('/\.[0-9]+S/', 'S', 'PT24.0S'));
        
        $unit = lmsCoursework::get_scorm_datesubmitted($t, 'PT24.0S');
        
        $end = $start->add($intvl);
        $this->assertEquals($end->getTimestamp(), $t+24);
        $this->assertEquals($t+24, $unit);
        
    }
    

}

?>
