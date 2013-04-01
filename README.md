ap_report
=========

Academic Partnerships Moodle 2.X Export. Mostly LSU Specific.
#lmsEnrolment

##quick install
1. install the plugin as usual
1. set the hour in which you'd like cron to call the script to populate user activity stats (this is always for the previous day).
1. verify that things work by previewing activity stats for today. This uses the same calls as the daily cron but gives data for today, making testing easier.

###Summary
This application will calculate student time spent in class on a daily basis using the activity records stored in `mdl_log`. 
A single DB table, `mdl_apreport_enrol`, stores this information.

Realtime aggregate data is not available, rather, the application will only be concerned with events occurring up until the first minute of the current day, exclusive. 
Generally, we will only concern ourselves with the 24 hours ending at the beginning of the first minute of the current day.

At application run time, latest `timespent` and `last_access` information will be written to the file system under the Moodle Dataroot (`$CFG->dataroot`) 
as XML in a file named `lmsEnrollments.xml` by default. This name is user-configurable in the settings page `admin/settings.php?section=local_ap_report`.

An outline of the procedure is as follows. These actions run on a daily basis via cron unless an error condition is detected. In case of error, the cycle halts, 
and an error flag is set for the administrator on the plugin settings page.

1. Update db
	1. add yesterday total to cumulative total
	1. reset yesterday total to 0
	1. set success or failure
1. calculate time spent for yesterday
	1. check error, halt if error
	1. set `$CFG->apreport_job_start`
	1. using the _Comprehensive Enrollment Query_ (below) build a logical structure, 
            a tree or multidimensional array, call it the _Enrollments Tree_ data structure, in memory
	1. calculate time spent per user per course at the leaves of the _Enrollments Tree_ 
	1. traverse the tree and save `timespent`/`lastaccess` data in `{apreport_enrol}`
1. build xml (at any time after time-spent calculation has been performed)
	1. if not(`$CFG->apreport_job_start > $CFG->apreport_job_complete`), complain and stop
	1. otherwise, JOIN `{apreport_enrol}` with appropriate tables to hydrate sparse enrollment records into full AP-spec records for serialization to XML
	1. iterate through rows fetched in previous step, appending new `lmsEnrollment` nodes to a new `DOMDocument`
	1. save the `DOMDocument` to file
	1. declare victory by setting `$CFG->apreport_job_complete` to the current time and returning the `DOMDocument` for presentation

###Roadmap
* generate reports for an arbitrary time span


##Update DB
In this step, we will add yesterday's activity totals to the cumulative activity total for each user. Assuming this query does not return false, we proceed and set the accumulator column, `yesterday_ts` to 0.  
__NB__ that a 0 in this column means that there has been no activity for the user in the given section for yesterday.
Assuming this does not return false, do not set any error flags (or we just return true).


##Calculate Time Spent
In this step, we begin by setting `lmsEnrollment-start`. We build a data structure, _Enrollments Tree_, from enrollment data returned from the _Comprehensive Enrollment Query_. The leaves of this data structure are user activity records retrieved from a separate, similarly time-constrained, query on `mdl_log`. 

Traversing the tree in a left-right manner, we build an array of `lmsEnrollment_record` objects representing timespent per user per section for the timeframe (yesterday).

This process finishes by updating the `mdl_apreport_enrol` table with the data stored in the just-created `lmsEnrollment_reocrd`s array. If there are no errors, set `$CFG->lmsenrollment-finish`. 

##Build XML
For this step to occur, we require that no errors are present in the cycle, especially, that `$CFG->lmsEnrollment-start < $CFG->lmsEnrollment-finish`.
If all is well, we use the the _Get Enrollment Data_ query to get data to be serialized into the output XML.

---

#APPENDIX

##Comprehensive Enrollment Query

This query constructs a unique id through the concatenation of fields, required by Moodle for unique indexing in the returned rows array. Each field of each row will become a node in the _Enrollments Tree_ data structure, and student activity will be calculated at the leaves. 
There are two optimizations to the overall algorithm introduced in this query:

