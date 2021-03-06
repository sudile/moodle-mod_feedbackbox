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
 * Setting page for questionaire module
 *
 * @package    mod
 * @subpackage feedbackbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;
if (isset($ADMIN) && $ADMIN->fulltree) {
    $settings->add(new admin_setting_configcheckbox('feedbackbox/allowemailreporting',
        get_string('configemailreporting', 'feedbackbox'), get_string('configemailreportinglong', 'feedbackbox'), 0));

    $secret = get_config('feedbackbox', 'secret');
    if ($secret == null) {
        $secret = bin2hex(random_bytes(50));
        set_config('secret', $secret, 'feedbackbox');
    }

    $settings->add(new admin_setting_configtext('feedbackbox/secret',
        get_string('secret', 'feedbackbox'),
        get_string('secret_help', 'feedbackbox'), $secret));
}