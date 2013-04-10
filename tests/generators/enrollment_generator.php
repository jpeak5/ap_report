<?php
global $CFG;
require_once ($CFG->dirroot.'/local/ap_report/classes/dbal.php');


class enrollment_dataset_generator{

    public $user_count;
    
    public static function create_users($users){
        
    }
    
    public function create_quiz(){
        $req_fields = array(
            'id',
            'name',
            'timeclose',
            'grade',
            'userid',
            'course',
        );
    }

    public function create_coursework_scenario(){
        /**
         * require the following table entries
         * quiz
         * 
         */
        
        //ues semesters
        $usem_cols      = array('id','year','name','campus','session_key','classes_start','grades_due');
        $usem_rows      = array();
        $sem_rows[]     = array(5,2013,'Spring','LSU',1358143200,1369458000);
        
        //ues_courses
        $ucourse_cols   = array('id', 'fullname', 'department', 'cou_number');
        $ucourse_rows   = array();
        $ucourse_rows[] = array(2656,'BIOL1335','BIOL', 1335);
        $ucourse_rows[] = array(3613,'AGRI4009','AGRI', 4009);
        
        //ues_courses
        $ustu_cols      = array('id', 'userid', 'sectionid', 'credit_hours', status);
        $ustu_rows      = array();
        
        //mdl_user
        $user_cols      = array('id','firstname','lastname','email', 'idnumber','username');
        $user_rows      = array();
        $user_rows[]    = array(999, 'teacher-0','exampleuser','teacher-0@example.com',666777555,'teacher-0');
        $user_rows[]    = array(5566,'teacher-1','exampleuser','teacher-1@example.com',666777545,'teacher-1');
        $user_rows[]    = array(555, 'coach-0',  'exampleuser',  'coach-0@example.com',123777555,'coach-0');
        
        //user id 465
        $user_rows[]    = array(465, 'student-0','exampleuser','student-0@example.com',472725024,'student-0');
        $ustu_rows[]    = array(1415, 465, 7227, 5, 'enrolled');
        $ustu_rows[]    = array(5442, 465,  743, 4, 'enrolled');
        
        //user id 8251
        $user_rows[]    = array(8251,'student-1','exampleuser','student-1@example.com',163360288,'student-1');
        $ustu_rows[]    = array( 452, 8251, 743, 5, 'enrolled');
        
        
        //final table array assignment
        $usem_data      = array('cols'=>$usem_cols,     'rows'=>$usem_rows);
        $ucourse_data   = array('cols'=>$ucourse_cols,  'rows'=>$ucourse_rows);     
        $user_data      = array('cols'=>$user_cols,     'rows'=>$user_rows);
        $ustu_data      = array('cols'=>$ustu_cols,     'rows'=>$ustu_rows);
    }
    
}

?>
