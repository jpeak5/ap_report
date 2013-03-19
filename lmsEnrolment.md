##lmsEnrolment
1. calculate time spent (daily)
	1. get active ues semesters
	1. get sections for each semester
	1. get students per section
	1. calculate time spent per user per course and save it in `mdl_lsureports_lmsenrollment`
1. build xml (at any time after time-spent calculation has been performed)
	1. get userid, sectionid, lastaccesstime spent from enrolment table
	1. get semesters details: year, name
	1. get sections for each semester
	1. get students per course:
	1. get section/course details:
	

##get active ues semester ids

	`array int function get_active_ues_semester_ids()`
	
	mysql> select id from mdl_enrol_ues_semesters where classes_start < UNIX_TIMESTAMP(NOW()) and grades_due > UNIX_TIMESTAMP(NOW());                                              
	+----+
	| id |
	+----+
	|  5 |
	|  6 |
	| 15 |
	+----+
	3 rows in set (0.05 sec)

##Get active sections
	
	array int function get_active_section_ids(int $semesterid);

	mysql> select id from mdl_enrol_ues_sections where semesterid IN(5,6,15) limit 10;                                                                                                 
	+------+
	| id   |
	+------+
	| 5558 |
	| 5559 |
	| 5560 |
	| 5561 |
	| 5562 |

	...

	| 5564 |
	| 5565 |
	| 5566 |
	| 8805 |
	+------+
	?? rows in set (0.00 sec)


##get students per section:
Using the `sectionid` retrieved previously, get a list of userids enrolled in a given section.

	array int function get_studentids_per_section(int $sectionid);

	mysql> select ustu.userid moodle_uid  from mdl_enrol_ues_students ustu where sectionid = 8805 and status = 'enrolled';                                                             
	+------------+
	| moodle_uid |
	+------------+
	|      27574 |
	|      27591 |
	|      27604 |
	|      27622 |
	|      27629 |
	|      25718 |
	|      27637 |
	|          3 |
	|      27645 |
	|      27655 |
	|      27657 |
	|      27676 |
	|      27689 |
	|      27692 |
	|      27710 |
	|      27721 |
	|      27723 |
	|      27724 |
	+------------+
	18 rows in set (0.00 sec)

##get moodle course `id` numbers for use in `mdl_log` query:

	array int function get_moodle_course_id(int $sectionid);

	mysql> select  c.id moodle_courseid from mdl_enrol_ues_sections usect join mdl_course c on c.idnumber = usect.idnumber where usect.id = 8805;                                      
	+-----------------+
	| moodle_courseid |
	+-----------------+
	|            5594 |
	+-----------------+
	1 row in set (0.00 sec)


##get time spent per user per course:
With a student userid and moodle courseid in hand, calculate time spent using `mdl_log` data.
Trivial calculation algorithm shown here.

	array int function get_time_spent_today_section(int $courseid, int $userid);
(returns a keyed array of the form `array('timespent'=>x, 'lastaccess'=>y)`).

	mysql> select max(time) - min(time) duration from mdl_log where userid = 10282 and cmid = 88 order by time desc limit 10;                                                          
	+----------+
	| duration |
	+----------+
	|      315 |
	+----------+
	1 row in set (0.00 sec)

1. Save this data in a new lms table called `mdl_lsureports_lmsenrollment`
	1. table has the following fields: `id (priamry key),userid, ues_sectionid, timespent, lastAccess`

`bool function save_daily_enrollment_record(int $userid, int $sectionid, int $timespent, int $lastaccess);`


##build xml

##get details for active semesters:
The results of this query establish a basis on which we can establish the following mapping from lms db fields => the AP xml spec

	| LMS Field 		|	XML element	|
	-------------------------------------
	| year				|	enrollmentId[0-1]
	| classes_start		|	startDate
	| grades_due		|	endDate

The query for this is as follows:

	array int function get_active_ues_semester_details()

	mysql> select year, classes_start, grades_due from mdl_enrol_ues_semesters where classes_start < UNIX_TIMESTAMP(NOW()) and grades_due > UNIX_TIMESTAMP(NOW());                     
	+------+---------------+------------+
	| year | classes_start | grades_due |
	+------+---------------+------------+
	| 2013 |    1358143200 | 1369458000 |
	| 2013 |    1358143200 | 1364277600 |
	| 2013 |    1358143200 | 1370926800 |
	+------+---------------+------------+
	3 rows in set (0.00 sec)

These values will be valid for all `lmsEnrollment` elements.
Now fill in the rest of the required XML elements using the values in the `mdl_lsureports_lmsenrollment` table as keys into queries for course information.

###get section/course details:
for each in-bounds record in `mdl_lsureports_lmsenrollment`, get the required course and section details:

	array int function get_active_section_details(int $semesterid);

	mysql> select  ucourse.department, ucourse.cou_number, usect.sec_number,usect.id ues_sectionid, c.id moodle_courseid from mdl_enrol_ues_sections usect join mdl_enrol_ues_courses ucourse on ucourse.id = usect.courseid join mdl_course c on c.idnumber = usect.idnumber where usect.id in(8805);                                                                    
	+------------+------------+------------+---------------+-----------------+
	| department | cou_number | sec_number | ues_sectionid | moodle_courseid |
	+------------+------------+------------+---------------+-----------------+
	| LIS        | 7505       | 001        |          8805 |            5594 |
	+------------+------------+------------+---------------+-----------------+
	1 row in set (0.00 sec)

