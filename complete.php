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

// This page prints a particular instance of feedbackbox.

use mod_feedbackbox\feedbackbox;
use mod_feedbackbox\output\completepage;

require_once("../../config.php");
require_once("./locallib.php");

if (!isset($SESSION->feedbackbox)) {
    $SESSION->feedbackbox = new stdClass();
}
$SESSION->feedbackbox->current_tab = 'view';

$id = optional_param('id', null, PARAM_INT);    // Course Module ID.
$a = optional_param('a', null, PARAM_INT);      // Feedbackbox ID.

$sid = optional_param('sid', null, PARAM_INT);  // Survey id.
$resume = optional_param('resume', null, PARAM_INT);    // Is this attempt a resume of a saved attempt?
list($cm, $course, $feedbackbox) = feedbackbox_get_standard_page_items($id, $a);

// Check login and get context.
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);
require_capability('mod/feedbackbox:view', $context);

$url = new moodle_url($CFG->wwwroot . '/mod/feedbackbox/complete.php');
if (isset($id)) {
    $url->param('id', $id);
} else {
    $url->param('a', $a);
}

$PAGE->set_url($url);
$PAGE->set_context($context);
$feedbackbox = new feedbackbox(0, $feedbackbox, $course, $cm);
// Add renderer and page objects to the feedbackbox object for display use.
$feedbackbox->add_renderer($PAGE->get_renderer('mod_feedbackbox'));
$feedbackbox->add_page(new completepage());

$feedbackbox->strfeedbackboxs = get_string("modulenameplural", "feedbackbox");
$feedbackbox->strfeedbackbox = get_string("modulename", "feedbackbox");

// Mark as viewed.
$completion = new completion_info($course);
$completion->set_module_viewed($cm);

// Generate the view HTML in the page.
$feedbackbox->view();
// Output the page.
echo $feedbackbox->renderer->header();
echo $feedbackbox->renderer->render($feedbackbox->page);
echo $feedbackbox->renderer->footer($course);