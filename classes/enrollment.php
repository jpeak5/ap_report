<?php

/**
 * This class contains most of the complex queries used to 
 * retrieve the datasets used in AP reports
 */
class enrollment_model {
    /**
     *
     * @var semester[]
     */
    public $semesters;
    /**
     *
     * @var course[] 
     */
    public $courses;
    /**
     *
     * @var array(student) 
     */
    public $students;
    
    /**
     *
     * @var group[]
     */
    public $groups;
    
    /**
     *
     * @var lmsGroupMembershipRecord[]
     */
    public $group_membership_records;
    
    /**
     *
     * @var lmsSectionGroupRecord[]
     */
    public $groups_primary_instructors;
    
    /**
     *
     * @var lmsSectionGroupRecord[]
     */
    public $groups_coaches;
    
    /**
     *
     * @var lmsSectionGroupRecord[] 
     */
    public $sectionGroupRecords;
    /**
     *
     * @var stdClass holds DB records of users 
     * users are considered active if they occur 
     * in mdl_log as having done anything in a course.
     */
    public $active_users;
    
    public $all_enrollment_records;
    public $all_users;
    /**
     * holds mdl_course.id ofo each current course
     * used in lmsCoursework report
     * @var int[] 
     */
    public $current_courseids;

    public function __construct(){
        $this->semesters = self::get_active_ues_semesters();
        assert(!empty($this->semesters));
        $this->courses   = self::get_all_courses(array_keys($this->semesters));
    }
    

    
    public static function get_all_courses($semesterids, $ids_only=false){
        global $DB;
        
        $sql = sprintf("SELECT 
                    usect.id            AS ues_sectionid,
                    usect.sec_number    AS ues_sections_sec_number,  
                    usect.idnumber      AS idnumber,
                    c.id                AS mdl_courseid,
                    c.shortname         AS mdl_course_shortname,
                    usem.id             AS ues_semesterid,
                    ucourse.department  AS ues_course_department,
                    ucourse.cou_number  AS ues_cou_number
                FROM {enrol_ues_sections} usect
                INNER JOIN {enrol_ues_courses} ucourse
                    on usect.courseid = ucourse.id
                INNER JOIN {enrol_ues_semesters} usem
                    on usect.semesterid = usem.id
                INNER JOIN {course} c
                    on usect.idnumber = c. idnumber
                WHERE 
                    usem.id IN(%s)
                AND 
                    usect.idnumber <> ''",
                implode(',',$semesterids));
        //verify courses
        $courses = array();
        
        $rows = $DB->get_records_sql($sql);
        if($ids_only){
            $ids = array();
            foreach($rows as $row){
                $ids[] = $row->mdl_courseid;
            }
            return !empty($ids) ? $ids : false;
        }
        
        foreach($rows as $row){
            $course = new course();
            
            //build the course obj
            $mc                 = new mdl_course();
            $mc->id             = $row->mdl_courseid;
            $mc->idnumber       = $row->idnumber;
            $course->mdl_course = $mc;
            
            //build the ues section object
            $usect              = new ues_sections_tbl();
            $usect->id          = $row->ues_sectionid;
            $usect->idnumber    = $row->idnumber;
            $usect->sec_number  = $row->ues_sections_sec_number;
            $usect->semesterid  = $row->ues_semesterid;
            $course->ues_section= $usect;

            $courses[$mc->id]   = $course;
        }
        
        return $courses;
        
    }
    

    
    public function get_group_members(){
                $sql = "SELECT DISTINCT
                            gm.id AS uuid,
                            gm.groupid,
                            gm.userid,
                            g.courseid,
                            u.idnumber AS studentid,
                            c.idnumber,
                            usect.sec_number AS ues_sectionnum
                        FROM 
                            (
                                mdl_groups_members gm 
                                LEFT JOIN mdl_groups g ON g.id = gm.groupid
                                LEFT JOIN mdl_user u ON u.id = gm.userid
                                LEFT JOIN mdl_course c ON c.id = g.courseid
                            )
                            INNER JOIN mdl_enrol_ues_sections usect ON c.idnumber = usect.idnumber
                            LEFT JOIN mdl_enrol_ues_courses ";
        
        global $DB;
        $members = $DB->get_recrods_sql($sql);
        
        foreach($members as $member){
            if(!array_key_exists($member->groupid, $this->groups)){
                $this->groups[$member->groupid] = new group();
            }
            if(!array_key_exists($this->groups[$member->groupid]->group_members[$member->uuid])){
                $this->groups[$member->groupid]->group_members[$member->uuid] = new group_member();
                $mdl_user = mdl_user::instantiate(array('id'=>$member->userid,'idnumber'=>$member->userid));
                $mdl_group_member = mdl_group_member::instantiate(array(
                    'id'=>$member->uuid,
                    'groupid'=>$member->groupid,
                    'userid'=>$member->userid
                    ));
                $group_member = group_member::instantiate(array('mdl_user'=>$mdl_user,
                    'mdl_group_member'=>$mdl_group_member));
                $this->groups[$member->groupid]->group_members[$member->uuid]=$group_member;
            }
            
        }
        
    }
    
    
    public function queryGroupmembership(){
        global $DB;
        $sql = "SELECT
                    CONCAT(gm.id,c.idnumber) AS userGroupId,
                    c.id AS courseid,                    
                    us.sec_number AS ues_sectionId,
                    g.id AS groupId,
                    gm.id AS gmid,
                    u.idnumber AS studentId,
                    u.id AS userid,
                    gm.groupid AS groupid,
                    CONCAT(us.id,'-',uc.department,'_',uc.cou_number,'_',us.sec_number) AS sectionid_uniq,
                    NULL AS extensions
                FROM {course} AS c
                    INNER JOIN {enrol_ues_sections} AS us ON c.idnumber = us.idnumber
                        AND us.idnumber IS NOT NULL
                        AND c.idnumber IS NOT NULL
                        AND us.idnumber <> ''
                        AND c.idnumber <> ''
                    INNER JOIN {enrol_ues_courses} AS uc ON uc.id = us.courseid
                    INNER JOIN {enrol_ues_semesters} AS usem ON usem.id = us.semesterid
                    INNER JOIN {groups} AS g ON c.id = g.courseid
                    INNER JOIN {context} AS ctx ON c.id = ctx.instanceid AND ctx.contextlevel = 50
                    INNER JOIN {role_assignments} AS ra ON ra.contextid = ctx.id
                    INNER JOIN {user} AS u ON u.id = ra.userid AND ra.roleid IN (5)
                    INNER JOIN {groups_members} AS gm ON g.id = gm.groupid AND u.id = gm.userid
                WHERE usem.classes_start < UNIX_TIMESTAMP(NOW())
                        AND usem.grades_due > UNIX_TIMESTAMP(NOW())
                GROUP BY userGroupId";

        return $DB->get_records_sql($sql);
    }
    
