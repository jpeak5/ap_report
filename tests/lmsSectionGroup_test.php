<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';

require_once('apreports_testcase.php');

class lmsSectionGroup_testcase extends apreports_testcase{
    
    public function setup(){
        parent::setup();
        global $CFG;
//        mtrace('assuming config values for SectionGroups report');
        $CFG->apreport_primy_inst_roles = '3';
        $CFG->apreport_coach_roles ='4,19,20,21';
    }
    
    public function test_get_groups_primary_instructors(){
        global$DB;
        $enr = new enrollment_model();
        $instructors = $enr->get_groups_primary_instructors();
        $this->assertTrue($instructors != false);
        $this->nonempty_array($instructors);
        foreach($instructors as $i){
            $this->assertTrue(in_array($i->primaryinstructoremail,array('teacher-0@example.com','teacher-1@example.com')));
            $this->assertInstanceOf('lmsSectionGroupRecord', $i);
        }        
    }
    
    
    public function test_get_groups_coaches(){
        global$DB;
        $enr = new enrollment_model();
        $coaches = $enr->get_groups_coaches();
        $this->assertTrue($coaches != false);
        $this->nonempty_array($coaches);
        foreach($coaches as $i){
            $this->assertTrue(in_array($i->coachemail,array('coach-0@example.com')));
            $this->assertInstanceOf('lmsSectionGroupRecord', $i);
        }        
    }
    
    public function test_merge_instructors_coaches(){
        global$DB;
        $enr = new enrollment_model();
        
        $instructors = $enr->get_groups_primary_instructors();
        $this->nonempty_array($instructors);
        
        $coaches = $enr->get_groups_coaches();
        $this->nonempty_array($coaches);
        
        $lmsSG = new lmsSectionGroup();
        $groups = $lmsSG->merge_instructors_coaches();
        $this->nonempty_array($groups);
        
        foreach($groups as $g){
            $this->assertInstanceOf('lmsSectionGroupRecord', $g);
        }
        $this->assertEquals(2,count($groups));
    }
    
    public function test_run(){
        $unit = new lmsSectionGroup();
        $xdoc = $unit->run();
        $this->assertInstanceOf('DOMDocument', $xdoc);
    }
    
}
?>
