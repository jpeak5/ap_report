<?php

global $CFG;
require_once($CFG->dirroot.'/local/ap_report/lib.php');
require_once('apreports_testcase.php');

class lmsCoursework_testcase extends apreports_testcase{
    
    public function test_init(){
        $unit = new lmsCoursework();
        $this->assertNotEmpty($unit->courses);
   
    }

}
?>
