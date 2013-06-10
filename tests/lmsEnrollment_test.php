<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';


class lmsEnrollment_testcase extends advanced_testcase{
    
    public $reportClass;

    public function setUp(){

        $this->resetAfterTest();
        $this->make_dummy_data();
        $this->reportClass = new lmsEnrollment();
    }
    
    private function make_dummy_data(){
        global $DB;
        
        $this->resetAllData();
        $this->resetAfterTest(true);

        $DB->delete_records('log');
        $this->assertEmpty($DB->get_records('log'));

        $dataset = $this->createXMLDataSet('tests/fixtures/dataset.xml');
        $this->loadDataSet($dataset);
        
        $this->assertNotEmpty($DB->get_records('log'));
        $this->assertNotEmpty($DB->get_records('enrol_ues_semesters'));
    }
    
    public function test_dataset(){
        global $DB;
        //check log activity exists
        $log_sql = "SELECT * FROM {log} WHERE time > ? and time < ?";
        $logs = $DB->get_records_sql($log_sql, array($this->reportClass->proc_start, $this->reportClass->proc_end));
        $this->assertNotEmpty($logs);
    }
    
    public function test_get_yesterday(){
        
        list($start, $end) = apreport_util::get_yesterday();
        //first of all, have values been set for today?
        $this->assertTrue(isset($start));
        $this->assertTrue(isset($end));
        
        //what time is it now?
        $now = time();
        
        //compute midnight ($end) by an alternate method
        $midnight_this_morning = strtotime(strftime('%F', $now));
        $this->assertEquals($midnight_this_morning, $end);
        
        //we always work on yesterday's data;
        //$end better be less than time()
        $this->assertTrue($now > $end);
        
        //we are always working on a 24hour time period
        //start better be 86400 seconds before end
        $this->assertEquals($end,$start + 86400);


        // 2000-10-10 ->  971154000
        $this->assertTrue(strftime('%F', 971154000) == '2000-10-10');
        
        list($oct09_st, $oct09_end) = apreport_util::get_yesterday(strftime('%F', 971154000));
        $this->assertTrue(strftime('%F %T',$oct09_st) == '2000-10-09 00:00:00');
        $this->assertTrue(strftime('%F %T',$oct09_end) == '2000-10-10 00:00:00');
        
        list($oct08_s, $oct08_e) = apreport_util::get_yesterday(strftime('%F', $oct09_st));
        $this->assertTrue(strftime('%F %T',$oct08_s) == '2000-10-08 00:00:00');
        $this->assertTrue(strftime('%F %T',$oct08_e) == '2000-10-09 00:00:00');
    }


    public function test_construct(){
        
    }
}


?>
