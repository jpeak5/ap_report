<?php
global $CFG;
require_once ($CFG->dirroot.'/local/ap_report/tests/generators/enrollment_generator.php');


class enrollment_generator_testcase extends advanced_testcase{
    public function test_true(){
        $this->assertTrue(true);
    }
    
    
    public function test_insert_users(){
        global $DB;
        //precondition
        $this->assertEquals(2,count($DB->get_records('user')));
//        
//        $users = array(
//            mdl_user::instantiate(array('id'=>9876, 'username'=>'student9876', 'idnumber'=>123456789)),
//            mdl_user::instantiate(array('id'=>1234, 'username'=>'student1234', 'idnumber'=>987654231))
//        );
//        
//        $this->createArrayDataSet($users);
//        
//        $dataset = $this->createXMLDataSet('tests/fixtures/dataset.xml');
//        $this->loadDataSet($dataset);
    }
    
}

?>
