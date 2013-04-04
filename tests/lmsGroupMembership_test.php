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
        $gm = new lmsGroupMembership();
        $this->assertTrue($gm->run());
        
        $this->nonempty_array($gm->enrollment->groups);
        $this->assertEquals(2, count($gm->enrollment->groups[666]->group_members));
//        foreach($gm->enrollment->groups as $g){
//            $this->assertTrue(array_key_exists($g->mdl_group->id, array(666,7)),'check dataset entries for groups table');
//        }
        
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
    
    public function test_toXML(){
        $arr   = array(
                'sectionid'=>10,
                'groupid'=>666,
                'studentid'=>456654654,
                'extensions'=>'');
        $camel = lmsGroupMembershipRecord::camelize(lmsGroupMembershipRecord::instantiate($arr));
        $frag   = lmsGroupMembershipRecord::toXML($camel);
        print_r($camel);
        $this->assertInstanceOf('DOMDocument', $frag);
        
        $doc = new DOMDocument();
        
        $node = $frag->getElementsByTagName('lmsGroupMember');
        $this->assertEquals(1,$node->length);
        $new = $doc->importNode($node->item(0),true);
        
        $root = $doc->createElement('lmsGroupMembers');
        $root->appendChild($new);
        $doc->appendChild($root);
        $doc->formatOutput = true;
        print_r($doc->saveXML());
    }
    
}


?>
