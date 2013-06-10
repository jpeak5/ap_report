<?php
global $CFG;
require_once ($CFG->dirroot.'/local/ap_report/classes/dbal.php');


class enrollment_dataset_generator{

    public $user_count;
    
    public static function create_users($users){
        
    }
    
    public function create_quiz(){
        $q = array(
            'id'    =>  1,
            'intro' =>  'garbage text',
            'name'  =>  'hc quiz 1',
            'timeclose'=>time(),
            'grade' =>  10,
            'course'=>  5543,
            'questions' => 'sugjgksdfg'
        );
        return $q;
    }

    public function create_coursework_scenario(){
        /**
         * require the following table entries
         * quiz
         * 
         */
        
        $da = function($c,$r){
          return array('cols'=>$c, 'rows'=>$r);
        };
        
        $tables = array(
            'ucourse'   =>'enrol_ues_courses',
            'user'      =>'user',
            'ustu'      =>'enrol_ues_students',
            'usem'=>'enrol_ues_semesters',
            'usect'     =>'enrol_ues_sections',
            'course'    =>'course',
            'ctx'       =>'context',
            'ra'        =>'role_assignments',
            'log'       =>'log',
            'g'         =>'groups',
            'gm'        =>'groups_members',
            'mgi'       =>'grade_items',
            'mgg'       =>'grade_grades',
            'mgc'       =>'grade_categories',
            'quiz'      =>'quiz',
            'qatt'      =>'quiz_attempts'          
            );
        
        
//--------------------------------------------------------------------//
//----------------------------- enrollment ITEMS ---------------------//
//--------------------------------------------------------------------//

        foreach(array_keys($tables) as $t){
            ${"{$t}_rows"} = array();
        }

        
        
        
        $date = new DateTime('today');
        $now = $date->getTimestamp();
        $current_semester = array(
            'id'            =>5,
            'year'          =>strftime('%Y', $now),
            'name'          =>'Current',
            'campus'        =>'LSU',
            'session_key'   =>null,
            'classes_start' =>$now-(86400*21),
            'grades_due'    =>$now+(86400*14)
            ); //end two weeks ahead
        
        $enr1 = array(
            'cid'       => '1234',
            'idnumber'  => 'COURSE001_SPRING',
            'usectid'   => '4321',
            'semester'  => $current_semester,
            'ucourseid' => 1,
            'ucourse_fname' => 'COURSE001',
            'ucourse_dept'  => 'COURSE',
            'ucourse_num'   => 4001,
            'usect_num'     => 003
        );

        $user1 = array(9541,'student-3','exampleuser','student-3@example.com',700188683,'student-3');

        $enrol = function($user, $enrollments) 
            use(&$usem_rows,
                &$ucourse_rows,
                &$ustu_rows,
                &$usect_rows,
                &$course_rows,
                &$ctx_rows,
                &$ra_rows,
                &$user_rows)
                {
            $user_rows[] = $user;
            foreach($enrollments as $e){
                $usem_rows[]        = array_values($e['semester']);
                
                $flag = false;
                foreach($ucourse_rows as $r){
                    if($r[0] == $e['ucourseid']){
                        $flag = true;
                    }
                }
                if(!$flag) $ucourse_rows[]     = array($e['ucourseid'],$e['ucourse_fname'],$e['ucourse_dept'],$e['ucourse_num']);
                
                $flag = false;
                foreach($ustu_rows as $r){
                    if($r[0] == $user[0].$e['usectid']){
                        $flag = true;
                    }
                }
                if(!$flag) $ustu_rows[]        = array($user[0].$e['usectid'],$user[0], $e['usectid'],3,'Enrolled');
                
                $flag = false;
                foreach($usect_rows as $r){
                    if($r[0] == $e['usectid']){
                        $flag = true;
                    }
                }
                if(!$flag) $usect_rows[]       = array($e['usectid'],$e['idnumber'],$e['cid'],$e['semester']['id'],$e['usect_num']);
                
                
                
                $flag = false;
                foreach($course_rows as $r){
                    if($r[0] == $e['cid']){
                        $flag = true;
                    }
                }
                if(!$flag) $course_rows[]      = array($e['cid'],$e['idnumber']);
                
                $flag = false;
                foreach($ctx_rows as $r){
                    if($r[0] == $e[cid].$user[0]){
                        $flag = true;
                    }
                }
                if(!$flag) $ctx_rows[]         = array($e['cid'].$user[0],50,$e['cid']);
                
                $ra_rows[]          = array(count($ra_rows)+1,5,$user[0],'contextid',$e['cid'].$user[0]);
                
            }
        };
        
        $enrol($user1,array($enr1));

        
        //ues semesters
        $usem_rows = $ucourse_rows = $usect_rows = $ustu_rows = array();
        $user_rows = $course_rows  = $ctx_rows = $groups = $groups_members = array();
        
        $usem_cols      = array('id','year','name','campus','session_key','classes_start','grades_due');
        $usem_rows[]     = array(5,2013,'Spring','LSU',null,1358143200,time()+(86400*14)); //end two weeks ahead
        
        //ues_courses
        $ucourse_cols   = array('id', 'fullname', 'department', 'cou_number');
        $ucourse_rows[] = array(2656,'BIOL1335','BIOL', 1335);
        $ucourse_rows[] = array(3613,'AGRI4009','AGRI', 4009);
        
        //ues_students
        $ustu_cols      = array('id', 'userid', 'sectionid', 'credit_hours', 'status');
        $ustu_rows[]    = array(1415, 465, 7227, 5, 'enrolled');
        $ustu_rows[]    = array(5442, 465,  743, 4, 'enrolled');
        $ustu_rows[]    = array(4861, 9584, 743, 5, 'enrolled');
        $ustu_rows[]    = array( 452, 8251, 743, 5, 'enrolled');
        $ustu_rows[]    = array(1413, 9541, 743, 5, 'enrolled');
        
        //ues sections
        $usect_cols     = array('id','idnumber','courseid','semesterid','sec_number');
        $usect_rows[]   = array(7227,'BIOL13356099',2656, 5, '006');
        $usect_rows[]   = array(743,'AGRI40095354',3613,5,'009');
        
        //mdl_courses
        $course_cols    = array('id','idnumber');
        $course_rows[]  = array(2326,'BIOL13356099');
        $course_rows[]  = array(9850,'AGRI40095354');
        
        //mdl_context
        $ctx_cols       = array('id','contextlevel','instanceid');
        $ctx_rows[]     = array(2042,50,2326);
        $ctx_rows[]     = array(333,50,9850);
        $ctx_rows[]     = array(334,50,3613);
        
        //mdl_role_asignment
        $ra_cols        = array('id','roleid','userid','contextid');
        $ra_rows[]      = array(1,5,465,2042);
        $ra_rows[]      = array(2,5,465,333);
        $ra_rows[]      = array(3,5,8251,2042);
        $ra_rows[]      = array(4,5,8251,333);
        $ra_rows[]      = array(5,3,999,2042);
        $ra_rows[]      = array(6,4,555,2042);
        $ra_rows[]      = array(7,3,5566,2042);
        $ra_rows[]      = array(8,5,9854,334);
        
        //mdl_user
        $user_cols      = array('id','firstname','lastname','email', 'idnumber','username');
        $user_rows[]    = array(999, 'teacher-0','exampleuser','teacher-0@example.com',666777555,'teacher-0');
        $user_rows[]    = array(5566,'teacher-1','exampleuser','teacher-1@example.com',666777545,'teacher-1');
        $user_rows[]    = array(555, 'coach-0',  'exampleuser',  'coach-0@example.com',123777555,'coach-0');
        $user_rows[]    = array(9584,'student-2','exampleuser','student-2@example.com',253071515,'student-2');
        $user_rows[]    = array(9541,'student-3','exampleuser','student-3@example.com',700188683,'student-3');
        
        //user id 465 is in 2 sections
        $user_rows[]    = array(465, 'student-0','exampleuser','student-0@example.com',472725024,'student-0');

        //user id 8251 has only one section
        $user_rows[]    = array(8251,'student-1','exampleuser','student-1@example.com',163360288,'student-1');

        //mdl_groups
        $g_cols         = array('id','courseid','name');
        $g_rows[]       = array(666,2326,'control-01');
        $g_rows[]       = array(667,2326,'control-02');
        $g_rows[]       = array(7,2326,'control-02');
        
        //mdl_groups_members
        $gm_cols        = array('id','groupid','userid','timeadded');
        $gm_rows[]      = array(45,666,465,1358143200);
        $gm_rows[]      = array(46,666,8251,1358143200);
        $gm_rows[]      = array(47,666,999,1358143200);
        $gm_rows[]      = array(48,666,555,1358143200);
        $gm_rows[]      = array(49,667,5566,1358143200);
        
        
        //mdl_log
        $log_cols       = array('id','time','userid','course', 'action');
        //note that log_rows is populated by a dedicated function 
        $log_rows       = $this->generate_activity_sequence();
        
        //--------------------------------------------------------------------//
        //----------------------------- coursework ITEMS ---------------------//
        //--------------------------------------------------------------------//
        
        /**
         * grade tables: grade_item, grade_categories, grade_grades
         */
        $mgi_cols       = array('id','courseid', 'itemtype', 'itemmodule', 'iteminstance', 'aggregationcoef', 'categoryid');
        $mgc_cols       = array('id','fullname','courseid', 'timecreated','timemodified');
        $mgg_cols       = array('finalgrade', 'userid', 'itemid');
        
        $mgi_rows = $mgc_rows = $mgg_rows = array();
        
        $mgi_rows[]     = array(1,2326,'mod','quiz',1,1,9);
        $mgc_rows[]     = array(432,'categ fullname',9, time()-rand(100,10000),time());
        $mgg_rows[]     = array(95,465,1);
        
        
        /**
         * Tables required: quiz, quiz_attempts
         * Dependencies: grade item with `itemmodule = 'quiz'`
         */
        $quiz_rows = $qatt_rows = array();
        
        $quiz_cols    = array('id','intro','name','timeclose','grade','course','questions');
        $quiz_rows[]  = array(1,'intro-test-1','quiz-test-1',time()+rand(0,100000), 100, 2326,'question dfjkskjgf');
        $quiz_rows[]  = array(2,'intro-test-2','quiz-test-2',time()+rand(0,100000), 10, 2326, 'question 1 gfshsf');

        $qatt_cols    = array('id','quiz', 'timefinish', 'layout');
        $qatt_rows[]  = array(84,1,time()-10, 'default layout (reqd field)');
        
        //build xml file
        $xdoc = new DOMDocument();
        $root = $xdoc->createElement('dataset');
        foreach($tables as $table_key => $table_name){
            $t = $xdoc->createElement('table');
            $t->setAttribute('name', $table_name);
//            mtrace($table_key);
            foreach(${$table_key.'_cols'} as $tc){
                $column = $xdoc->createElement('column', $tc);
                $t->appendChild($column);
            }
            foreach(${$table_key.'_rows'} as $tr=>$vals){
//                mtrace($table_key);
                $row = $xdoc->createElement('row');
                foreach($vals as $v){
                    $value = $xdoc->createElement('value', $v);
                    $row->appendChild($value);
                }
                $t->appendChild($row);
            }
            $root->appendChild($t);
        }
        $xdoc->appendChild($root);
        return $xdoc;
    }
    