    public function get_group_membership_report(){
        if(!isset($this->groups)){
            $this->groups = array();
        }
        foreach($this->queryGroupmembership() as $row){
            $rec = new lmsGroupMembershipRecord();
            $rec->groupid = $row->groupid;
            $rec->sectionid = $row->ues_sectionid;
            $rec->studentid = $row->studentid;
            if(!isset($this->group_membership_records[$rec->groupid])){
                $this->group_membership_records[$rec->groupid] = array();
            }
            $this->group_membership_records[$rec->groupid][$row->usergroupid] = $rec;
        }

        return $this->group_membership_records;
    
    }
    
    public function get_groups_with_students(){

        $sql = "SELECT
                    
                    CONCAT(gm.id,c.idnumber) AS userGroupId,
                    c.id AS courseid,                    
                    us.sec_number AS sectionId,
                    g.id AS groupId,
                    gm.id AS gmid,
                    u.idnumber AS studentId,
                    u.id AS userid,
                    gm.groupid AS groupid,
                    NULL AS extensions
                FROM {course} AS c
                    INNER JOIN {enrol_ues_sections} AS us ON c.idnumber = us.idnumber
                        AND us.idnumber IS NOT NULL
                        AND c.idnumber IS NOT NULL
                        AND us.idnumber <> ''
                        AND c.idnumber <> ''
                    INNER JOIN {enrol_ues_courses} AS uc ON uc.id = us.courseid
                    INNER JOIN {enrol_ues_semesters} AS usem ON usem.id = us.semesterid
                    INNER JOIN {groups} AS g ON c.id = g.courseid
                    INNER JOIN {context} AS ctx ON c.id = ctx.instanceid AND ctx.contextlevel = 50
                    INNER JOIN {role_assignments} AS ra ON ra.contextid = ctx.id
                    INNER JOIN {user} AS u ON u.id = ra.userid AND ra.roleid IN (5)
                    INNER JOIN {groups_members} AS gm ON g.id = gm.groupid AND u.id = gm.userid
                WHERE usem.classes_start < UNIX_TIMESTAMP(NOW())
                        AND usem.grades_due > UNIX_TIMESTAMP(NOW())
                GROUP BY userGroupId";
        
        global $DB;
        $rows = $DB->get_records_sql($sql);
        assert(count($rows) > 0);
        if(!isset($this->groups)){
            $this->groups = array();
        }
        foreach($rows as $row){

                //make a group member
                $mgm_gm = mdl_group_member::instantiate(array(
                    'id'        =>$row->gmid,
                    'userid'    =>$row->userid,
                    'groupid'   =>$row->groupid
                ));
                
                $mgm_mu = mdl_user::instantiate(array(
                    'id'        =>$row->userid,
                    'idnumber'  =>$row->studentid
                ));
                
                $gm = group_member::instantiate(array(
                    'mdl_user'  => $mgm_mu,
                    'mdl_group_member' => $mgm_gm
                ));

            if(!array_key_exists($row->groupid,$this->groups)){
                //make the group object and constituents
                $mg = mdl_group::instantiate(array(
                    'id'        =>$row->groupid,
                    'courseid'  =>$row->courseid,
                    ));
                
                $g = group::instantiate(array(
                    'mdl_group'     => $mg, 
                    'group_members' => array($gm->mdl_group_member->id=>$gm)
                    ));
                $this->groups[$row->groupid] = $g;
            }
            
            $this->groups[$row->groupid]->group_members[$gm->mdl_group_member->id] = $gm;
      

        }
        
        return $this->groups;
    }
    
