<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';
require_once $CFG->dirroot.'/local/ap_report/tests/generators/enrollment_generator_test.php';


class lmsEnrollment_testcase extends advanced_testcase{

    public $xml;

    public function setUp(){
        $gen  = new enrollment_dataset_generator();
        $this->xml = $gen->create_coursework_scenario();

        $this->resetAfterTest();
        $this->make_dummy_data();
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
        $unit = new lmsEnrollment();
        //check log activity exists
        $log_sql = "SELECT * FROM {log} WHERE time > ? and time < ?";
        $logs = $DB->get_records_sql($log_sql, array($unit->proc_start, $unit->proc_end));
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


    public function test_construct_todays_process_time_range(){
        $unit = new lmsEnrollment('preview');

        $am = new DateTime('today');
        $this->assertEquals($am->getTimestamp(), $unit->proc_start);

        $pm = new DateTime('tomorrow');
        $this->assertEquals($unit->proc_end,$pm->getTimestamp());

    }

    public function test_construct_yesterdays_process_time_range(){
        $reprocess = new lmsEnrollment('reprocess');
        $cron      = new lmsEnrollment('cron');

        $am = new DateTime('yesterday');
        $this->assertEquals($am->getTimestamp(), $reprocess->proc_start);
        $this->assertEquals($am->getTimestamp(), $cron->proc_start);

        $pm = new DateTime('today');
        $this->assertEquals($reprocess->proc_end,$pm->getTimestamp());
        $this->assertEquals($cron->proc_end,$pm->getTimestamp());

    }

    public function test_earliest_classes_start_date(){
        global $DB;
        $unit = new lmsEnrollment('preview');
        $sql = sprintf("
            SELECT classes_start FROM {enrol_ues_semesters}
            WHERE classes_start < %s AND grades_due > %s
            ORDER BY classes_start ASC LIMIT 1",$unit->proc_end,$unit->proc_end);
        $earliest = $DB->get_record_sql($sql);
        $this->assertEquals($earliest->classes_start, $unit->report_start);
    }

    public function test_construct_todays_report_time_range(){
        $latest  = new lmsEnrollment('view_latest');
        $preview = new lmsEnrollment('preview');

        $start = apreport_util::get_earliest_semester_start();
        $end   = new DateTime('tomorrow');

        $this->assertEquals($start, $latest->report_start);
        $this->assertEquals($start, $preview->report_start);

        $this->assertEquals($end->getTimestamp(), $latest->report_end);
        $this->assertEquals($end->getTimestamp(), $preview->report_end);
    }

    public function test_construct_yesterdays_report_time_range(){
        $current  = new lmsEnrollment('view_current');
        $reproc   = new lmsEnrollment('reprocess');
        $cron     = new lmsEnrollment('cron');

        $start = apreport_util::get_earliest_semester_start();
        $end   = new DateTime('today');

        $this->assertEquals($start, $current->report_start);
        $this->assertEquals($start, $reproc->report_start);
        $this->assertEquals($start, $cron->report_start);

        $this->assertEquals($end->getTimestamp(), $current->report_end);
        $this->assertEquals($end->getTimestamp(), $reproc->report_end);
        $this->assertEquals($end->getTimestamp(), $cron->report_end);
    }

    public function test_getEnrollment(){
        $unit = new lmsEnrollment('cron');
        $enr  = $unit->getEnrollment();
        $this->assertGreaterThan(0,count($enr));
//        mtrace(var_dump($enr));

        //this is validating the integrity of the dataset
        //and should go somewhere else
        $xpath  = new DOMXpath($this->xml);
        $xrows  = $xpath->query("//table[@name='user']/row");
//        $this->assertEquals($xrows->length, count($enr));

        $this->assertGreaterThan(0, count($enr));
    }

    public function test_process(){

    //        465-2326, 85 sec
    //        465-9850, 54 sec
        $unit  = new lmsEnrollment('cron');
        $newRecs = $unit->processUsers(
                 $unit->getEnrollment(),
                 $unit->getLogs(),
                 $unit->getPriorRecords()
             );

        $this->assertGreaterThan(0, count($newRecs));

        $this->assertTrue(in_array('465-9850', array_keys($newRecs)));
        $this->assertTrue(in_array('465-2326', array_keys($newRecs)));
        $this->assertEquals(37,$newRecs['465-9850']->timespentinclass);
        $this->assertEquals(63,$newRecs['465-2326']->timespentinclass);
    }

}


?>