    public function create_course($usect=null, $course=null){
        
    }
    

    
    
    public function get_standard_sequence(){
        $objs = $this->get_standard_sequence();
        $logs = array();
        foreach($objs as $o){
            $logs[] = (array)$o;
        }
        return $logs;
    }
    
    /**
     * generates log events for our test behavior case
     */
    public function generate_activity_sequence(){
        $logs = array();
        $ts = self::get_sequence_start();
        $lid = 1;
        
        $user = new stdclass();
        $user->a = 465;
        $user->b = 9584;
        
        $course = new stdClass();
        $course->a = 2326;
        $course->b = 9850;
        
        $log = function($uid, $ts, $cid=null, $login=false) use (&$lid){
            $action = ($login || is_null($cid)) ? 'login' : 'view';
            $cid = ($login || is_null($cid)) ? 1 : $cid;
            $params = array('id'=>$lid,'time'=>$ts,'userid'=>$uid, 'course'=>$cid, 'action'=>$action);
            $lid++;
            return tbl_model::instantiate_from_tablename('log', $params);
        };
        
        $view = function($offsets, $u, $c) use (&$ts, $log){
            $logs = array();
            while(count($offsets)>0){
                $ts+=array_shift($offsets);
                $logs[] = $log($u, $ts, $c);
            }
            return $logs;
        };
        
        //*************** begin sequesnce ***************//
        /**
         * the following 
         */
        
        //log the user in
        $logs[] = $log($user->a, $ts,null, true);
        
        //********** look at some stuff **********//
        $logs   = array_merge($logs,$view(array(10,10,10,10,10), $user->a, $course->a));

        
        
        //*************** switch courses ***************//
        $logs   = array_merge($logs,$view(array(10,10,10,4,3), $user->a, $course->b));
        
        //*************** switch courses ***************//
        $logs   = array_merge($logs,$view(array(2,5,13), $user->a, $course->a));
        
        
        //*************** new login ***************//
        $ts+=4*3600; //four hours later...
        $logs[] = $log($user->a, $ts);
        
        //********** look at some stuff **********//
        $logs   = array_merge($logs,$view(array(7,10), $user->a, $course->b));
        
        //*************** switch courses ***************//
        $logs   = array_merge($logs,$view(array(10,5), $user->a, $course->a));
        
//        mtrace(print_r($logs));
        return $logs;
    }
    
    /**
     * returns the timestamp of our sequence,
     * which always returns the ts for yesterday @ 9:38:06 AM
     * @return int
     */
    public static function get_sequence_start(){
        $d = strftime('%F', time());
        $date = new DateTime($d.' 9:38:06');
        return $date->getTimestamp()-86400;
    }

}


?>