1. the `WHERE` condition: `AND usem.id in(current semester ids)` narrows the scope of the query. See _get active ues semesters_ (below) for the query and method signature
1. the `WHERE` clause `AND u.id IN(<active user ids>)` further restricts the resultset to only those studentids that have appeared in the logs during the time window in question.
If required, further optimizations would be possible by limiting the sections checked.  

	
		SELECT
		    CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number, '_', u.idnumber) AS enrollmentId,
		    u.id AS studentId, 
		    usem.id AS semesterid,
		    usem.year,
		    usem.name,
		    uc.department,
		    uc.cou_number,
		    us.sec_number AS sectionId,
		    c.id AS mdl_courseid,
		    us.id AS ues_sectionid,
		    'A' AS status,
		    CONCAT(usem.year, '_', usem.name, '_', uc.department, '_', uc.cou_number, '_', us.sec_number) AS uniqueCourseSection
		FROM mdl_course AS c
		    INNER JOIN mdl_context                  AS ctx  ON c.id = ctx.instanceid
		    INNER JOIN mdl_role_assignments         AS ra   ON ra.contextid = ctx.id
		    INNER JOIN mdl_user                     AS u    ON u.id = ra.userid
		    INNER JOIN mdl_enrol_ues_sections       AS us   ON c.idnumber = us.idnumber
		    INNER JOIN mdl_enrol_ues_students       AS ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
		    INNER JOIN mdl_enrol_ues_semesters      AS usem ON usem.id = us.semesterid
		    INNER JOIN mdl_enrol_ues_courses        AS uc   ON uc.id = us.courseid
		    
		WHERE 
		    ra.roleid IN (5)
		    AND usem.id in(5,6,7,15)
		    AND ustu.status = 'enrolled'
		    AND u.id IN(3)
		ORDER BY uniqueCourseSection


##get active ues semesters

	`array stdClass function get_active_ues_semesters()`
	
	mysql> select * from mdl_enrol_ues_semesters where classes_start < UNIX_TIMESTAMP(NOW()) and grades_due > UNIX_TIMESTAMP(NOW());                                              
	+----+------+--------+--------+-------------+---------------+------------+
	| id | year | name   | campus | session_key | classes_start | grades_due |
	+----+------+--------+--------+-------------+---------------+------------+
	|  5 | 2013 | Spring | LSU    |             |    1358143200 | 1369458000 |
	|  6 | 2013 | Spring | LSU    | B           |    1358143200 | 1364277600 |
	|  7 | 2013 | Spring | LSU    | C           |    1362978000 | 1369371600 |
	| 15 | 2013 | Spring | LAW    |             |    1358143200 | 1370926800 |
	+----+------+--------+--------+-------------+---------------+------------+
	4 rows in set (0.00 sec)








##build xml

###Get Enrollment Data for output
Returns records ready for formatting to final XML output.

	SELECT len.id AS enrollmentid
    , len.userid
    , u.idnumber AS studentid
    , len.sectionid AS ues_sectionid
    , c.id AS courseid
    , len.semesterid AS semesterid
    , usem.year
    , usem.name
    , usem.session_key
    , usem.classes_start AS startdate
    , usem.grades_due AS enddate
    , (len.timespent + len.yesterday_timespent) AS timespent
    , len.lastaccess 
    , ucourse.department AS department
    , ucourse.cou_number AS coursenumber
    , usect.sec_number AS sectionid
    , 'A' as status
    , NULL AS extensions
    FROM mdl_apreport_enrol len
        LEFT JOIN mdl_user u
            on len.userid = u.id
        LEFT JOIN mdl_enrol_ues_sections usect
            on len.sectionid = usect.id
        LEFT JOIN mdl_course c
            on usect.idnumber = c.idnumber
        LEFT JOIN mdl_enrol_ues_courses ucourse
            on usect.courseid = ucourse.id
        LEFT JOIN mdl_enrol_ues_semesters usem
            on usect.semesterid = usem.id
    WHERE 
        len.timestamp > %s and len.timestamp <= %s
    GROUP BY len.sectionid


##get details for active semesters:
The results of this query establish a basis on which we can establish the following mapping from lms db fields => the AP xml spec

	| LMS Field 								|	XML element	|
	-------------------------------------------------------------------
	| usem.year									|	enrollmentId[0-1]
	| len.userid 								|	enrollmentId[2-10]
	| coursenumber								|	enrollmentId[11-16]
	| sectionid									|	enrollmentId[17-19]
	| len.userid	 							| 	studentId
	| ucourse.department + ucourse.coursenumber | 	courseId
	| sectionId 								| 	sectionId
	| startDate 								|	startDate
	| enddate									|	endDate
	| status									| 	status
	| len.lastaccess 							|	lastCourseAccess 	
	| timespent 								|	timeSpentInClass




