<?php
global $CFG;
require_once($CFG->dirroot.'/local/ap_report/lib.php');
class classes_testcase extends advanced_testcase{
    
    private function make_dummy_data(){
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
    private function nonempty_array($a){
        $this->assertTrue(is_array($a));
        $this->assertNotEmpty($a);
    }
    
    public function test_make_dummy_data(){
        global $DB;
        $this->resetAfterTest();
        $this->make_dummy_data();
        
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
    
    public function setup(){
        $this->resetAfterTest();
        $this->make_dummy_data();
    }
    
    public function test_enrollment_model_construct(){
        
        $e = new enrollment_model();
        $this->assertInstanceOf('enrollment_model', $e);
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
    

}
?>
