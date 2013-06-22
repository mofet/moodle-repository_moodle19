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

$request_encoded = optional_param('request',NULL,PARAM_RAW); // encrypted


// Handle NEW backupfolder hack by nitzan
require_once('../backup/lib.php');
$preferences = backup_get_config();
if (!empty($preferences->backup_sche_destination)){
    $backupfolder = $preferences->backup_sche_destination;
} else {
    $backupfolder = $CFG->dataroot;
}
// End hack

$request = decode_request($request_encoded);

if (!is_request_valid($request)){
    handle_error('Unauthorized request!', 403);
}

$action = optional_param('action',NULL,PARAM_RAW);

switch ($request->action){

    // If we got a "download" request then send back the backup file
    case 'download':

        if (!isset($request->file) || empty($request->file)){
            handle_error('Backup file not specified!', 500);
        }


        session_write_close(); // unlock session during fileserving
        include_once('../lib/filelib.php');
        list($courseid, $filename) = explode('|', $request->file);
        $path = $backupfolder.'/'.$courseid.'/backupdata/'.$filename;

        if (!file_exists($path)){
            handle_error('File does not exist on disk! '.$path, 404);
        }

        send_file($path,'moodle19_backupfile.zip');
        die;


        break;

    // else...
    // We are probably asked for a course list
    case 'list':

        $user = authenticate_user($request->username, $request->password, $request->courses4usertoken);

        $list = null;
        if (!isset($request->type)){
            $list = prepare_category_list();
            //handle_error($list, 500);
        }
        else if ($request->type == 'category' && isset($request->id)){

            $categories_list = prepare_category_list($request->id);
            $course_list  = prepare_course_list($user, $request->id);

            $list = array_merge($categories_list, $course_list);
            //handle_error($course_list, 500);
        }
        else if ($request->type == 'course' && isset($request->id)){
            $list = preapare_course_backup_list($backupfolder, $request->id);
            //handle_error($list, 500);
        }

        echo json_encode($list);

        break;

    case 'navbar':
        $list = array();

        if (!isset($request->type)){
            //do notihing (empty list)
        }
        else if ($request->type == 'category' && isset($request->id)){

            $path = get_record('course_categories', 'id', $request->id);

            $path_items = explode('/', $path->path);
            //handle_error($path_items, 500);
            foreach($path_items as $cat_id){
                if (empty($cat_id)){
                    continue;
                }

                $category = get_record('course_categories', 'id', $cat_id);

                if (isset($category)){
                    $cat_entry = new stdClass();
                    $cat_entry->id = $category->id;
                    $cat_entry->name = $category->name;
                    $cat_entry->type = 'category';
                    $list[] = $cat_entry;
                }

            }

        }

        echo json_encode($list);
        break;


    default:
        handle_error('Invalid operation', 500);
        break;
}


function prepare_category_list($parent=0){
    $categories = get_categories($parent, null, true);

    $list = array();
    foreach($categories as $category){
        $cat = new stdClass();
        $cat->id = $category->id;
        $cat->name = $category->name;
        $cat->type = 'category';
        $cat->hidden = !$category->visible;
        $list[] = $cat;
    }

    return $list;
}

function decode_request($request_encoded){

    global $CFG;

    //handle_error(urldecode($request_encoded),500);

    $request_decoded = base64_decode(rawurldecode($request_encoded));



    $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC); // =32
    # retrieves the IV, iv_size should be created using mcrypt_get_iv_size()
    $iv = substr($request_decoded, 0, $iv_size);

    # retrieves the cipher text (everything except the $iv_size in the front)
    $request_data = substr($request_decoded, $iv_size);

    $base64_data = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($CFG->moodle2secret), $request_data, MCRYPT_MODE_CBC,$iv);
    $request_dec = base64_decode($base64_data);
    $request = json_decode($request_dec);
    //print_r($request);

    return $request;
}

function authenticate_user($username, $password, $courses4usertoken){

    // Authentication...
    //

    // for now, we use the unique username which is T"Z (ID Number)
    // to link a Moodle 2 user with the same Moodle 19 user (Teacher)
    // So, $usertoken is actually = username (from user table)
    $requesting = get_record('user','username',$username);
    if (empty($requesting)) die; // did not find user. oups :-(
    //echo "we have a user";
    // Make sure you copy Moodle 19 salt to Moodle 2
    // before we compare these MD5 passwards
    // DISABLED!!! due to lack of Moodle 19 salt in config.php
    //echo "pass=".$teacher->password;
    if (md5($password) != $requesting->password) die;
    //echo "we have a password";


    // get actual userid for actual course request (if Admin is not making the request then it is the actual teacher)
    $teacher = get_record('user','username',$courses4usertoken);


    return $teacher;
}

function prepare_course_list($teacher, $category){
    $sql_mycourses = "SELECT c.id as id, c.fullname as fullname
                    FROM mdl_role_assignments AS ra
                    JOIN mdl_context AS context ON ra.contextid = context.id AND context.contextlevel = 50
                    JOIN mdl_course AS c ON context.instanceid = c.id
                    WHERE ra.roleid = 3 and ra.userid = '".$teacher->id."'
                    AND c.category = '".$category."'
                    ORDER BY c.id DESC";

//echo $sql_mycourses;

    $list = array();
    if ($mycourses = get_records_sql($sql_mycourses )) {
        foreach ($mycourses as $mycourse ) {
            $course = new stdClass();
            $course->id = $mycourse->id;
            $course->name = $mycourse->fullname;
            $course->type = 'course';
            $course->hidden = !$course->visible;

            $list[] = $course;
        }
    }
    return $list;
}

function preapare_course_backup_list($backupfolder, $courseid){

    $list = array();

    $folder = "{$backupfolder}/{$courseid}/backupdata/";

    $file_list = get_directory_list("{$backupfolder}/{$courseid}/backupdata/");

    foreach($file_list as $file) {
        $file_entry = new stdClass();

        $file_entry->name = $file;
        $file_entry->size = filesize("{$backupfolder}/{$courseid}/backupdata/{$file}");
        $file_entry->date = filectime("{$backupfolder}/{$courseid}/backupdata/{$file}");
        $file_entry->type = 'backup_file';
        $list[] = $file_entry;
    }

    return $list;

}



function handle_error($message, $response_code){

    $error = new stdClass();
    $error->error = $message;
    echo json_encode($error);
    header('X-PHP-Response-Code: '.$response_code, true, $response_code);
    die;
}

function is_request_valid($request){
    global $CFG;

    //handle_error($request, 500);
    if (!isset($request) || !isset($request->action)){
        return false;
    }

    switch ($request->action) {

        case 'download':
            return true;
            break;

        case 'list':
            return true;
            break;


        case 'navbar':
            return true;
            break;

        default :
            return false;
            break;

    }


}
