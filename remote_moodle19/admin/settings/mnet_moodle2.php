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
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

    $temp = new admin_settingpage('course_export_ws', 'Course Export Webservice');

    // Set Moodle2 secret for connecting Moodle2 repository "Moodle19 Import" plugin to this Moodle.
    $temp->add(new admin_setting_configtext('moodle2secret', 'Export Webservice secret', 'Export Webservice Secret. This should match in Moodle2 or any other server that is using this service. KEEP THIS SAFE!', 'secret'));

    $temp->add(new admin_setting_configcheckbox('repository_moodle19_require_user_password', 'Require password', 'When a remote server requests data from this server only serve content if username and password are present in request and they match local user\'s password' ,true, true, false));

    $temp->add(new admin_setting_configcheckbox('repository_moodle19_allow_restore_onbehalf', 'Request on behalf', 'Allow remote server to request data on behalf of other users. No password validation is done. NOTE: When require password is off other server can request any course data with no validation! Use with care!', false, true, false));

    $ADMIN->add('mnet', $temp);
