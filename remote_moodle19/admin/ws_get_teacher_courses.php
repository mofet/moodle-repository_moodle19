<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * This plugin enable users (Teachers) to import remote Moodle 1.9.x backup files into current Moodle 2+
 *
 * @since 2.4
 * @package    repository_moodle19
 * @copyright  2013 Nadav Kavalerchik {@link http://github.com/nadavkav}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../config.php');

// Make sure request is coming only from authorized Moodle 2 server
//if ($_SERVER['SERVER_NAME'] != $CFG->moodle2servername) {
//    handle_error('This request is not authorized. Please check moodle2servername and secret key in settings', 403);
//};

$request_encoded = optional_param('request',NULL,PARAM_RAW); // encrypted
$secret = optional_param('secret',NULL,PARAM_RAW); // encrypted
$action = optional_param('action',NULL,PARAM_RAW);
$backupfile = optional_param('backupfile',NULL,PARAM_RAW);

// Handle NEW backupfolder hack by nitzan
require_once('../backup/lib.php');
$preferences = backup_get_config();
if (!empty($preferences->backup_sche_destination)){
    $backupfolder = $preferences->backup_sche_destination;
} else {
    $backupfolder = $CFG->dataroot;
}
// End hack


// If we got a "download" request then send back the backup file
if ($secret == md5($CFG->moodle2secret) and $action=='download' and !empty($backupfile)) {
    session_write_close(); // unlock session during fileserving
    include_once('../lib/filelib.php');
    //echo $CFG->dataroot.$backupfile;die;
    send_file($backupfolder.'/'.$backupfile,'moodle19_backupfile.zip');
    die;
}

// else...
// We are probably asked for a course list
$request_decoded = base64_decode($request_encoded);

$iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC); // =32
# retrieves the IV, iv_size should be created using mcrypt_get_iv_size()
$iv = substr($request_decoded, 0, $iv_size);

# retrieves the cipher text (everything except the $iv_size in the front)
$request_data = substr($request_decoded, $iv_size);

$base64_data = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($CFG->moodle2secret), $request_data, MCRYPT_MODE_CBC,$iv);
$request_dec = base64_decode($base64_data);
$request = json_decode($request_dec);
//print_r($request);

// Authentication...
//

// for now, we use the unique username which is T"Z (ID Number)
// to link a Moodle 2 user with the same Moodle 19 user (Teacher)
// So, $usertoken is actually = username (from user table)
$requesting = get_record('user','username',$request->username);
if (empty($requesting)) die; // did not find user. oups :-(
//echo "we have a user";
// Make sure you copy Moodle 19 salt to Moodle 2
// before we compare these MD5 passwards
// DISABLED!!! due to lack of Moodle 19 salt in config.php
//echo "pass=".$teacher->password;
if (md5($request->password) != $requesting->password) die;
//echo "we have a password";


// get actual userid for actual course request (if Admin is not making the request then it is the actual teacher)
$teacher = get_record('user','username',$request->courses4usertoken);

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
        unset($backupfilelist);
        //echo $mycourse->fullname.'<br/>';
        //echo "{$CFG->dataroot}/{$mycourse->id}/backupdata <br/>";
        //echo "<hr/>";
        $courseswithbackupfiles[] = $coursewithbackupfiles;
        unset($coursewithbackupfiles);
    }
    //print_object($courseswithbackupfiles);die;
    echo json_encode($courseswithbackupfiles);
}

function handle_error($message, $response_code){

    $error = new stdClass();
    $error->error = $message;
    echo json_encode($error);
    header('X-PHP-Response-Code: 403', true, $response_code);
    die;
}
