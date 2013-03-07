<?php
global $CFG;
require_once $CFG->dirroot.'/local/lsuonlinereport/lib.php';



class lmsEnrollment_testcase extends basic_testcase{
    
    public $sp;
    public $rows;
    public $numRows;

    public static function strf($arg){
        return strftime('%F %T',$arg);
    }
    
    public function setUp(){
        $this->sp = new lmsEnrollment();
        $this->numRows = 10;
        $this->rows = $this->sp->getData($this->numRows);
        
    }
    
    public function test_getData(){
        $this->assertNotEmpty($this->rows, "No DB Rows");
        $this->assertEquals($this->numRows, count($this->rows));

    }
    
    /**
     * This test only exists to verify the refactoring of getData()
     */
    public function test_getDataOpt(){
        $optRows = $this->sp->getDataOpt($this->numRows);

        $this->assertNotEmpty($optRows, "No DB Rows");
        
        $this->assertEquals($this->numRows, count($optRows));
        
        //get a reference to the report
        $report = $this->sp->buildXML($optRows);
        
        //are we getting xml
        $this->assertInstanceOf('DOMDocument', $report);
        
        //right encoding?
        $this->assertEquals('UTF-8', $report->encoding);
        
        //valid?
        $this->assertTrue($report->schemaValidate('tests/lmsEnrollment.xsd'));
        
        //get a reference to the OLD SQL report
        $reportOld = $this->sp->buildXML($this->rows);
//        $this->assertEquals($reportOld, $report);
//        $this->assertEquals($reportOld->saveXML(), $report->saveXML());
    }

    public function test_buildXML(){
        
        //get a reference to the report
        $report = $this->sp->buildXML($this->rows);
        
        //are we getting xml
        $this->assertInstanceOf('DOMDocument', $report);
        
        //right encoding?
        $this->assertEquals('UTF-8', $report->encoding);
        
        //valid?
        $this->assertTrue($report->schemaValidate('tests/lmsEnrollment.xsd'));
    }
    
    public function test_getReport(){
        $report = $this->sp->getReport('XML', 10);
        
        $doc = new DOMDocument();
        $doc->loadXML($report);
        
        //valid?
        $this->assertTrue($doc->schemaValidate('tests/lmsEnrollment.xsd'));
        
    }
}


?>
