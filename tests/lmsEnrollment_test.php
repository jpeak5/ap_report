<?php
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereport/lib.php';



class lmsEnrollment_testcase extends advanced_testcase{
    
    public $sp;
    public $numRows;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function setUp(){
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
    }
  
    public function test_get_last_run_time(){
        $unit = lmsEnrollment::get_last_run_time();
        $this->assertTrue(is_numeric($unit));
        $this->assertGreaterThan(0,$unit);
    }


    
    /**
     * @depends test_get_active_users
     * 
     */
    public function test_get_semester_data($active_users){
        die('got here');
        $this->assertNotEmpty($active_users);
        $semesters = $this->sp->get_active_ues_semesters();
        $units = $this->sp->get_semester_data(array_keys($semesters));
        $this->assertTrue($units, 'no semester data returned; is there any data to return?');
        $this->assertNotEmpty($units);
//        $this->assertEquals(10,count($units));
                
        return $units;
    }
    
    public function test_get_active_ues_semesters(){
//        die('got here');
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
     * @depends test_get_semester_data
     */
    public function test_build_enrollment_tree(){
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

    }
    

    /**
     * @depends test_get_active_ues_semesters
     * @return type
     */
    public function test_prepare_activity_records($semesters){

//        $semesters = $this->sp->get_active_ues_semesters();
        $this->sp->build_enrollment_tree($semesters);
        $records = $this->sp->prepare_activity_records();
        $this->assertTrue(is_array($records));
        $this->assertNotEmpty($records);
        $keys = array_keys($records);
        $this->assertInstanceOf('lsureports_lusenrollment_record', $records[$keys[0]]);
        return $records;
    }
    
    /**
     * @depends test_prepare_activity_records
     */
    public function test_save_activity_records($records){
        $this->resetAfterTest(true);
        $result = $this->sp->save_activity_records($records);
        $this->assertEmpty($result);
    }
    
    public function test_get_active_users(){
  
        $units = $this->sp->get_active_users(lmsEnrollment::get_last_run_time());
        $this->assertTrue((false !== $units),sprintf("empty set of active users returned from sp->get_active_users(%d)", lmsEnrollment::get_last_run_time()));
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        $keys = array_keys($units);
        $vals = array_values($units);
        $unit = array_pop($keys);
        $this->assertTrue(is_int($unit));
        return $units;
    }
    
    

    public function test_get_activity_records(){
        $units = $this->sp->get_activity_records();
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
     * @depends test_get_activity_records
     */
    public function test_get_enrollment_xml($records){

        $this->assertTrue(is_array($records));
        $this->assertNotEmpty($records);
        
        $xml = $this->sp->buildXML($records);
        $this->assertTrue($xml->schemaValidate('tests/lmsEnrollment.xsd'));        
    }
    
    /**
     * @depends test_get_activity_records
     */
    public function test_transform_year($records){

    }

    public function test_write_file(){
        global $CFG;
        $file = $CFG->dataroot.'/test.txt';
        $handle = fopen($file, 'a');
        $this->assertTrue($handle !=false);
        $this->assertGreaterThan(0,fwrite($handle, 'hello world!'));
        fclose($handle);
        
        
    }
}


?>
