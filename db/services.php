<?php
/**
 * following the template given in moodle docs: 
 * http://docs.moodle.org/dev/Adding_a_web_service_to_a_plugin
 */
$functions = array(
        'local_lsuonlinereports_get_enrollment' => array(
                'classname'   => 'local_lsuonlinereports_external',
                'methodname'  => 'get_enrollment',
                'classpath'   => 'local/lsuonlinereports/externallib.php',
                'description' => 'Return Enrollment',
                'type'        => 'read',
        )
);

// We define the services to install as pre-build services. A pre-build service is not editable by administrator.
$services = array(
        'LSU Online Reports' => array(
                'functions' => array ('local_lsuonlinereports_get_enrollment'),
                'restrictedusers' => 0,
                'enabled'=>1,
        )
);
?>
