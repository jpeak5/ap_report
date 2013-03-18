<?php
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereport/lib.php';



class lmsEnrollment_testcase extends advanced_testcase{
    
    public $sp;
    public $numRows;
    public $start;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function setUp(){
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
        $this->start = strtotime('-1 day');
    }
    
    
    public function test_get_last_run_time(){
        
        $unit = lmsEnrollment::get_last_run_time();
        $this->assertTrue(is_numeric($unit));
        $this->assertGreaterThan(0,$unit);
    }
    
    /**
     * if this returns true, there is reason to run the script
     * @return boolean
     */
    public function test_activity_check(){
        
        $act = $this->sp->activity_check();
        $this->assertTrue($act);
        return $act;
    }
  
/*----------------------------------------------------------------------------*/
    
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
     * @depends test_get_active_users
     * @depends test_activity_check
     */
    public function test_get_semester_data($active_users, $act){
        if($act){
        
        $this->assertNotEmpty($active_users);
        $semesters = $this->sp->get_active_ues_semesters();
        $units = $this->sp->get_semester_data(array_keys($semesters));
        $this->assertTrue(($units !=false), 'no semester data returned; is there any data to return?');
        $this->assertNotEmpty($units);
//        $this->assertEquals(10,count($units));
                
        return $units;
        }else{
            return false;
        }
    }
    

    
    /**
     * @depends test_get_semester_data
     */
    public function test_build_enrollment_tree($sem_data){

        $this->assertTrue($sem_data != false);
//  die('git here');
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
     * 
     * @return type
     */
    public function test_prepare_activity_records($semesters){
//die('git here');

    //        $semesters = $this->sp->get_active_ues_semesters();
            $this->sp->build_enrollment_tree($semesters);
            $records = $this->sp->prepare_activity_records();
            $this->assertTrue(is_array($records));
            $this->assertNotEmpty($records."no records returned from 'prepare_activity_records");
            $keys = array_keys($records);
            $this->assertGreaterThanOrEqual(1, count($keys));
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
    

 /*----------------------------------------------------------------------------*/   
    

    /**
     * 
     * @return type
     */
    public function test_get_activity_records(){
        
        
//        die('git here');
        
        
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
