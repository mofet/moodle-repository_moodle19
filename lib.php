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
        $this->usertoken = optional_param('usertoken', '', PARAM_RAW);
        //$this->password = optional_param('password', '', PARAM_RAW);

        //$this->moodle19sever = 'http://localhost/moodle-macam/moodle/';
        $this->moodle19sever = $moodle19server = get_config('moodle19', 'moodle19server');
        $secret = get_config('moodle19', 'secret');
        $this->key = md5($secret);
    }

    public function check_login() {
        //if (!empty($this->courseid)) {
        if (!empty($this->usertoken)) {
            return true;
        } else {
            //$this->usertoken = optional_param('usertoken', '', PARAM_RAW);
            return false;
        }
    }

    /**
     * @return array of user's courses and list of backup files within each course
     */

    public function get_remotecourselist($usertoken,$password = 'null') {
        $ch = curl_init();
        $fields = array(
            'key' => urlencode($this->key),
            'usertoken' => urlencode($usertoken),
            'password' => urlencode($password),
            'action' => 'courselist'
        );
        $fields_string = '';
        foreach($fields as $key=>$value) { $fields_string .= $key.'='.$value.'&'; }
        rtrim($fields_string, '&');

        curl_setopt($ch,CURLOPT_URL, $this->moodle19sever.'ws_get_teacher_courses.php?');
        curl_setopt($ch,CURLOPT_POST, count($fields));
        curl_setopt($ch,CURLOPT_POSTFIELDS, $fields_string);
        curl_setopt($ch,CURLOPT_RETURNTRANSFER, '1');
        $courselist_json = curl_exec($ch);
        curl_close($ch);

        return json_decode($courselist_json);
    }
    /**
     * @return mixed
     */
    public function print_login() {
        global $USER;

        //$this->usertoken = optional_param('usertoken', '', PARAM_RAW);

        $strdownload = get_string('download', 'repository');
        $strname     = get_string('rename', 'repository_moodle19');
        $strmoodle19 = get_string('moodle19', 'repository_moodle19');

        $this->courselist = $this->get_remotecourselist((!empty($this->usertoken))?$this->usertoken:$USER->username /*$USER->password*/);
        $courselist_arr = array();
        if (!empty($this->courselist)) {
            foreach ($this->courselist as $course) {
                array_push($courselist_arr,(object)array( 'value' => $course->id, 'label' => $course->fullname ));
                //foreach ($course->backupfilelist as $id => $backupfile) {
                // backup file(s)
                //array_push($courselist_arr,(object)array( 'value' => "{$course->id}/backupdata/{$backupfile}", 'label' => "  >> ".$backupfile));
                //}
            }

        }

        if ($this->options['ajax']) {

            $user = new stdClass();

            $user->id   = 'usertoken';
            $user->name = 'usertoken';
            if (has_capability('moodle/site:config', context_system::instance())) {
                $user->label = 'Username: ';
                $user->type = 'text'; // Admin user can change username, manually
                $user->value = $this->usertoken;
            } else {
                $user->label = 'Username: Using logged-in username';
                $user->type = 'hidden';
                $user->value = $USER->username;
            }

            /*
            $login = new stdClass();
            $login->label = 'Password: ';
            $login->id   = 'password';
            $login->type = 'text';
            $login->name = 'password';
            */

            // This SELECT INPUT element is not used.
            // We display the list of courses on the next page
            /*
            $courseid = new stdClass();
            $courseid->type = 'select';
            $courseid->options = $courselist_arr;
            $courseid->id = 'courseid';
            $courseid->name = 'courseid';
            $courseid->label = get_string('courseid', 'repository_moodle19').': ';
            */

            // Get the list of backup files by using the courseid for a specific user(id)
            //$ret['login'] = array($user,/* $login, */ $courseid);

            // We just need the user(id) to get the list of courses and backup files from the remote Moodle 19 server
            $ret['login'] = array($user);
            //$ret['login_btn_label'] = get_string('download', 'repository_moodle19');
            $ret['login_btn_label'] = get_string('listcoursesandfiles', 'repository_moodle19');

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
        $backupfiles['path'] = $this->list_backupcourses();
        //$backupfiles['list'] = $this->list_backupfiles($this->courseid);
        $backupfiles['list'] = $this->list_backupcoursesandfiles();
        $backupfiles['nologin'] = false;
        $backupfiles['nosearch'] = true;
        $backupfiles['norefresh'] = true;


        return $backupfiles;
    }

    // This function is not used
    public function list_backupfiles($courseid) {
        global $USER;
        $files_array = array();
        $this->courselist = $this->get_remotecourselist((!empty($this->usertoken))?$this->usertoken:$USER->username); /// todo: must be a way to call this function only once
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

    public function list_backupcoursesandfiles() {
        global $USER;

        $courses_array = array();
        $files_array = array();
        $this->courselist = $this->get_remotecourselist((!empty($this->usertoken))?$this->usertoken:$USER->username); /// todo: must be a way to call this function only once
        foreach ($this->courselist as $course) {
            foreach ($course->backupfilelist as $id => $backupfile) {
                    // backup file(s)
                    $files_array[] = array(
                        'title'=>$backupfile->name,
                        'date'=>$backupfile->date,
                        'size'=>$backupfile->size,
                        //'thumbnail'=>$thumbnail,
                        'thumbnail_width'=>16,
                        'thumbnail_height'=>16,
                        // plugin-dependent unique path to the file (id, url, path, etc.)
                        'source'=>"{$this->moodle19sever}ws_get_teacher_courses.php?key={$this->key}&action=download&backupfile={$course->id}/backupdata/{$backupfile->name}",
                        // the accessible url of the file
                        //'url'=>"{$this->moodle19sever}/ws_get_backupfile.php?file={$course->id}/backupdata/{$backupfile}"
                    );

                }
            $courses_array[] = array('title'=>$course->fullname,'children'=>$files_array);
            unset($files_array);
        }
        return $courses_array;
    }

    public function list_backupcourses() {
        global $USER;

        $courses_array = array(array('name'=>get_string('courses','repository_moodle19'),'path'=>'/'));
        $this->courselist = $this->get_remotecourselist((!empty($this->usertoken))?$this->usertoken:$USER->username); /// todo: must be a way to call this function only once
        /*
        foreach ($this->courselist as $course) {
            $courses_array[] = array('name'=>$course->fullname,'path'=>'/'.$course->id);
        }*/
        return $courses_array;
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

    public static function get_type_option_names() {
        return array('moodle19server', 'secret', 'pluginname');
    }

    public static function type_config_form($mform, $classname = 'repository') {

        parent::type_config_form($mform);
        $mform->addElement('text', 'moodle19server', get_string('moodle19server', 'repository_moodle19'));
        $mform->setType('moodle19server', PARAM_URL);

        // Secret should also be set on the Moodle 19 server side
        // navigate to http://moodle-19-server/admin/settings.php?section=experimental
        // And set "moodle2secret"
        $mform->addElement('text', 'secret', get_string('secret', 'repository_moodle19'));
        $mform->setType('secret', PARAM_RAW_TRIMMED);

        $strrequired = get_string('required');
        $mform->addRule('moodle19server', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }
}

