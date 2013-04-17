<?php
global $CFG;
require_once ($CFG->dirroot.'/local/ap_report/tests/generators/enrollment_generator.php');
require_once ($CFG->dirroot.'/local/ap_report/tests/apreports_testcase.php');


class enrollment_generator_testcase extends advanced_testcase{
    public function test_true(){
        $this->assertTrue(true);
    }
    
    public function test_dbal_tbl_model(){
        $params = array(
            'name'=>'test quiz',
            'timeclose'=>time(),
            'grade'=>100,
            'id'=>1,
            'course'=>2236
        );
        $q = tbl_model::instantiate_from_tablename('quiz', $params);
        
    }
    
    public function test_create_quiz(){
        $this->resetAfterTest();
        $unit = new enrollment_dataset_generator();
        $q = $unit->create_quiz();
        $this->assertNotEmpty($q);
        
        $qzs = array_values($q);
        
        $quiz_cols    = array('id','intro','name','timeclose','grade','course','questions');
        $ds = array('quiz'=>array($quiz_cols,$qzs));
        $dataset = $this->createArrayDataSet($ds);
        $this->loadDataSet($dataset);
    }
    
    
    public function test_insert_users(){
        global $DB;
        //precondition
        $this->assertEquals(2,count($DB->get_records('user')));

    }
    
    public function test_create_coursework_scenario(){
        $unit = new enrollment_dataset_generator();
        $xdoc = $unit->create_coursework_scenario();
        $xdoc->format = true;


    }
    
    public function test_get_sequence_start(){
        $unit = new enrollment_dataset_generator();
        $ts = $unit->get_sequence_start();
        $this->assertTrue(is_int($ts));
    }
    
    public function test_generate_activity_sequence(){
        $unit = new enrollment_dataset_generator();
        $logs = $unit->generate_activity_sequence();
        $this->assertNotEmpty($logs);
    }
}

?>
