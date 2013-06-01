<?php

// Set Moodle2 secret for connecting Moodle2 repository "Moodle19 Import" plugin to this Moodle
$temp->add(new admin_setting_configtext('moodle2secret', get_string('moodle2secret', 'admin'), get_string('configmoodle2secret', 'admin'), 'Cde34rfV'));
$ADMIN->add('mnet', $temp);