    public function get_active_students($start,$end){
        assert(!empty($this->semesters));
        $active_users = $this->get_active_users($start,$end);
        $datarows = $this->get_semester_data(array_keys($this->semesters),
                array_keys($active_users));
        
        if(empty($this->students)){
            $this->students = array();
        }
        
        foreach($datarows as $row){
            
            $ues_course              = new ues_courses_tbl();
            $ues_course->cou_number  = $row->cou_number;
            $ues_course->department  = $row->department;

            $ues_section             = new ues_sections_tbl();
            $ues_section->sec_number = $row->sectionid;
            $ues_section->id         = $row->ues_sectionid;
            $ues_section->semesterid = $row->semesterid;

            $mdl_course              = new mdl_course();
            $mdl_course->id          = $row->mdl_courseid;

            $course                  = new course();
            $course->mdl_course      = $mdl_course;
            $course->ues_course      = $ues_course;
            $course->ues_section     = $ues_section;
            
            if(!array_key_exists($row->studentid, $this->students)){
                $s = new mdl_user();
                $s->id = $row->studentid;
                
                $student = new student();
                $student->mdl_user = $s;
                
                $this->students[$student->mdl_user->id] = $student;
                
                $this->students[$student->mdl_user->id]->courses[$course->mdl_course->id] = $course;
            }
            
            $this->students[$row->studentid]->courses[$course->mdl_course->id] = $course;
            
        }

        return $this->students;
    }

