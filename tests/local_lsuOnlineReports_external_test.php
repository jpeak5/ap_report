<?php
 
defined('MOODLE_INTERNAL') || die();
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereports/lib.php';
require_once $CFG->dirroot.'/webservice/tests/helpers.php';
require_once($CFG->dirroot . '/local/lsuonlinereports/externallib.php');


class local_lsuonlinereports_external_test extends externallib_advanced_testcase{
    
    //dev.mdl test-user = test_external_user:Test!@#456
    
    
    public function test_get_enrollment() {
        global $USER;
 
        $this->resetAfterTest(true);
 
        // Set the required capabilities by the external function
//        $contextid = context_XXXX::instance()->id;
//        $roleid = $this->assignUserCapability('moodle/CAPABILITYNAME', $contextid);
 
        $params = array('limit'=>'8');
 
        $returnvalue = local_lsuonlinereports_external::get_enrollment($params);
 
        // We need to execute the return values cleaning process to simulate the web service server
        $cleanReturnValue = external_api::clean_returnvalue(local_lsuonlinereports_external::get_enrollment_returns(), $returnvalue);
 
        // Some PHPUnit assert
        $this->assertEquals("you asked for 8 records", $cleanReturnValue);
 
        // Call without required capability
//        $this->unassignUserCapability('moodle/CAPABILITYNAME', $contextid, $roleid);
//        $this->setExpectedException('required_capability_exception');
//        $returnvalue = COMPONENT_external::FUNCTION_NAME($params);
 
    }
    

}
?>
