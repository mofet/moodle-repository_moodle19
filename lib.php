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
 * This plugin is used to access files by providing an moodle19
 *
 * @since 2.0
 * @package    repository_moodle19
 * @copyright  2010 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once($CFG->dirroot . '/repository/lib.php');
require_once(dirname(__FILE__).'/locallib.php');

/**
 * repository_moodle19 class
 * A subclass of repository, which is used to download a file from a specific url
 *
 * @since 2.0
 * @package    repository_moodle19
 * @copyright  2009 Dongsheng Cai {@link http://dongsheng.org}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_moodle19 extends repository {
    private $processedfiles = array();
    private $courselist = array();
    private $key = null;
    /**
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()){
        global $CFG;
        parent::__construct($repositoryid, $context, $options);
        $this->courseid = optional_param('courseid', '', PARAM_RAW);
        $this->password = optional_param('password', '', PARAM_RAW);
        $this->moodle19sever = 'http://localhost/moodle-macam/moodle/';
        // we better have this key read from global settings (for better security)
        $this->key = md5('12345');
    }

    public function check_login() {
        if (!empty($this->courseid)) {
            return true;
        } else {
            return false;
        }
    }

    /**
     * @return array of user's courses and list of backup files within each course
     */

    public function get_remotecourselist($userid = '3',$password = 'student01') {
        $ch = curl_init();
        $fields = array(
            'key' => urlencode($this->key),
            'userid' => urlencode($userid),
            'password' => urlencode($password),
            'action' => 'courselist'
        );
        $fields_string = '';
        foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
        rtrim($fields_string, '&');

        curl_setopt($ch,CURLOPT_URL, $this->moodle19sever.'ws_get_teacher_courses.php');
        curl_setopt($ch,CURLOPT_POST, count($fields));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, '1');
        $courselist_json = curl_exec($ch);
        curl_close($ch);

        //$this->courselist = json_decode($courselist_json);
        return json_decode($courselist_json);
    }
    /**
     * @return mixed
     */
    public function print_login() {
        $strdownload = get_string('download', 'repository');
        $strname     = get_string('rename', 'repository_moodle19');
        $strmoodle19 = get_string('moodle19', 'repository_moodle19');

        $this->courselist = $this->get_remotecourselist('3','student01');
        $courselist_arr = array();
        foreach ($this->courselist as $course) {
            array_push($courselist_arr,(object)array( 'value' => $course->id, 'label' => $course->fullname ));
            //foreach ($course->backupfilelist as $id => $backupfile) {
                // backup file(s)
                //array_push($courselist_arr,(object)array( 'value' => "{$course->id}/backupdata/{$backupfile}", 'label' => "  >> ".$backupfile));
            //}
        }

        if ($this->options['ajax']) {
            $login = new stdClass();
            $login->label = 'Password: ';
            $login->id   = 'password';
            $login->type = 'text';
            $login->name = 'password';

//            $useridnumber = new stdClass();
//            $useridnumber->label = 'User ID: ';
//            $useridnumber->id   = 'useridnumber';
//            $useridnumber->type = 'hidden';
//            $useridnumber->name = 'useridnumber';

            $courseid = new stdClass();
            $courseid->type = 'select';
            $courseid->options = $courselist_arr;
            $courseid->id = 'courseid';
            $courseid->name = 'courseid';
            $courseid->label = get_string('courseid', 'repository_moodle19').': ';

            $ret['login'] = array($login,$courseid);//,$useridnumber);
            $ret['login_btn_label'] = get_string('download', 'repository_moodle19');

            $ret['allowcaching'] = true; // indicates that login form can be cached in filepicker.js
            return $ret;
        } else {
            echo <<<EOD
<table>
<tr>
<td>Choose a course: </td><td><input name="courseid" type="text" /></td>
<td>Password: </td><td><input name="password" type="text" placeholder="password"/></td>
</tr>
</table>
<input type="submit" value="{$strdownload}" />
EOD;

        }
    }

//    public function get_file($backupfile, $title = '') {
//        global $CFG;
//        $url = urldecode($this->moodle19sever.'/ws_get_backupfile.php?file='.$backupfile);
//        $path = $this->prepare_file($title);
//        $buffer = file_get_contents($url);
//        $fp = fopen($path, 'wb');
//        fwrite($fp, $buffer);
//        return array('path'=>$path);
//    }

    /**
     * @param mixed $path
     * @param string $search
     * @return array
     */
    public function get_listing($path='', $page='') {
        $backupfiles = array();
        $backupfiles['list'] = $this->list_backupfiles($this->courseid);
        $backupfiles['nologin'] = false;
        $backupfiles['nosearch'] = true;
        $backupfiles['norefresh'] = true;


        return $backupfiles;
    }

    public function list_backupfiles($courseid) {

        $files_array = array();
        $this->courselist = $this->get_remotecourselist(); /// todo: must be a way to call this function only once
        foreach ($this->courselist as $course) {
            if ($course->id == $courseid ) {
                foreach ($course->backupfilelist as $id => $backupfile) {
                    // backup file(s)
                    $files_array[] = array(
                        'title'=>$course->fullname.' '.$backupfile,         //chop off 'File:'
                        //'thumbnail'=>$thumbnail,
                        'thumbnail_width'=>32,
                        'thumbnail_height'=>32,
                        // plugin-dependent unique path to the file (id, url, path, etc.)
                        'source'=>"{$this->moodle19sever}ws_get_teacher_courses.php?key={$this->key}&action=download&backupfile={$course->id}/backupdata/{$backupfile}",
                        // the accessible url of the file
                        //'url'=>"{$this->moodle19sever}/ws_get_backupfile.php?file={$course->id}/backupdata/{$backupfile}"
                    );

                }
            }
        }
        return $files_array;
    }

    public function supported_returntypes() {
        return (FILE_INTERNAL | FILE_EXTERNAL);
    }

    /**
     * Return the source information
     *
     * @param stdClass $url
     * @return string|null
     */
    public function get_file_source_info($url) {
        return $url;
    }
}