    /**
     * for the input semesters, this method builds an 
     * internal tree-like data structure composed of 
     * nodes of type semester, section, user.
     * Note that this tree contains only enrollment, no timespent data.
     * 
     *  
     * @see get_active_ues_semesters
     */
    public function build_enrollment_tree(){
        
        
        assert(!empty($this));

        //define the root of the tree
        $tree = $this->enrollment;

        //put enrollment records into semester arrays
        $enrollments = $this->get_semester_data(array_keys($this->enrollment->semesters));
        //@TODO what hapens if this is empty???
        if(empty($enrollments)){
            return false;
        }

        
        //walk through each row returned from the db @see get_semester_data
        foreach($enrollments as $row => $e){
            
            //in populating the first level, above, we should have already 
            //allocated an array slot for every possible value here
            assert(array_key_exists($e->semesterid, $tree->semesters));

                $semester = $tree->semesters[$e->semesterid];
                if(empty($semester->courses)){
                    $semester->courses = array();
                }
                
                if(!array_key_exists($e->sectionid, $semester->courses)){
                    //add a new section to the semester node's sections array
                    
                    $ues_course = new stdClass();
                    $ues_course->cou_number     = $e->cou_number;
                    $ues_course->department  = $e->department;
                    
                    $ues_section = new stdClass();
                    $ues_section->sec_number = $e->sectionid;
                    $ues_section->id         = $e->ues_sectionid;
                    $ues_section->semesterid = $e->semesterid;

                    $mdl_course = new stdClass();
                    $mdl_course->id    = $e->mdl_courseid;

                    $ucourse = ues_courses_tbl::instantiate($ues_course);
                    
                    $usect = ues_sections_tbl::instantiate($ues_section);
                    $mdlc = mdl_course::instantiate($mdl_course);
                    
                    $course_params = array('ues_course' => $ucourse, 
                        'ues_section' => $usect,
                        'mdl_course' => $mdlc);
                    
                    $semester->courses[$e->ues_sectionid] = course::instantiate($course_params);
                    
                    
                    //@TODO refactor to use student, not old user class
                    //add this row's student as the next element 
                    //of the current section's users array
                    $user     = new user();
                    $user->id = $e->studentid;
                    $user->apid = $e->apid;
//                    $user->last_update = $e->last_update;
                    $section->users[$e->studentid] = $user;
                }else{
                    //the section already exists, so just add the user 
                    //to the semester->section->users array
                    $user = new user();
                    $user->id = $e->studentid;
                    $user->apid = $e->apid;
//                    $user->last_update = $e->last_update;
                    $semester->sections[$e->sectionid]->users[$e->studentid] = $user;
                }
        }
//        print_r($tree);
        return $tree;
    }
    
    /**
     * utility function that takes in an array of [active] semesterids and queries
     * the db for enrollment records on a per-[ues]section basis;
     * The result set of this query is limited by the return value of 
     * @see get_active_users
     * @global type  $DB
     * @param  array $semesterids  integer semester ids, presumably for active semesters
     * @return array stdClass | false
     */
    public function get_semester_data($semesterids, $userids){
        global $DB;

        //use the idnumbers of the active users to reduce the number of rows we're working with;
        if(!$userids){
            add_to_log(1, 'ap_reports', 'no active users');
            return false;
        }
        
        
        $sql = vsprintf(
            "SELECT
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
            
            FROM {course} AS c
                INNER JOIN {context}                  AS ctx  ON c.id = ctx.instanceid
                INNER JOIN {role_assignments}         AS ra   ON ra.contextid = ctx.id
                INNER JOIN {user}                     AS u    ON u.id = ra.userid
                INNER JOIN {enrol_ues_sections}       AS us   ON c.idnumber = us.idnumber
                INNER JOIN {enrol_ues_students}       AS ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
                INNER JOIN {enrol_ues_semesters}      AS usem ON usem.id = us.semesterid
                INNER JOIN {enrol_ues_courses}        AS uc   ON uc.id = us.courseid
                
            WHERE 
                ra.roleid IN (5)
                AND usem.id in(%s)
                AND ustu.status = 'enrolled'
                AND u.id IN(%s)
            ORDER BY uniqueCourseSection"
                , array(implode(',',$semesterids)
                        , implode(',', $userids))
                );
        
        $rows = $DB->get_records_sql($sql);
        
        return count($rows) > 0 ? $rows : false;
    }
    
