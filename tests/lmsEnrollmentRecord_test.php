<?php
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereport/lib.php';



class lmsEnrollmentRecord_testcase extends basic_testcase{
    
    public $sp;
    
    
    public function setUp(){

    }
    
    public function test_constructor(){

        
        $i=0;
        $string = function($len) {
          return substr(md5((string) rand(0, 1111111)), 0, $len);
        };
        $num    = function ($s,$e){
            return rand($s,$e);
        };
        
        
        
        while($i <5){
            
            //build some fake db results
            $start      = (time()- ($num(1,365)*$num(3600, 86400)));
            $courseId   = $string(10);
            $section    = $num(0,999);
            $rec        = array(
                'enrollmentid' => $courseId,
                'studentid' => $num(69000000, 69999999),
                'courseid' => 'C_ID '.$num(1000, 9999),
                'sectionid' => $section,
                'semesterid' => 5,
                'status' => 'A',
//                'uniquecoursesection' => $courseId.'_'.$section
            );
            
            $obj = new lmsEnrollmentRecord($rec);
            
            foreach(array_keys($rec) as $k){
                $this->assertObjectHasAttribute($k, $obj, sprintf("member %s does not exist", $k));
            }
            
            foreach(array_keys($rec) as $k){
                $this->assertEquals($rec[$k], $obj->$k, sprintf("values '%s' and '%s' not equal for field '%s'", $rec[$k], $obj->$k,$k));
            }
            $i++;
        }
        
        
        
    }

    

}
?>
