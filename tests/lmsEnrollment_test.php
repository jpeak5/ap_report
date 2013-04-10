<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';
require_once('fixtures/enrollment.php');



class lmsEnrollment_testcase extends advanced_testcase{
    
    public $sp;
    public $numRows;
    public $start;
    public $eight_am_yesterday;
    public $zero_hour_yesterday;
    public $enrollment;
    
    public static $logid =0;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function toTime($in){
        return strftime('%F %T', $in);
    }
    
    public function dump_time_member(){
        $rs = $this->sp->report_start;
        $re = $this->sp->report_end;
        $ss = $this->sp->survey_start;
        $se = $this->sp->survey_end;

    }
    
    public function setUp(){
        
        $this->midnight_this_morning = strtotime(strftime('%F', time()));
        $this->zero_hour_yesterday = $this->midnight_this_morning -86400;
        $this->eight_am_yesterday = strtotime("+8 hours", $this->zero_hour_yesterday);
        $this->start = strtotime('-5 day');
        $this->resetAfterTest();
        $this->make_dummy_data();
        
        global $DB;
        $this->assertNotEmpty($DB->get_records('enrol_ues_semesters'));
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;  
        
        
        
//        $this->enrollment = new enrollment_generator();
//        $this->enrollment->generate(true);

        
    }
    
    public static function gen_id($low=null, $high=null){
        $low  = isset($low)  ? $low  : 1;
        $high = isset($high) ? $high : 9999;
        return rand($low, $high);
    }
    
    public static function gen_idnumber(){
        return rand(111111111,888999999);
    }
    
    
    private static function get_workhours_activity_timestamps_for_yesterday($total_time, $count, $start=null, $end=null){
        assert($count > 0);
        $times = array();
        $zero = strtotime(strftime('%F', time())) - 86400; //0:00 yesterday
        $start = isset($start) ? $start : strtotime("+8 hours",$zero); //yesterday 08:00
        $end   = isset($end)   ? $end   : strtotime("+8 hours",$start); //yesterday 16:00
        
        
        
        $random_start_time = rand($start, $end - $total_time); //start in business hours and finish before end
        
        $times[0] = $random_start_time;
        $times[$count-1] = $random_start_time + $total_time;
        $avg = (int)$total_time/($count-1);
        
        //this loop just fills in the gaps
        $i = $count -2;
        while($i >0){
            
            $times[$i] = $times[$i+1]-(int)$avg;
            $i--;
        }
        assert(count($times) == $count);
        assert($times[$count-1] - $times[0] == $total_time);
        return $times;
    }
    
    private function generate_lastaccess(){
        return rand($this->zero_hour_yesterday-86400, $this->zero_hour_yesterday);
    }
    
    private function generate_previous_cum_timespent(){
        return rand(0, 1000000);
    }
    
    public static function generate_log_event($time, $userid, $courseid, $is_login=false){
        
        $action = $is_login ? 'login' : 'view';
        $record = array('time'=>$time, 'userid'=>$userid,'course'=>$courseid, 'action'=>$action);
        
        return $record;
    }
    
    public static function generate_user_activity_segment($userid, $cum_time_spent, $event_count, $courseid){
        assert($event_count >= 2);
        $ts=  self::get_workhours_activity_timestamps_for_yesterday($cum_time_spent, $event_count);
        
        //log the user in such that we don't take away from the known time spent
        $usr_logs = array(self::generate_log_event($ts[0]-10, $userid, $courseid, true));
        foreach($ts as $usr1_act){
            $usr_logs[] = self::generate_log_event($usr1_act, $userid, $courseid);
        }
        return $usr_logs;
    }
    
    private function create_ues_semester($id, $year, $name, $campus, $start=null, $grades_due=null){
        $start = isset($start) ? $start : strtotime("-7 days", time());
        $end   = isset($end)   ? $end   : strtotime("+7 days", time());
        return array('id' =>$id, 'year'=>$year,'name'=>$name, 'campus'=>$campus,'classes_start'=> $start, 'grades_due'=>$end);
    }
    

    
    private function make_dummy_data(){
        self::$logid =0;
        global $DB;
        $this->resetAllData();
        $this->resetAfterTest(true);
        $DB->delete_records('log');
        
        $logs = $DB->get_records('log');
        $this->assertEmpty($logs);

        $dataset = $this->createXMLDataSet('tests/fixtures/dataset.xml');

        $this->loadDataSet($dataset);
    }
    
