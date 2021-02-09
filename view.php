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
require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

if (!isset($SESSION->feedbackbox)) {
    $SESSION->feedbackbox = new stdClass();
}
$SESSION->feedbackbox->current_tab = 'view';

$id = optional_param('id', null, PARAM_INT);    // Course Module ID.
$a = optional_param('a', null, PARAM_INT);      // Or feedbackbox ID.

$sid = optional_param('sid', null, PARAM_INT);  // Survey id.

list($cm, $course, $feedbackbox) = feedbackbox_get_standard_page_items($id, $a);

// Check login and get context.
require_course_login($course, true, $cm);
$context = context_module::instance($cm->id);

$url = new moodle_url($CFG->wwwroot . '/mod/feedbackbox/view.php');
if (isset($id)) {
    $url->param('id', $id);
} else {
    $url->param('a', $a);
}
if (isset($sid)) {
    $url->param('sid', $sid);
}

$PAGE->set_url($url);
$PAGE->set_context($context);
$feedbackbox = new feedbackbox(0, $feedbackbox, $course, $cm);
// Add renderer and page objects to the feedbackbox object for display use.
$feedbackbox->add_renderer($PAGE->get_renderer('mod_feedbackbox'));
$feedbackbox->add_page(new \mod_feedbackbox\output\viewpage());

$PAGE->set_title(format_string($feedbackbox->name));
$PAGE->set_heading(format_string($course->fullname));

echo $feedbackbox->renderer->header();
$feedbackbox->page->add_to_page('feedbackboxname', format_string($feedbackbox->name));

// Print the main part of the page.
if ($feedbackbox->intro) {
    $feedbackbox->page->add_to_page('intro', format_module_intro('feedbackbox', $feedbackbox, $cm->id));
}

$cm = $feedbackbox->cm;
$currentgroupid = groups_get_activity_group($cm);
if (!groups_is_member($currentgroupid, $USER->id)) {
    $currentgroupid = 0;
}

$message = $feedbackbox->user_access_messages($USER->id);
if ($message !== false) {
    $feedbackbox->page->add_to_page('message', $message);
} else if ($feedbackbox->user_can_take($USER->id)) {
    if ($feedbackbox->questions) { // Sanity check.
        if (!$feedbackbox->user_has_saved_response($USER->id)) {
            $feedbackbox->page->add_to_page('complete',
                '<a href="' . $CFG->wwwroot . htmlspecialchars('/mod/feedbackbox/complete.php?' .
                    'id=' . $feedbackbox->cm->id) . '" class="btn btn-primary">' .
                get_string('answerquestions', 'feedbackbox') . '</a>');
        } else {
            $resumesurvey = get_string('resumesurvey', 'feedbackbox');
            $feedbackbox->page->add_to_page('complete',
                '<a href="' . $CFG->wwwroot . htmlspecialchars('/mod/feedbackbox/complete.php?' .
                    'id=' . $feedbackbox->cm->id . '&resume=1') . '" title="' . $resumesurvey .
                '" class="btn btn-primary">' . $resumesurvey . '</a>');
        }
    } else {
        $feedbackbox->page->add_to_page('message', get_string('noneinuse', 'feedbackbox'));
    }
}

if ($feedbackbox->capabilities->editquestions && !$feedbackbox->questions && $feedbackbox->is_active()) {
    $feedbackbox->page->add_to_page('complete',
        '<a href="' . $CFG->wwwroot . htmlspecialchars('/mod/feedbackbox/questions.php?' .
            'id=' . $feedbackbox->cm->id) . '" class="btn btn-primary">' .
        get_string('addquestions', 'feedbackbox') . '</a>');
}

if (isguestuser()) {
    $guestno = html_writer::tag('p', get_string('noteligible', 'feedbackbox'));
    $liketologin = html_writer::tag('p', get_string('liketologin'));
    $feedbackbox->page->add_to_page('guestuser',
        $feedbackbox->renderer->confirm($guestno . "\n\n" . $liketologin . "\n",
            get_login_url(),
            get_local_referer(false)));
}

// Log this course module view.
// Needed for the event logging.
$context = context_module::instance($feedbackbox->cm->id);
$anonymous = $feedbackbox->respondenttype == 'anonymous';

$usernumresp = $feedbackbox->count_submissions($USER->id);

if ($feedbackbox->capabilities->readownresponses && ($usernumresp > 0)) {
    $argstr = 'instance=' . $feedbackbox->id . '&user=' . $USER->id;
}

if (has_capability('mod/feedbackbox:manage', $context)) {
    $argstr = 'instance=' . $feedbackbox->id . '&group=' . $currentgroupid;
    $feedbackbox->page->add_to_page('allresponses',
        '<a href="' . $CFG->wwwroot . htmlspecialchars('/mod/feedbackbox/report.php?' . $argstr) . '" class="btn btn-primary">' .
        get_string('viewallresponses', 'feedbackbox') . '</a>');
}

echo $feedbackbox->renderer->render($feedbackbox->page);
echo $feedbackbox->renderer->footer();
