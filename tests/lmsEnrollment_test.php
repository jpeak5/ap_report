<?php
global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';
//require_once('fixtures/enrollment.php');



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
    
//    private function create_ues_semester($id, $year, $name, $campus, $start=null, $grades_due=null){
//        $start = isset($start) ? $start : strtotime("-7 days", time());
//        $end   = isset($end)   ? $end   : strtotime("+7 days", time());
//        return array('id' =>$id, 'year'=>$year,'name'=>$name, 'campus'=>$campus,'classes_start'=> $start, 'grades_due'=>$end);
//    }
    

    
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
        $logs = $DB->get_records_sql($log_sql, array($this->sp->proc_start, $this->sp->proc_end));
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


}


?>
