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
 * @copyright  2013 Nitzan Bar {@link http://github.com/nitzo}
 * @copyright  2013 MOFET INSTITUTE {@link http://www.mofet.macam.ac.il/eng}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../config.php');

$requestencoded = optional_param('request', null, PARAM_RAW); // Encrypted.

// Handle NEW backupfolder hack by nitzan.
require_once('../backup/lib.php');
$preferences = backup_get_config();
if (!empty($preferences->backup_sche_destination)) {
    $backupfolder = $preferences->backup_sche_destination;
} else {
    $backupfolder = $CFG->dataroot;
}
// End hack.

$request = decode_request($requestencoded);

if (!is_request_valid($request)) {
    handle_error('Request is not valid!', 500);
}

$teacher = authenticate_and_get_user($request);

switch ($request->action){

    // If we got a "download" request then send back the backup file.
    case 'download':

        if (!isset($request->file) || empty($request->file)) {
            handle_error('Backup file not specified!', 500);
        }

        session_write_close(); // Unlock session during fileserving.
        include_once('../lib/filelib.php');
        list($courseid, $filename) = explode('|', $request->file);
        $path = $backupfolder.'/'.$courseid.'/backupdata/'.$filename;

        if (!file_exists($path)) {
            handle_error('File does not exist on disk! '.$path, 404);
        }

        send_file($path, 'moodle19_backupfile.zip');
        die;

        break;

    // Else...
    // We are probably asked for a course list.
    case 'list':

        $list = null;
        if (!isset($request->type)) {
            $list = prepare_category_list();
            // handle_error($list, 500);
        } else if ($request->type == 'category' && isset($request->id)) {

            $categorieslist = prepare_category_list($request->id);
            $courselist  = prepare_course_list($teacher, $request->id);
            // handle_error('debug request=', print_r($request).print_r($courselist));

            $list = array_merge($categorieslist, $courselist);
            // handle_error($courselist, 500);
        } else if ($request->type == 'course' && isset($request->id)) {
            $list = preapare_course_backup_list($backupfolder, $request->id);
            // handle_error($list, 500);
        }

        echo json_encode($list);

        break;

    case 'navbar':
        $list = array();

        if (!isset($request->type)) {
            // do notihing (empty list).
        } else if ($request->type == 'category' && isset($request->id)) {
            $list = prepare_navbar_category_list($request->id);
        } else if ($request->type == 'course' && isset($request->id)) {
            $course = get_record('course', 'id', $request->id);

            $list = prepare_navbar_category_list($course->category);

            $courseentry = new stdClass();

            $courseentry->id = $request->id;
            $courseentry->type = $request->type;
            $courseentry->name = $course->shortname;

            $list[] = $courseentry;
        }

        echo json_encode($list);
        break;

    default:
        handle_error('Invalid operation', 500);
        break;
}

function prepare_category_list($parent=0) {
    $categories = get_categories($parent, null, true);

    $list = array();
    foreach ($categories as $category) {
        $cat = new stdClass();
        $cat->id = $category->id;
        $cat->name = $category->name;
        $cat->type = 'category';
        $cat->hidden = !$category->visible;
        $list[] = $cat;
    }

    return $list;
}

function decode_request($requestencoded) {

    global $CFG;

    // handle_error(urldecode($requestencoded),500);

    $requestdecoded = base64_decode(rawurldecode($requestencoded));



    $ivsize = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC); // Value = 32.
    // Retrieves the IV, iv_size should be created using mcrypt_get_iv_size().
    $iv = substr($requestdecoded, 0, $ivsize);

    // Retrieves the cipher text (everything except the $ivsize in the front).
    $requestdata = substr($requestdecoded, $ivsize);

    $base64data = mcrypt_decrypt(MCRYPT_RIJNDAEL_256, md5($CFG->moodle2secret), $requestdata, MCRYPT_MODE_CBC, $iv);
    $requestdec = base64_decode($base64data);
    $request = json_decode($requestdec);

    return $request;
}

function authenticate_and_get_user($request) {
    global $CFG;

    $teacher = null;

    if (!isset($CFG->repository_moodle19_require_user_password) || $CFG->repository_moodle19_require_user_password == true) {
        $requestinguser = authenticate_user_login($request->username, $request->password);
    } else {
        $requestinguser = get_record('user', 'username', $request->username);
    }

    // Did not find user or authentication failed.
    if (!isset($requestinguser) || empty($requestinguser)) {
        handle_error('Unauthorized request!', 403);
    }

    // If restoring on behalf of a user - verify this is allowed and get actual user.
    if (isset($request->courses4usertoken) && !empty($request->courses4usertoken)) {

        if (isset($CFG->repository_moodle19_allow_restore_onbehalf) && $CFG->repository_moodle19_allow_restore_onbehalf &&
            is_siteadmin($requestinguser->id)) {
            $teacher = get_record('user', 'username', $request->courses4usertoken);
        }

        if (!isset($teacher) || empty($teacher)) {
            handle_error('Unauthorized request! Cannot restore on behalf. Please verify settings.', 403);
        }

    } else {
        $teacher =  $requestinguser;
    }

    // If requesting a course make sure user is teacher in course.
    if (!empty($teacher) && isset($request->type) && $request->type == 'course') {
        $context = get_context_instance(CONTEXT_COURSE, $request->id);
        if (!user_has_role_assignment($teacher->id, 3, $context->id)) {
            handle_error('User is not a teacher in course!!!', 403);
        }
    }

    return $teacher;

}

function prepare_course_list($teacher, $category) {
    $sqlmycourses = "SELECT c.id as id, c.fullname as fullname, c.visible as visible
                    FROM mdl_role_assignments AS ra
                    JOIN mdl_context AS context ON ra.contextid = context.id AND context.contextlevel = 50
                    JOIN mdl_course AS c ON context.instanceid = c.id
                    WHERE ra.roleid = 3 and ra.userid = '".$teacher->id."'
                    AND c.category = '".$category."'
                    ORDER BY c.id DESC";


    $list = array();
    // handle_error(get_records_sql($sqlmycourses ),500);
    if ($mycourses = get_records_sql($sqlmycourses )) {
        foreach ($mycourses as $mycourse) {
            $course = new stdClass();
            $course->id = $mycourse->id;
            $course->name = $mycourse->fullname;
            $course->type = 'course';
            $course->hidden = ($mycourse->visible == 0) ? 1 : 0;

            $list[] = $course;
        }
    }
    return $list;
}

function preapare_course_backup_list($backupfolder, $courseid) {

    $list = array();

    $folder = "{$backupfolder}/{$courseid}/backupdata/";

    $filelist = get_directory_list("{$backupfolder}/{$courseid}/backupdata/");

    foreach ($filelist as $file) {
        $fileentry = new stdClass();

        $fileentry->name = $file;
        $fileentry->size = filesize("{$backupfolder}/{$courseid}/backupdata/{$file}");
        $fileentry->date = filectime("{$backupfolder}/{$courseid}/backupdata/{$file}");
        $fileentry->type = 'backup_file';
        $list[] = $fileentry;
    }

    return $list;

}


function prepare_navbar_category_list($id) {

    $list = array();

    $path = get_record('course_categories', 'id', $id);

    $pathitems = explode('/', $path->path);
    // handle_error($pathitems, 500);
    foreach ($pathitems as $catid) {
        if (empty($catid)) {
            continue;
        }

        $category = get_record('course_categories', 'id', $catid);

        if (isset($category)) {
            $catentry = new stdClass();
            $catentry->id = $category->id;
            $catentry->name = $category->name;
            $catentry->type = 'category';
            $list[] = $catentry;
        }

    }
    return $list;
}

function handle_error($message, $responsecode) {

    $error = new stdClass();
    $error->error = $message;
    echo json_encode($error);
    header('X-PHP-Response-Code: '.$responsecode, true, $responsecode);
    die;
}

function is_request_valid($request) {

    // handle_error($request, 500);
    if (!isset($request) || !isset($request->action)) {
        return false;
    }

    switch ($request->action) {

        case 'download':
            if (!isset($request->file)) {
                return false;
            }
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
