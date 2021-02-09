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
 * feedbackbox external functions and service definitions.
 *
 * @package    mod_feedbackbox
 * @category   external
 * @copyright  2018 Igor Sazonov <sovletig@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.0
 */

defined('MOODLE_INTERNAL') || die;

$services = [
    'mod_feedbackbox' => [
        'functions' => ['mod_feedbackbox_chartdata_single', 'mod_feedbackbox_chartdata_multiple'],
        'requiredcapability' => '',
        'enabled' => 1
    ]
];

$functions = [
    'mod_feedbackbox_chartdata_single' => [
        'classname' => 'mod_feedbackbox_external',
        'methodname' => 'get_chartdata_single',
        'classpath' => 'mod/feedbackbox/externallib.php',
        'description' => 'feedbackbox chartdata single',
        'type' => 'read',
        'capabilities' => 'mod/feedbackbox:manage',
        'ajax' => true
    ],
    'mod_feedbackbox_chartdata_multiple' => [
        'classname' => 'mod_feedbackbox_external',
        'methodname' => 'get_chartdata_multiple',
        'classpath' => 'mod/feedbackbox/externallib.php',
        'description' => 'feedbackbox chartdata multiple',
        'type' => 'read',
        'capabilities' => 'mod/feedbackbox:manage',
        'ajax' => true
    ]
];