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

use mod_feedbackbox\feedbackbox;

require_once("../../config.php");

$instance = optional_param('instance', false, PARAM_INT);   // Feedbackbox ID.
$action = optional_param('action', null, PARAM_ALPHA);
$turnus = optional_param('turnus', null, PARAM_INT);

$userid = $USER->id;

if ($instance === false) {
    if (!empty($SESSION->instance)) {
        $instance = $SESSION->instance;
    } else {
        print_error('requiredparameter', 'feedbackbox');
    }
}
$SESSION->instance = $instance;
$usergraph = get_config('feedbackbox', 'usergraph');

if (!$feedbackbox = $DB->get_record("feedbackbox", ["id" => $instance])) {
    print_error('incorrectfeedbackbox', 'feedbackbox');
}
if (!$course = $DB->get_record("course", ["id" => $feedbackbox->course])) {
    print_error('coursemisconf');
}
if (!$cm = get_coursemodule_from_instance("feedbackbox", $feedbackbox->id, $course->id)) {
    print_error('invalidcoursemodule');
}

require_course_login($course, true, $cm);


$feedbackbox = new feedbackbox(0, $feedbackbox, $course, $cm);
$context = context_module::instance($cm->id);
if (!has_capability('mod/feedbackbox:manage', $context)) {
    // Should never happen, unless called directly by a snoop...
    print_error('nopermissions',
        'moodle',
        $CFG->wwwroot . '/mod/feedbackbox/view.php?id=' . $cm->id,
        get_string('viewallresponses', 'mod_feedbackbox'));
}

$sid = $feedbackbox->survey->id;

$url = new moodle_url($CFG->wwwroot . '/mod/feedbackbox/report.php');
if ($instance) {
    $url->param('instance', $instance);
}
if ($action) {
    $url->param('action', $action);
}
if ($turnus) {
    $url->param('turnus', $turnus);
}
$PAGE->set_url($url);
$PAGE->set_context($context);
$feedbackbox->add_renderer($PAGE->get_renderer('mod_feedbackbox'));
$currentturnus = $feedbackbox->get_current_turnus();

if ($currentturnus != false) {
    $currentturnus = $currentturnus->id;
} else {
    $currentturnus = 1;
}
$turnus = $turnus === null ? $currentturnus : intval($turnus);
if ($action == 'single') {
    $data = $feedbackbox->get_turnus_responses($turnus);
    $data->single = true;
    $PAGE->requires->js_call_amd('mod_feedbackbox/chartview',
        'init',
        [$feedbackbox->id, 'single', $turnus]);
} else {
    $data = $feedbackbox->get_feedback_responses();
    $PAGE->requires->js_call_amd('mod_feedbackbox/chartview',
        'init',
        [$feedbackbox->id, 'all', $data->totalparticipants]);
    $data->all = true;
}
$data->navbarelements = [
    (object) ['url' => new moodle_url('/mod/feedbackbox/report.php',
        ['instance' => $instance]), 'name' => get_string('viewstats', 'mod_feedbackbox')],
    (object) ['url' => new moodle_url('/mod/feedbackbox/report.php',
        ['instance' => $instance, 'action' => 'single']), 'name' => get_string('viewsingle', 'mod_feedbackbox')],
];
if ($action == 'single') {
    $data->navbarelements[1]->active = true;
} else {
    $data->navbarelements[0]->active = true;
}


$PAGE->requires->css('/mod/feedbackbox/style/chart.css');
echo $feedbackbox->renderer->header();
echo $feedbackbox->renderer->render_from_template('mod_feedbackbox/report', $data);
echo $feedbackbox->renderer->footer($course);