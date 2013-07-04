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
require_once($CFG->dirroot . '/repository/lib.php');

/**
 * repository_moodle19 class
 * A subclass of repository, which is used to download a file from a specific url
 *
 * @since 2.0
 * @package    repository_moodle19
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class repository_moodle19 extends repository {

    private $secret = null;
    private $manual = false;
    private $username;
    private $password;
    private $courses4usertoken;

    private static $iv;



    /**
     * @param int $repositoryid
     * @param object $context
     * @param array $options
     */
    public function __construct($repositoryid, $context = SYSCONTEXTID, $options = array()){
        parent::__construct($repositoryid, $context, $options);



        // The following mathematical calculation takes "long" time so we do it once
        // It safe enough not to redo it for every WS connection we open to the Moodle 19 server

        //$this->moodle19sever = 'http://localhost/moodle-macam/moodle/';
        $moodle19server = get_config('moodle19', 'moodle19server');
        if (stripos(strrev($moodle19server), '/') !== 0){
            $moodle19server.='/';
        }
        $this->ws_endpoint_url = $moodle19server.'admin/ws_get_teacher_courses.php';

        $this->secret = get_config('moodle19', 'secret');
        $this->manual = get_config('moodle19', 'manual');

        $this->sessname = 'repository_moodle19';

        //Deal with user logging in
        $this->username = optional_param('username', '', PARAM_RAW);
        $this->password = optional_param('password', '', PARAM_RAW);

        $this->courses4usertoken = optional_param('courses4usertoken', '', PARAM_RAW);


        $this->login();

    }

    private function initIV(){
        // create a random IV to use with CBC encoding
        // mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC) = 32
        global $SESSION;

        $key = $this->sessname.'_iv';

        if (extension_loaded('mcrypt') && empty($SESSION->{$key})){
            $iv_size = mcrypt_get_iv_size(MCRYPT_RIJNDAEL_256, MCRYPT_MODE_CBC); // =32
            $SESSION->{$key} = mcrypt_create_iv($iv_size, MCRYPT_DEV_URANDOM);
        }
    }

    private function getIV(){
        global $SESSION;

        $key = $this->sessname.'_iv';

        if (empty($SESSION->{$key})){
            $this->initIV();

        }
           return $SESSION->{$key};
    }

    private function login(){

        global $SESSION, $USER;


        if ($this->manual == 0){    //In automatic mode use current user's username
            $this->username = $USER->username;
        }

        if (!has_capability('moodle/site:config', context_system::instance())){ //Make sure no one hacked to get another user's courses. ONLY ADMIN can fill in a value here!
            $this->courses4usertoken = '';
        }


        if (empty($SESSION->{$this->sessname})){ //NEW USER

            //Do not log-in ADMIN users automatically
            if (has_capability('moodle/site:config', context_system::instance()) && (optional_param('submitted', 'false', PARAM_RAW) == 'false')){
                return;
            }

            if (empty($this->password) && $this->manual == 1){
                return;
            }

            if (!empty($this->username)) {

                $SESSION->{$this->sessname} = $this->username;

                if (!empty($this->password)){
                    $SESSION->{$this->sessname}.= '|'.$this->password;
                }
                else {
                    $SESSION->{$this->sessname}.= '|';
                }

                if (!empty($this->courses4usertoken)){
                    $SESSION->{$this->sessname}.= '|'.$this->courses4usertoken;
                }
            }
        }
        else {  //RETURNING USER

            $params = explode('|', $SESSION->{$this->sessname});

            $this->username = $params[0];

            if (sizeof($params) >= 2){
                $this->password = $params[1];
            }

            if (sizeof($params) == 3){
                $this->courses4usertoken = $params[2];
            }
        }
    }


    public function check_login() {
        global $SESSION;
        return !empty($SESSION->{$this->sessname});
    }

    public function logout(){
        global $SESSION;
        unset($SESSION->{$this->sessname});
        return $this->print_login();
    }

    /**
     * @return mixed
     */
    public function print_login() {
        global $USER;

        //$this->usertoken = optional_param('usertoken', '', PARAM_RAW);

        $strdownload = get_string('download', 'repository');
        //$strname     = get_string('rename', 'repository_moodle19');
        //$strmoodle19 = get_string('moodle19', 'repository_moodle19');

        if ($this->options['ajax']) {

            $user = new stdClass();
            $password = new stdClass();
            $courses4usertoken = new stdClass();

            if ( has_capability('moodle/site:config', context_system::instance()) ) {
                $ret['login'] = array();
                if ($this->manual == 1){

                    $user->label = 'Authenticate with Username: ';
                    $user->type = 'text';
                    $user->value = $USER->username; // Admin user can change username, manually
                    $user->id   = 'username';
                    $user->name = 'username';

                    $password->label = 'Password: ';
                    $password->type = 'password';
                    $password->value = ''; // Admin user can change username, manually
                    $password->id = 'password';
                    $password->name = 'password';

                    $ret['login'][] = $user;
                    $ret['login'][] = $password;
                }


                $courses4usertoken->label = 'Request courses for user (Leave empty to restore for yourself): ';
                $courses4usertoken->type = 'text';
                $courses4usertoken->value = '';
                $courses4usertoken->name = 'courses4usertoken';
                $courses4usertoken->id = 'courses4usertoken';

                $submitted = new stdClass();
                $submitted->type = 'hidden';
                $submitted->value = true;
                $submitted->name = 'submitted';
                $submitted->id = 'submitted';


                $ret['login'][] = $courses4usertoken;
                $ret['login'][] = $submitted;


            } else {    //Not ADMIN
                if ($this->manual==1) { //Manual user matching mode. Require USER+PASSWORD
                    // User (Teacher) can use different credentials
                    // to login into a different Moodle 19 system
                    $user->label = 'Username: ';
                    $user->type = 'text';
                    $user->value = $USER->username;
                    $user->id   = 'username';
                    $user->name = 'username';

                    $password->label = 'Password: ';
                    $password->type = 'password';
                    $password->value = '';
                    $password->id = 'password';
                    $password->name = 'password';

                    $ret['login'] = array($user,$password);

                } else {    //Not admin, automatic user matching mode. Do not require anything to login.
                    $ret['login'] = array();
                }

            }

            $ret['login_btn_label'] = get_string('listcoursesandfiles', 'repository_moodle19');

            //$ret['allowcaching'] = true; // indicates that login form can be cached in filepicker.js
            return $ret;
        } else {
            // Not implemented!!!
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

    /**
     * Send a POST requst using cURL
     * @param string $url to request
     * @param array $post values to send
     * @param array $options for cURL
     * @return string
     */
    function curl_post($url, array $post = NULL, array $options = array())
    {
        $defaults = array(
            CURLOPT_POST => 1,
            CURLOPT_HEADER => 0,
            CURLOPT_URL => $url,
            CURLOPT_FRESH_CONNECT => 1,
            CURLOPT_RETURNTRANSFER => 1,
            CURLOPT_FORBID_REUSE => 1,
            CURLOPT_TIMEOUT => 4,
            CURLOPT_POSTFIELDS => http_build_query($post)
        );

        $ch = curl_init();
        curl_setopt_array($ch, ($options + $defaults));
        if(($result = curl_exec($ch)) === false)
        {
            trigger_error(curl_error($ch));
        }

        $response = new stdClass();
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $response->status_code = $http_status;
        $response->data = $result;

        curl_close($ch);
        return $response;
    }


    /**
     * @return array of user's courses and list of backup files within each course
     */
    private function get_remote_data($type, $id, $action) {

        $fields = array(
            'username' => $this->username,
            'password' => $this->password,
            'action' => $action,
            'courses4usertoken' => $this->courses4usertoken
        );

        if (isset($type) && isset($id)){
            $fields['type'] = $type;
            $fields['id'] = $id;
        }

        $base64_json_request = base64_encode(json_encode($fields));

        $data = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($this->secret),$base64_json_request, MCRYPT_MODE_CBC,$this->getIV());

        $result = $this->curl_post($this->ws_endpoint_url,array('request'=>urlencode(base64_encode($this->getIV().$data))));

        if ($result->status_code != 200){   //Print error to enable debugging
            $this->logout();
            print_r($result->data);
        }
        else {
           return json_decode($result->data);
        }
    }



    public function get_file($url, $filename=""){
        set_time_limit(0);
        $fields = array(
          'action' => 'download',
          'username' => $this->username,
          'password' => $this->password,
          'courses4usertoken' => $this->courses4usertoken,
          'file' => $url
        );

        $base64_json_request = base64_encode(json_encode($fields));

        $data = mcrypt_encrypt(MCRYPT_RIJNDAEL_256, md5($this->secret),$base64_json_request, MCRYPT_MODE_CBC,$this->getIV());

        $url = $this->ws_endpoint_url.'?request='.urlencode(base64_encode($this->getIV().$data));

        return parent::get_file($url, $filename);
    }

    /**
     * @param mixed $path
     * @param string $search
     * @return array
     */
    public function get_listing($path='', $page='') {

        if (!extension_loaded('mcrypt')){
            return array();
        }

        $backupfiles = array();
        $backupfiles['path'] = $this->get_navbar($path);
        $backupfiles['list'] = $this->get_list($path);
        $backupfiles['dynload'] = true;
        $backupfiles['nologin'] = false;
        $backupfiles['nosearch'] = true;
        $backupfiles['norefresh'] = false;

        return $backupfiles;
    }


    private function get_list($path) {

        $id = $type = null;
        $list = array();

        if (isset($path) && !empty($path)){
            list($type, $id) = explode('|', $path);
        }


        $remote_list = $this->get_remote_data($type, $id, 'list');

        if (empty($remote_list)){
            return $list;
        }

        foreach ($remote_list as $entry) {
            if ($entry->type == 'course'){
                $list[] =  $this->build_course_entry($entry);
            }
            else if ($entry->type == 'category'){
                $list[] = $this->build_category_entry($entry);
            }
            else if ($entry->type == 'backup_file'){
                //Show only zip files in root directory
                if (strpos($entry->name,'.zip') > 0 && strpos($entry->name, '/') == false){
                    $list[] = $this->build_backupfile_entry($entry, $id);
                }

            }
            else {
                continue;
            }

        }
        return $list;
    }

    private function build_category_entry($entry){
        return array('title' => $entry->name, 'path' => 'category|'.$entry->id, 'children' => array());
    }

    private function build_course_entry($entry){
        global $OUTPUT;

       return  array('title'=>$entry->name, 'path' => 'course|'.$entry->id, 'children'=>array(), 'icon' => $OUTPUT->pix_url('f/moodle-24','core')->out(false));
    }

    private function build_backupfile_entry($entry, $courseid){
        $source = $courseid.'|'.$entry->name;
        return array('title'=> $entry->name, 'date' => $entry->date, 'size' => $entry->size, 'source' => $source);

    }

    // generate repository course navigation PATH
    private function get_navbar($path='') {

        $nav_bar = array(array('name' => empty($this->courses4usertoken) ? $this->username : $this->courses4usertoken, 'path' => ''));

        $id = $type = null;

        if (isset($path) && !empty($path)){
            list($type, $id) = explode('|', $path);
        }
        else {
            return $nav_bar;
        }

        $list = $this->get_remote_data($type, $id, 'navbar');

        if (empty($list)){
            return $nav_bar;
        }


        foreach($list as $navbar_entry){
            $nav_bar[] = array('name' => $navbar_entry->name, 'path' => $navbar_entry->type.'|'.$navbar_entry->id);
        }
        return $nav_bar;
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
        return $this->sessname.':'.$url;
    }

    public static function get_type_option_names() {
        return array('moodle19server', 'secret','manual', 'pluginname');
    }

    public static function type_config_form($mform, $classname = 'repository') {

        parent::type_config_form($mform);
        $mform->addElement('text', 'moodle19server', get_string('moodle19server', 'repository_moodle19'),array('size'=>60));
        $mform->setType('moodle19server', PARAM_URL);

        // Secret should also be set on the Moodle 19 server side
        // navigate to http://moodle-19-server/admin/settings.php?section=experimental
        // And set "moodle2secret"
        $mform->addElement('text', 'secret', get_string('secret', 'repository_moodle19'),array('size'=>15));
        $mform->setType('secret', PARAM_RAW_TRIMMED);

        // Admin can enable "manual" mode, in which the user (Teacher) can use a different username and password
        // (In case the same users has different user credentials on both systems)
        $mform->addElement('checkbox', 'manual', get_string('manual', 'repository_moodle19'));
        $mform->setDefault('manual', false);

        $strrequired = get_string('required');
        $mform->addRule('moodle19server', $strrequired, 'required', null, 'client');
        $mform->addRule('secret', $strrequired, 'required', null, 'client');
    }
}


