<?php

global $CFG;
require_once $CFG->dirroot.'/local/ap_report/lib.php';
require_once('fixtures/enrollment.php');
require_once('apreports_testcase.php');

class lmsGroupMembership_testcase extends apreports_testcase{
    
    public function test_constructor(){
        $gm = new lmsGroupMembership();
        $this->assertInstanceOf('enrollment_model',$gm->enrollment);
    }
    
    
    public function test_run(){
        global $CFG;
        $gm = new lmsGroupMembership();
        $this->assertInstanceOf('enrollment_model', $gm->enrollment);
        
//        mtrace(print_r($gm->enrollment));
        $this->assertTrue($gm->run());
//        $gm->run();

        $this->nonempty_array($gm->enrollment->groups);
        $this->assertEquals(2, count($gm->enrollment->groups[666]->group_members));
        
        $file = $CFG->dataroot.'/groups.xml';
        $this->assertFileExists($file);
    }
    
    public function test_lmsGroupMembershipRecord_camelize(){
        $arr = array(
                'sectionid'=>10,
                'groupid'=>666,
                'studentid'=>456654654,
                'extensions'=>'');
        $obj = lmsGroupMembershipRecord::instantiate($arr);
        $this->assertInstanceOf('lmsGroupMembershipRecord', $obj);
        $camel = lmsGroupMembershipRecord::camelize($obj);
        $this->assertTrue(object_property_exists($camel, 'sectionId'));
        $this->assertTrue(!object_property_exists($camel, 'sectionid'));
        $this->assertSame($arr['sectionid'], $camel->sectionId);
        $this->assertSame($arr['groupid'], $camel->groupId);
        $this->assertSame($arr['studentid'], $camel->studentId);
        $this->assertSame($arr['extensions'], $camel->extensions);
    }
    
    public function test_toXMLElement(){
        $arr   = array(
                'sectionid'=>10,
                'groupid'=>666,
                'studentid'=>456654654,
                'extensions'=>'');
        $camel = lmsGroupMembershipRecord::camelize(lmsGroupMembershipRecord::instantiate($arr));
        $frag  = lmsGroupMembershipRecord::toXMLElement($camel);
        $this->assertInstanceOf('DOMElement', $frag);
        $doc   = new DOMDocument();
        $root  = $doc->createElement('lmsGroupMembers');
        $root->setAttribute('university', 'test');
        $root->appendChild($doc->importNode($frag,true));
        
        //finish duocument
        $doc->appendChild($root);
        $doc->formatOutput = true;
        $this->assertTrue($doc->schemaValidate('tests/schema/lmsGroupMembership.xsd'));
        
    }
    
    public function test_toXMLDoc(){
        $arr   = array(
                    array(
                        'sectionid'=>10,
                        'groupid'=>666,
                        'studentid'=>456654654,
                        'extensions'=>''
                        ),
                    array(
                        'sectionid'=>11,
                        'groupid'=>6667,
                        'studentid'=>457645635,
                        'extensions'=>''
                    )
                );
        $class_obj = array();
        foreach($arr as $a){
            $class_obj[] = lmsGroupMembershipRecord::instantiate($a);
        }
        
        $xdoc = lmsGroupMembershipRecord::toXMLDoc($class_obj);
        $this->assertTrue($xdoc->schemaValidate('tests/schema/lmsGroupMembership.xsd'));
        
    }
}


?>
