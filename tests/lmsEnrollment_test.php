<?php
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereport/lib.php';



class lmsEnrollment_testcase extends basic_testcase{
    
    public $sp;
    public $rows;
    public $numRows;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function setUp(){
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;
        $this->rows = $this->sp->getData($this->numRows);
        
    }
    
    
    
    
    /**
     * 
     * @return array integer ids of active semesters
     */
    public function test_get_active_ues_semester_ids(){
        $ints = $this->sp->get_active_ues_semester_ids();
        $this->assertNotEmpty($ints);
        $this->assertTrue(is_int(array_pop($ints)));
        return $ints;
    }
    
    /**
     * @depends test_get_active_ues_semester_ids
     * @param int $semesterid semester id
     * @return array section ids for the given semester
     */
    public function test_get_active_section_ids(array $semesterid){
        $ints = $this->sp->get_active_section_ids($semesterid);
        $this->assertNotEmpty($ints);
        $this->assertTrue(is_int(array_pop($ints)));
        return $ints;
    }
    
    /**
     * @depends test_get_active_section_ids
     * @param int $sectionid section id
     * @return array student ids for a given section
     */
    public function test_get_studentids_per_section(array $sectionid){
        $ints = $this->sp->get_studentids_per_section($sectionid);
        $this->assertNotEmpty($ints);
        $this->assertTrue(is_int(array_pop($ints)));
        return $ints;
    }
    
    /**
     * @depends test_get_studentids_per_section
     * @param int $sectionid a ues section id 
     * @return array moodle course id numbers
     */
    public function test_get_moodle_course_id(array $sectionid){
        $ints = $this->sp->get_moodle_course_id($sectionid);
        $this->assertNotEmpty($ints);
        $this->assertTrue(is_int(array_pop($ints)));
        return $ints;
    }
    
    
    public function test_calculateTimeSpent(){
        
        $semesterids = $this->sp->get_semesters();
        
        $this->assertNotEmpty($semesterids);
        $this->assertTrue(is_int(array_pop($semesterids)));
        
        $sectionids = $this->sp->get_active_section_ids($semesterids);
        $start = microtime(true);
        $inner = 0;
        foreach($sectionids as $s){
            $students = $this->sp->get_studentids_per_section($s);
            $courseuid = $this->sp->get_moodle_course_id($s);
            foreach($students as $st){
                $time = $this->sp->get_time_spent_today_section($st, $courseuid);
                $inner++;
                if($time > 0){
                    sprintf("found activity! user %d spent %d seconds in course id %d", $st, $time, $courseuid);
                }
            }
            if(time() - microtime() > 60){
                die(sprintf("one minute has elapsed; we have processed %d", $inner));
            }
        }
        echo sprintf("that took %f seconds ", microtime() - $start). microtime() - $start;
        die();
        
    }
    
    
    
    
    
    public function test_getData(){
        $this->assertNotEmpty($this->rows, "No DB Rows");
        $this->assertEquals($this->numRows, count($this->rows));

    }
    
    public function test_get_semesters(){
        list($sems, $sids) = $this->sp->get_semesters();
        $this->assertNotEmpty($sems);
        $this->assertNotEmpty($sids);
        
        $semesters = array('semesters'=>$sems,'semester_ids'=>$sids);
        return $semesters;
    }
    
    /**
     * @depends test_get_semesters
     */
    public function test_get_sections($semesters){
        
        list($sections, $section_ids) = $this->sp->get_sections($semesters['semester_ids']);
        $this->assertNotEmpty($section_ids);
        $this->assertNotEmpty($sections);
        
        return $sections = array('sections'=>$sections, 'section_ids'=>$section_ids);
    }
    
    /**
     * @depends test_get_sections
     */
    public function test_get_courses($sections){
        
        $s = array_pop($sections['sections']);
        $this->assertInstanceOf('stdClass', $s);
        $this->assertObjectHasAttribute('courseid',$s);
        
        
        list($courses, $cids) = $this->sp->get_courses($sections['sections']);
        
        $this->assertNotEmpty($cids);
        $this->assertNotEmpty($courses);
    }
    
    /**
     * This test only exists to verify the refactoring of getData()
     */
    public function test_getDataOpt(){
        $optRows = $this->sp->getDataOpt($this->numRows);

        $this->assertNotEmpty($optRows, "No DB Rows");
        
        $this->assertEquals($this->numRows, count($optRows));
        
        //get a reference to the report
        $report = $this->sp->buildXML($optRows);
        
        //are we getting xml
        $this->assertInstanceOf('DOMDocument', $report);
        
        //right encoding?
        $this->assertEquals('UTF-8', $report->encoding);
        
        //valid?
        $this->assertTrue($report->schemaValidate('tests/lmsEnrollment.xsd'));
        
        //get a reference to the OLD SQL report
        $reportOld = $this->sp->buildXML($this->rows);
//        $this->assertEquals($reportOld, $report);
//        $this->assertEquals($reportOld->saveXML(), $report->saveXML());
    }

    public function test_buildXML(){
        
        //get a reference to the report
        $report = $this->sp->buildXML($this->rows);
        
        //are we getting xml
        $this->assertInstanceOf('DOMDocument', $report);
        
        //right encoding?
        $this->assertEquals('UTF-8', $report->encoding);
        
        //valid?
        $this->assertTrue($report->schemaValidate('tests/lmsEnrollment.xsd'));
    }
    
    public function test_getReport(){
        $report = $this->sp->getReport('XML', 10);
        
        $doc = new DOMDocument();
        $doc->loadXML($report);
        
        //valid?
        $this->assertTrue($doc->schemaValidate('tests/lmsEnrollment.xsd'));
        
    }
    
    
}


?>