    /**
     * Get a list of all semesters 
     * where {classes_start < time() < grades_due}
     * 
     * @return array stdClass of active semesters
     */
    public static function get_active_ues_semesters($time=null, $ids_only=false){
        global $DB;
        $time = isset($time) ? $time : time();
        $sql = vsprintf("SELECT 
                                * 
                            FROM 
                                {enrol_ues_semesters}
                            WHERE 
                                classes_start <= %d 
                            AND 
                                grades_due >= %d"
                        , array($time,$time));
        $semesters = $DB->get_records_sql($sql);
        
        //shortcut breakout
        if($ids_only){
            $ids = array_keys($semesters);

            return !empty($ids) ? $ids : false;
        }

        assert(count($semesters) > 0);
        $s = array();
        foreach($semesters as $semester){
            $obj = semester::instantiate(array('ues_semester'=>$semester));
            $s[$obj->ues_semester->id] = $obj;
        }

        return $s;
    }
    
    public function get_all_users(){
        $sql = "SELECT
            CONCAT(usem.year,u.idnumber,LPAD(c.id,8,'0'),us.sec_number) AS enrollmentId,
            u.idnumber AS studentId,
            CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
            us.sec_number AS sectionId,
            usem.classes_start AS startDate,
            usem.grades_due AS endDate,
            'A' AS status
        FROM mdl_course AS c
            INNER JOIN mdl_context AS ctx ON c.id = ctx.instanceid
            INNER JOIN mdl_role_assignments AS ra ON ra.contextid = ctx.id
            INNER JOIN mdl_user AS u ON u.id = ra.userid
            INNER JOIN mdl_enrol_ues_sections us ON c.idnumber = us.idnumber
            INNER JOIN mdl_enrol_ues_students ustu ON u.id = ustu.userid AND us.id = ustu.sectionid
            INNER JOIN mdl_enrol_ues_semesters usem ON usem.id = us.semesterid
            INNER JOIN mdl_enrol_ues_courses uc ON uc.id = us.courseid
        WHERE ra.roleid IN (5)
            AND usem.classes_start < UNIX_TIMESTAMP(NOW())
            AND usem.grades_due > UNIX_TIMESTAMP(NOW())
            AND ustu.status = 'enrolled'";
    }
    
    
    /**
     * this function is a utility method that helps optimize the overall 
     * routine by limiting the number of people we check;
     * 
     * We do this by first getting a collection of potential users from current enrollment;
     * Then, limit that collection to include only those users who have registered activity in the logs
     * 
     */
    public function get_active_users($start, $end){
       global $DB;
       
       //get one userid for anyone in the mdl_log table that has done anything
       //in the temporal bounds
       //get, also, the timestamp of the last time they were included in this 
       //scan (so we keep a contiguous record of their activity)
       $sql =  vsprintf("SELECT DISTINCT u.id
                FROM 
                    {log} log 
                        join 
                    {user} u 
                        on log.userid = u.id 
                WHERE 
                    log.time > %s
                AND 
                    log.time < %s;",array($start,$end));
       $this->active_users = $DB->get_records_sql($sql);
       
       return count($this->active_users) > 0 ? $this->active_users : false;
    }
    
    /**
     * Note that this method follows a 'first come, first served strategy. The first occurence of a user 
     * for a group will become THE user for the group; all others will be ignored
     * @global type $DB
     * @return lmsSectionGroupRecord[]
     */
    public function get_groups_primary_instructors() {
        global $CFG;
        $sql = sprintf("SELECT
                    CONCAT(g.id,u.idnumber) AS userGroupId,
                    us.sec_number AS sectionId,
                    g.id AS groupId,
                    g.name AS groupName,
                    CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                    u.idnumber AS primaryInstructorId,
                    u.firstname AS primaryInstructorFname,
                    u.lastname AS primaryInstructorLname,
                    u.email AS primaryInstructorEmail,
                    NULL AS coachId,
                    NULL AS coachFirstName,
                    NULL AS coachLastName,
                    NULL AS coachEmail,
                    NULL AS extensions
                FROM {course} AS c
                    INNER JOIN {enrol_ues_sections} AS us ON c.idnumber = us.idnumber
                        AND us.idnumber IS NOT NULL
                        AND c.idnumber IS NOT NULL
                        AND us.idnumber <> ''
                        AND c.idnumber <> ''
                    INNER JOIN {enrol_ues_courses} AS uc ON uc.id = us.courseid
                    INNER JOIN {enrol_ues_semesters} AS usem ON usem.id = us.semesterid
                    INNER JOIN {groups} AS g ON c.id = g.courseid
                    INNER JOIN {context} AS ctx ON c.id = ctx.instanceid AND ctx.contextlevel = 50
                    INNER JOIN {role_assignments} AS ra ON ra.contextid = ctx.id
                    INNER JOIN {user} AS u ON u.id = ra.userid AND ra.roleid IN (%s)
                    INNER JOIN {groups_members} AS gm ON g.id = gm.groupid AND u.id = gm.userid
                WHERE usem.classes_start < UNIX_TIMESTAMP(NOW())
                    AND usem.grades_due > UNIX_TIMESTAMP(NOW())
                GROUP BY userGroupId", $CFG->apreport_primy_inst_roles);

        global $DB;
  
        foreach($DB->get_records_sql($sql) as $rec){
            $inst = lmsSectionGroupRecord::instantiate((array)$rec);
            if(empty($this->groups_primary_instructors[$inst->groupid])){
                $this->groups_primary_instructors[$inst->groupid] = $inst;
            }else{
                continue;
            }
        }
        
        return empty($this->groups_primary_instructors) ? false : $this->groups_primary_instructors;
        
    }
    
    /**
     * Note that this method follows a 'first come, first served strategy. The first occurence of a user 
     * for a group will become THE user for the group; all others will be ignored
     * @global type $DB
     * @return lmsSectionGroupRecord[]
     */
    public function get_groups_coaches(){
        global $CFG;
        $sql = sprintf("SELECT
                        CONCAT(g.id,u.idnumber) AS userGroupId,
                        c.id AS courseid,
                        g.name AS sectionId,
                        g.id AS groupId,
                        g.name AS groupName,
                        CONCAT(RPAD(uc.department,4,' '),'  ',uc.cou_number) AS courseId,
                        NULL AS primaryInstructorId,
                        NULL AS primaryInstructorFname,
                        NULL AS primaryInstructorLname,
                        NULL AS primaryInstructorEmail,
                        u.idnumber AS coachId,
                        u.firstname AS coachFirstName,
                        u.lastname AS coachLastName,
                        u.email AS coachEmail,
                        NULL AS extensions
                    FROM {course} AS c
                        INNER JOIN {enrol_ues_sections} AS us ON c.idnumber = us.idnumber
                            AND us.idnumber IS NOT NULL
                            AND c.idnumber IS NOT NULL
                            AND us.idnumber <> ''
                            AND c.idnumber <> ''
                        INNER JOIN {enrol_ues_courses} AS uc ON uc.id = us.courseid
                        INNER JOIN {enrol_ues_semesters} AS usem ON usem.id = us.semesterid
                        INNER JOIN {groups} AS g ON c.id = g.courseid
                        INNER JOIN {context} AS ctx ON c.id = ctx.instanceid AND ctx.contextlevel = 50
                        INNER JOIN {role_assignments} AS ra ON ra.contextid = ctx.id
                        INNER JOIN {user} AS u ON u.id = ra.userid AND ra.roleid IN (%s)
                        INNER JOIN {groups_members} AS gm ON g.id = gm.groupid AND u.id = gm.userid
                    WHERE usem.classes_start < UNIX_TIMESTAMP(NOW())
                        AND usem.grades_due > UNIX_TIMESTAMP(NOW())
                    GROUP BY userGroupId",$CFG->apreport_coach_roles);
        
        global $DB;
  
        foreach($DB->get_records_sql($sql) as $rec){
            $coach = lmsSectionGroupRecord::instantiate((array)$rec);
            if(empty($this->groups_coaches[$coach->groupid])){
                $this->groups_coaches[$coach->groupid] = $coach;
            }else{
                continue;
            }
        }
        
        return empty($this->groups_coaches) ? false : $this->groups_coaches;
        
    }

 


}


?>