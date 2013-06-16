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

// Set Moodle2 secret for connecting Moodle2 repository "Moodle19 Import" plugin to this Moodle
$temp->add(new admin_setting_configtext('moodle2secret', get_string('moodle2secret', 'admin'), get_string('configmoodle2secret', 'admin'), 'secret'));
$ADMIN->add('mnet', $temp);

$temp->add(new admin_setting_configtext('moodle2servername', get_string('moodle2servername', 'admin'), get_string('configmoodle2servername', 'admin'), 'your-moodle2-server-name'));
$ADMIN->add('mnet', $temp);
