<?php

require_once('config.php');

$key = optional_param('key',NULL,PARAM_RAW);

// for now, we use the unique username which is T"Z (ID Number)
// to link a Moodle 2 user with the same Moodle 19 user (Teacher)
// So, $usertoken is actually = username (from user table)
$usertoken = optional_param('usertoken',NULL,PARAM_RAW);

$password = optional_param('password',NULL,PARAM_RAW);
$action = optional_param('action','courselist',PARAM_RAW);
$backupfile = optional_param('backupfile',NULL,PARAM_RAW);

// we better have this key read from global settings (for better security)
// same as we do on the Moodle 2+ side.
if ($key != md5($CFG->moodle2secret)) die;

// Handle NEW backupfolder hack by nitzan
require_once('backup/lib.php');
$preferences = backup_get_config();
if (!empty($preferences->backup_sche_destination)){
    $backupfolder = $preferences->backup_sche_destination;
} else {
    $backupfolder = $CFG->dataroot;
}
// End hack

if ($action=='download') {
    session_write_close(); // unlock session during fileserving
    include_once('lib/filelib.php');
    //echo $CFG->dataroot.$backupfile;die;
    send_file($backupfolder.'/'.$backupfile,'moodle19_backupfile.zip');
}

$teacher = get_record('user','username',$usertoken);
//print_object($teacher);
if (empty($teacher)) die; // did not find user. oups :-(

// Make sure you copy Moodle 19 salt to Moodle 2
// before we compare these MD5 passwards
// DISABLED!!! due to lack of Moodle 19 salt in config.php
//if ($password != $teacher->password) die;

$sql_mycourses = "SELECT c.id as id, c.fullname as fullname
                    FROM mdl_role_assignments AS ra
                    JOIN mdl_context AS context ON ra.contextid = context.id AND context.contextlevel = 50
                    JOIN mdl_course AS c ON context.instanceid = c.id
                    WHERE ra.roleid = 3 and ra.userid = '".$teacher->id."'
                    ORDER BY c.id DESC
                    LIMIT 0,20";
//echo $sql_mycourses;


if ($mycourses = get_records_sql($sql_mycourses )) {
    //echo "<hr/>";
    $courseswithbackupfiles = array();
    foreach ($mycourses as $mycourse ) {
        $coursewithbackupfiles = new stdClass();
        $coursewithbackupfiles->id = $mycourse->id;
        $coursewithbackupfiles->fullname = $mycourse->fullname;
        $coursewithbackupfiles->backupfolder = "{$backupfolder}/{$mycourse->id}/backupdata/";
        $coursewithbackupfiles->backupfilelist = get_directory_list("{$backupfolder}/{$mycourse->id}/backupdata/");
        foreach($coursewithbackupfiles->backupfilelist as $backupfile) {
            $backupfilelist[] = array('name'=>$backupfile,
                'size'=>filesize("{$backupfolder}/{$mycourse->id}/backupdata/{$backupfile}"),
                'date'=>filectime("{$backupfolder}/{$mycourse->id}/backupdata/{$backupfile}"));

        }
        $coursewithbackupfiles->backupfilelist = $backupfilelist; // update file list with size and date for each file
        //echo $mycourse->fullname.'<br/>';
        //echo "{$CFG->dataroot}/{$mycourse->id}/backupdata <br/>";
        //echo "<hr/>";
        $courseswithbackupfiles[] = $coursewithbackupfiles;
    }
    //print_object($courseswithbackupfiles);die;
    echo json_encode($courseswithbackupfiles);
}