    public function test_dataset(){
        global $DB;
        //check log activity exists
        $log_sql = "SELECT * FROM {log} WHERE time > ? and time < ?";
        $logs = $DB->get_records_sql($log_sql, array($this->sp->start, $this->sp->end));
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

    

  
/*----------------------------------------------------------------------------*/
    

    

    
    public function test_build_user_tree(){
        $this->make_dummy_data();
        $semesters = $this->sp->enrollment->get_active_ues_semesters();
        $units = $this->sp->enrollment->get_active_students($this->sp->start, $this->sp->end);
        $this->assertTrue(!empty($units));
    }

    


    public function test_get_activity_logs(){
        $this->make_dummy_data();
        
        $units = $this->sp->get_log_activity();
        $this->assertTrue($units!=false);
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        $this->assertGreaterThan(10, count($units));
        
        return $units;
    }

    
    public function test_populate_activity_tree(){
        $this->make_dummy_data();
        $semesters = $this->sp->enrollment->get_active_ues_semesters();
        $this->sp->enrollment->get_active_users($this->sp->start, $this->sp->end);
        $data = $this->sp->enrollment->get_semester_data(array_keys($semesters),
                array_keys($this->sp->enrollment->active_users));
        $this->sp->enrollment->get_active_students($this->sp->start, $this->sp->end);
        
        $logs  = $this->sp->get_log_activity();
        $this->assertNotEmpty($logs);
        $tree = $units = $this->sp->populate_activity_tree();
        $this->assertTrue($units != false);
        
        $this->assertNotEmpty($units);
        return $tree;
    }
//    
    /**
     */
    public function test_calculate_time_spent(){
        $this->make_dummy_data();
        $semesters = $this->sp->enrollment->get_active_ues_semesters();
        $this->sp->enrollment->get_active_users($this->sp->start, $this->sp->end);
        $this->sp->enrollment->get_semester_data(array_keys($semesters),                
                array_keys($this->sp->enrollment->active_users));
        $this->sp->enrollment->get_active_students($this->sp->start, $this->sp->end);
        $this->sp->populate_activity_tree();


        $enr = $this->sp->calculate_time_spent();
        $this->assertTrue($enr!=false);
        $this->assertTrue(get_class($enr) == 'enrollment_model');
        $this->assertNotEmpty($enr);
        $this->assertNotEmpty($enr->students[465]);
        $this->assertNotEmpty($enr->students[465]->courses[2326]);
        $this->assertNotEmpty($enr->students[465]->courses[2326]->ap_report);
        
        //check correct values
        $ap = $enr->students[465]->courses[2326]->ap_report;
        $this->assertEquals(465,$ap->userid);
        $this->assertEquals(7227,$ap->sectionid);
        $this->assertEquals(5,$ap->semesterid);
        
        $this->assertEquals(1365515825,$ap->lastaccess);
        $this->assertEquals(63,$ap->agg_timespent);
        
        $ap2 = $enr->students[465]->courses[9850]->ap_report;
        $this->assertEquals(37, $ap2->agg_timespent);
        $this->assertEquals(1365515810, $ap2->lastaccess);
    }
    

    /**

     */
    public function test_save_enrollment_activity_records(){
        global $DB;
        $this->resetAfterTest(true);
        $this->make_dummy_data();
        $this->sp->enrollment->get_active_users($this->sp->start, $this->sp->end);
        $semesters = $this->sp->enrollment->get_active_ues_semesters();
        $this->sp->enrollment->get_semester_data(array_keys($semesters),                
                array_keys($this->sp->enrollment->active_users));
        $this->sp->enrollment->get_active_students($this->sp->start, $this->sp->end);
        $this->sp->populate_activity_tree();
        $this->sp->calculate_time_spent();
        $inserts = $this->sp->save_enrollment_activity_records();
        $this->assertTrue(is_array($inserts));
        $this->assertNotEmpty($inserts);
        
        $records = $DB->get_records_list('apreport_enrol', 'userid', $inserts);
        
        foreach($records as $record){
            if($record->userid == 465){
                if($record->sectionid == 7227){
                    $this->assertEquals(1364304025,$record->lastaccess);
                }elseif($record->sectionid == 743){
                    $this->assertEquals(37, $record->agg_timespent);
                }
            }
        }  
    }
    

 /*----------------------------------------------------------------------------*/   
    

    /**
     * 
     */
    public function test_get_enrollment_activity_records(){
        
        $this->resetAfterTest(true);
        
        $semesters = $this->sp->enrollment->get_active_ues_semesters();
        $this->sp->enrollment->get_active_users($this->sp->start, $this->sp->end);
        $this->sp->enrollment->get_semester_data(array_keys($semesters),                
                array_keys($this->sp->enrollment->active_users));
        $this->sp->enrollment->get_active_students($this->sp->start, $this->sp->end);
        $this->sp->populate_activity_tree();
        $this->sp->calculate_time_spent();
        $this->sp->save_enrollment_activity_records();
        
        $units = $this->sp->get_enrollment_activity_records();
        $this->assertTrue($units != false, sprintf("No activity records were returned; check to be sure that someone has done something in some course..."));
        $this->assertTrue(is_array($units));
        $this->assertNotEmpty($units);
        $keys = array_keys($units);

        foreach($units as $unit){
            $unit = new lmsEnrollmentRecord($units[$keys[0]]);
            $unit->validate();
            $this->assertInstanceOf('lmsEnrollmentRecord',$unit);
            $this->assertGreaterThanOrEqual(0,$unit->timeSpentInClass);

        }

        return $units;

    }
    
    /**
     * @depends test_get_enrollment_activity_records
     */
    public function test_get_enrollment_xml($records){
        
        $this->assertTrue(is_array($records));
        $this->assertNotEmpty($records);
        
        $xml = $this->sp->buildXML($records);
        $this->assertTrue($xml->schemaValidate('tests/schema/lmsEnrollment.xsd'));      
        
        return $xml;
    }
    


    /**
     * @depends test_get_enrollment_xml
     * @global type $CFG
     */
    public function test_write_file($xml){
        global $CFG;
        $file = $CFG->dataroot.'/test.txt';
        $handle = fopen($file, 'a');
        $this->assertTrue($handle !=false);
        $this->assertGreaterThan(0,fwrite($handle, $xml->saveXML()));
        fclose($handle);
  
    }
    
    public function test_get_enrollment(){

        $got_data = $this->sp->get_enrollment();
        $this->assertTrue($got_data);
    }
    

    public function test_run(){
        global $CFG;
        $xml = $this->sp->run();
        $this->assertInstanceOf('DOMDocument', $xml);
        $this->assertTrue($xml->schemaValidate('tests/schema/lmsEnrollment.xsd'));
        
        $this->assertNotEmpty($CFG->apreport_job_start);
        $this->assertNotEmpty($CFG->apreport_job_complete);
        
        $this->assertGreaterThan($CFG->apreport_job_start,$CFG->apreport_job_complete);
        
    }
}


?>
