<?php
defined('MOODLE_INTERNAL') || die;
global $CFG;
require_once("$CFG->libdir/externallib.php");


/**
 * @link http://docs.moodle.org/dev/Web_services_%282.0_onwards%29 see the moodle docs
 */
class local_lsuonlinereports_external extends external_api{
    //Provides a description of the parameters
    public static function get_enrollment_parameters () {
         return new external_function_parameters();
    } 

    //Does the work of collecting the requested data, creating adding deleting records etc.
    public static function get_enrollment($limit) {
        global $CFG;

        $params = self::validate_parameters(self::get_enrollment_parameters(),
            $limit
        );

        //retrieve courses
        //validate that expected params exist 
        //check the structure of \$params...
        //Will probably need to chuck a narny.


    } 

    //Provides a description of the structure for the data returned by get_courses (the worker function).
    public static function get_enrollment_returns () {
        return new external_function_parameters(
            array(
                'lmsEnrollments' => new external_multiple_structure(
                    new external_single_structure(
                        array(
                            'enrollmentid'  => new external_value(PARAM_RAW, 'unique enrollment id composed of multiple record fields'),
                            'studentid'     => new external_value(PARAM_RAW, 'student ID'),
                            'courseid'      => new external_value(PARAM_RAW, 'course ID'),
                            'startdate'     => new external_value(PARAM_RAW, 'start date'),
                            'enddate'       => new external_value(PARAM_RAW, 'end date'),
                            'status'        => new external_value(PARAM_RAW, 'status'),
                            'uniquecoursesection'     => new external_value(PARAM_RAW, 'unique value'),
                        ),
                            'lmsEnrollment'
                    )
                )
            )
        );
    }    
}
?>
