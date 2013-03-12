<?php
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereport/lib.php';



class lmsEnrollment_testcase extends basic_testcase{
    
    public $sp;
    public $numRows;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function setUp(){
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
    }
  
    public function test_get_active_users(){
//        die('got here');
        $u = $this->sp->get_active_user_ids();
        $this->assertNotEmpty($u);
        $this->assertTrue(is_int($u[0]));
  
    }
    
    public function test_getData(){
//        die('got here');
        $rows = $this->sp->getData($this->numRows);
        $this->assertNotEmpty($rows, "No DB Rows");
        $this->assertEquals($this->numRows, count($rows));
        return $rows;
    }
    
    /**
     * 
     * 
     */
    public function test_get_semester_data(){
        $semesters = $this->sp->get_active_ues_semesters();
        $units = $this->sp->get_semester_data(array_keys($semesters));
        
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
    

    public function test_get_active_user_ids(){
        $units = $this->sp->get_active_user_ids();
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        
        $this->assertTrue(is_string(implode(',',$units)));
        $unit = array_pop($units);
        $this->assertTrue(is_int($unit));
    }
    
    
    /**
     * @depends test_getData
     * 
     */
    public function test_buildXML($rows){
        
        //get a reference to the report
        $report = $this->sp->buildXML($rows);
        
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
