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

/*
 *
 * @package    mod
 * @subpackage feedbackbox
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * This file is executed right after the install.xml
 * @copyright  2010 Remote Learner (http://www.remote-learner.net)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Install needed DB content
 *
 * @throws dml_exception
 */
function xmldb_feedbackbox_install() {
    global $DB;


    $questiontype = new stdClass();
    $questiontype->typeid = 2;
    $questiontype->type = 'Text Box';
    $questiontype->has_choices = 'n';
    $questiontype->response_table = 'response_text';
    $id = $DB->insert_record('feedbackbox_question_type', $questiontype);

    $questiontype = new stdClass();
    $questiontype->typeid = 3;
    $questiontype->type = 'Essay Box';
    $questiontype->has_choices = 'n';
    $questiontype->response_table = 'response_text';
    $id = $DB->insert_record('feedbackbox_question_type', $questiontype);

    $questiontype = new stdClass();
    $questiontype->typeid = 4;
    $questiontype->type = 'Radio Buttons';
    $questiontype->has_choices = 'y';
    $questiontype->response_table = 'resp_single';
    $id = $DB->insert_record('feedbackbox_question_type', $questiontype);

    $questiontype = new stdClass();
    $questiontype->typeid = 5;
    $questiontype->type = 'Check Boxes';
    $questiontype->has_choices = 'y';
    $questiontype->response_table = 'resp_multiple';
    $id = $DB->insert_record('feedbackbox_question_type', $questiontype);

    $questiontype = new stdClass();
    $questiontype->typeid = 99;
    $questiontype->type = 'Page Break';
    $questiontype->has_choices = 'n';
    $questiontype->response_table = '';
    $id = $DB->insert_record('feedbackbox_question_type', $questiontype);

    $questiontype = new stdClass();
    $questiontype->typeid = 100;
    $questiontype->type = 'Section Text';
    $questiontype->has_choices = 'n';
    $questiontype->response_table = '';
    $id = $DB->insert_record('feedbackbox_question_type', $questiontype);

}