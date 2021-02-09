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

// Library of functions and constants for module feedbackbox.

/**
 * @package    mod_feedbackbox
 * @copyright  2016 Mike Churchward (mike.churchward@poetgroup.org)
 * @author     Mike Churchward
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use mod_feedbackbox\feedbackbox;

defined('MOODLE_INTERNAL') || die();

define('FEEDBACKBOX_RESETFORM_RESET', 'feedbackbox_reset_data_');
define('FEEDBACKBOX_RESETFORM_DROP', 'feedbackbox_drop_feedbackbox_');


function feedbackbox_supports($feature) {
    switch ($feature) {
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return false;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

function feedbackbox_cm_info_dynamic(cm_info $cm) {
    GLOBAL $CFG, $DB, $COURSE; // Required by include once.
    if ($COURSE->id == $cm->course) { // Avoid performance issues.
        $cousem = get_coursemodule_from_instance("feedbackbox", $cm->instance, $cm->course);
        $feedbackbox = new feedbackbox(0,
            $DB->get_record("feedbackbox", ["id" => $cousem->instance]),
            $cousem->course,
            $cousem);
        $zone = $feedbackbox->get_current_turnus();
        if ($zone !== false) {
            $calc = round(($zone->to - time()) / (60 * 60 * 24));
            $zone->daysleft = $calc . ' ';
            if ($calc > 1) {
                // Plural.
                $zone->daysleft .= get_string('cminfo_days', 'mod_feedbackbox');
            } else {
                // Singular.
                $zone->daysleft .= get_string('cminfo_day', 'mod_feedbackbox');
            }
            $cm->set_content(get_string('cminfodescription', 'mod_feedbackbox', $zone));
        } else {
            $cm->set_content(get_string('noturnusfound', 'mod_feedbackbox'));
        }
    }
}

/**
 * @return array all other caps used in module
 */
function feedbackbox_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

function feedbackbox_get_instance($feedbackboxid) {
    global $DB;
    return $DB->get_record('feedbackbox', ['id' => $feedbackboxid]);
}

function feedbackbox_add_template_object($courseid, $turnus, $start, $end, $intro, $notifystudents) {
    GLOBAL $DB;

    // Create survey.
    $survey = new stdClass();
    $survey->name = 'Feedback Box';
    $survey->courseid = $courseid;
    $survey->realm = 'private';
    $survey->status = 0;
    $survey->title = 'Feedback Box';
    $survey->email = '';
    $survey->subtitle = '';
    $survey->info = '';
    $survey->theme = '';
    $survey->thanks_page = '';
    $survey->thank_head = '';
    $survey->thank_body = '';
    $survey->feedbacksections = 0;
    $survey->feedbacknotes = '';
    $survey->feedbackscores = 0;
    $surveyid = $DB->insert_record('feedbackbox_survey', $survey);

    // Create Feedbackbox.
    $feedbackbox = new stdClass();
    $feedbackbox->course = $courseid;
    $feedbackbox->name = 'Feedback Box';
    $feedbackbox->intro = $intro;
    $feedbackbox->introformat = 1;
    $feedbackbox->qtype = 3;
    $feedbackbox->respondenttype = 'anonymous';
    $feedbackbox->resp_eligible = 'all';
    $feedbackbox->resp_view = 1;
    $feedbackbox->notifications = 0;
    $feedbackbox->opendate = $start;
    $feedbackbox->closedate = $end;
    $feedbackbox->resume = 0;
    $feedbackbox->navigate = 0;
    $feedbackbox->grade = 0;
    $feedbackbox->turnus = $turnus;
    $feedbackbox->sid = $surveyid;
    $feedbackbox->notifystudents = $notifystudents;

    $feedbackboxid = $DB->insert_record('feedbackbox', $feedbackbox);

    $essay = 3;
    $radio = 4;
    $check = 5;
    $section = 100;

    create_feedbackbox_question($surveyid,
        null,
        $radio,
        1,
        0,
        1,
        '<p><h4>Schritt 1/3:<br/><b>Hey, wie kommst du im Kurs zurecht?<span style="color: red;">*</span></b></h4></p>',
        'y',
        'n',
        ['Hypergalaktisch gut!', 'Läuft wie geschmiert.', 'Es ist okay.', 'Hilfe - Ich komme gar nicht klar!']);
    create_feedbackbox_question($surveyid, null, 99, 0, 0, 2, 'break', 'n', 'n');
    create_feedbackbox_question($surveyid,
        null,
        $section,
        0,
        0,
        3,
        '<h4>Schritt 2/3:<br/><b>Was läuft gut?</b></h4>',
        'n',
        'n');
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        4,
        '<p>STRUKTUR &amp; WORKLOAD<br></p>',
        'n',
        'n',
        ['Ablaufplan', 'Anforderungsniveau', 'Aufgabenumfang', 'Prüfungsleistung']);

    create_feedbackbox_question($surveyid,
        null,
        $check,
        1,
        5,
        5,
        '<p>ORGANISATORISCHES<br></p>',
        'n',
        'n',
        ['Zeitumfang', 'Pünktlichkeit', 'Pausen', 'Teilnehmendenanzahl', 'Technischer Zugang']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        6,
        '<p>MEDIEN &amp; KOMMUNIKATION<br></p>',
        'n',
        'n',
        ['Medienformate', 'Materialien', 'Betreuung', 'Feedbackkultur']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        7,
        '<p>INHALTE<br></p>',
        'n',
        'n',
        ['Themenschwerpunkte', 'Fachliche Tiefe', 'Roter Faden', 'Verständlichkeit']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        8,
        '<p>GRUPPENDYNAMIK &amp; WEITERES<br></p>',
        'n',
        'n',
        ['Anwesenheit', 'Beteiligung', 'Motivation', 'Zwischenmenschliches', 'Sonstiges']);
    create_feedbackbox_question($surveyid,
        null,
        $essay,
        5,
        1,
        9,
        '<p><h4><b>Kannst du das genauer erklären?</b></h4></p>',
        'n',
        'n');
    create_feedbackbox_question($surveyid, null, 99, 0, 0, 10, 'break', 'n', 'n');
    create_feedbackbox_question($surveyid,
        null,
        $section,
        0,
        0,
        11,
        '<h4>Schritt 3/3:<br/><b>Was könnte besser laufen?</b></h4>',
        'n',
        'n');
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        12,
        '<p>STRUKTUR &amp; WORKLOAD<br></p>',
        'n',
        'n',
        ['Ablaufplan', 'Anforderungsniveau', 'Aufgabenumfang', 'Prüfungsleistung']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        1,
        5,
        13,
        '<p>ORGANISATORISCHES<br></p>',
        'n',
        'n',
        ['Zeitumfang', 'Pünktlichkeit', 'Pausen', 'Teilnehmendenanzahl', 'Technischer Zugang']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        14,
        '<p>MEDIEN &amp; KOMMUNIKATION<br></p>',
        'n',
        'n',
        ['Medienformate', 'Materialien', 'Betreuung', 'Feedbackkultur']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        15,
        '<p>INHALTE<br></p>',
        'n',
        'n',
        ['Themenschwerpunkte', 'Fachliche Tiefe', 'Roter Faden', 'Verständlichkeit']);
    create_feedbackbox_question($surveyid,
        null,
        $check,
        0,
        0,
        16,
        '<p>GRUPPENDYNAMIK &amp; WEITERES<br></p>',
        'n',
        'n',
        ['Anwesenheit', 'Beteiligung', 'Motivation', 'Zwischenmenschliches', 'Sonstiges']);
    create_feedbackbox_question($surveyid,
        null,
        $essay,
        5,
        1,
        17,
        '<p><h4><b>Kannst du das genauer erklären?</b></h4></p>',
        'n',
        'n');
    create_feedbackbox_question($surveyid, null, 99, 0, 0, 18, 'break', 'n', 'n');
    return $feedbackboxid;
}

function create_feedbackbox_question($surveyid,
    $name,
    $type,
    $length,
    $precise,
    $position,
    $content,
    $required,
    $deleted,
    $choices = []) {
    GLOBAL $DB;
    $question = new stdClass();
    $question->surveyid = $surveyid;
    $question->name = $name;
    $question->type_id = $type;
    $question->length = $length;
    $question->precise = $precise;
    $question->position = $position;
    $question->content = $content;
    $question->required = $required;
    $question->deleted = $deleted;
    $questionid = $DB->insert_record('feedbackbox_question', $question);
    if ($type === 5 || $type === 4) {
        $choiceobjs = [];
        foreach ($choices as $choice) {
            $choiceobj = new stdClass();
            $choiceobj->content = $choice;
            $choiceobj->question_id = $questionid;
            $choiceobjs[] = $choiceobj;
        }
        $DB->insert_records('feedbackbox_quest_choice', $choiceobjs);
    }
}

function feedbackbox_add_instance($feedbackbox) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');
    if (!isset($feedbackbox->notifystudents) || $feedbackbox->notifystudents == null) {
        $feedbackbox->notifystudents = 0;
    }
    return feedbackbox_add_template_object($feedbackbox->course,
        $feedbackbox->turnus,
        $feedbackbox->opendate,
        $feedbackbox->closedate,
        $feedbackbox->intro,
        $feedbackbox->notifystudents);
}

function feedbackbox_update_instance($feedbackbox) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');
    $feedbackboxorg = $DB->get_record('feedbackbox', ['id' => $feedbackbox->instance]);
    // Check the realm and set it to the survey if its set.
    if (!empty($feedbackbox->sid) && !empty($feedbackbox->realm)) {
        $DB->set_field('feedbackbox_survey', 'realm', $feedbackbox->realm, ['id' => $feedbackbox->sid]);
    }
    $feedbackboxorg->timemodified = time();
    $feedbackboxorg->turnus = $feedbackbox->turnus;
    // $feedbackboxorg->opendate = $feedbackbox->opendate;
    $feedbackboxorg->closedate = $feedbackbox->closedate;

    if (!isset($feedbackbox->notifystudents) || $feedbackbox->notifystudents == null) {
        $feedbackboxorg->notifystudents = 0;
    } else {
        $feedbackboxorg->notifystudents = $feedbackbox->notifystudents;
    }

    $feedbackbox->id = $feedbackboxorg->id;
    // Get existing grade item.
    $completiontimeexpected = !empty($feedbackbox->completionexpected) ? $feedbackbox->completionexpected : null;
    \core_completion\api::update_completion_date_event($feedbackbox->coursemodule,
        'feedbackbox',
        $feedbackboxorg->id,
        $completiontimeexpected);
    $feedbackboxorg->intro = $feedbackbox->intro;
    return $DB->update_record("feedbackbox", $feedbackboxorg);
}

// Given an ID of an instance of this module,
// this function will permanently delete the instance
// and any data that depends on it.
function feedbackbox_delete_instance($id) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');
    $cm = get_coursemodule_from_instance('feedbackbox', $id);
    if (!has_capability('mod/feedbackbox:deleteinstance', context_module::instance($cm->id))) {
        return false;
    }
    if (!$feedbackbox = $DB->get_record('feedbackbox', ['id' => $id])) {
        return false;
    }

    $result = true;

    if ($events = $DB->get_records('event', ["modulename" => 'feedbackbox', "instance" => $feedbackbox->id])) {
        foreach ($events as $event) {
            $event = calendar_event::load($event);
            $event->delete();
        }
    }

    if (!$DB->delete_records('feedbackbox', ['id' => $feedbackbox->id])) {
        $result = false;
    }

    if ($survey = $DB->get_record('feedbackbox_survey', ['id' => $feedbackbox->sid])) {
        // If this survey is owned by this course, delete all of the survey records and responses.
        if ($survey->courseid == $feedbackbox->course) {
            $result = $result && feedbackbox_delete_survey($feedbackbox->sid, $feedbackbox->id);
        }
    }

    return $result;
}

// Return a small object with summary information about what a
// user has done with a given particular instance of this module
// Used for user activity reports.
// $return->time = the time they did it
// $return->info = a short text description.
/**
 * $course and $mod are unused, but API requires them. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_user_outline($course, $user, $mod, $feedbackbox) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

    $result = new stdClass();
    if ($responses = feedbackbox_get_user_responses($feedbackbox->id, $user->id, true)) {
        $n = count($responses);
        if ($n == 1) {
            $result->info = $n . ' ' . get_string("response", "feedbackbox");
        } else {
            $result->info = $n . ' ' . get_string("responses", "feedbackbox");
        }
        $lastresponse = array_pop($responses);
        $result->time = $lastresponse->submitted;
    } else {
        $result->info = get_string("noresponses", "feedbackbox");
    }
    return $result;
}

// Print a detailed representation of what a  user has done with
// a given particular instance of this module, for user activity reports.
/**
 * $course and $mod are unused, but API requires them. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_user_complete($course, $user, $mod, $feedbackbox) {
    global $CFG;
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

    if ($responses = feedbackbox_get_user_responses($feedbackbox->id, $user->id, false)) {
        foreach ($responses as $response) {
            if ($response->complete == 'y') {
                echo get_string('submitted', 'feedbackbox') . ' ' . userdate($response->submitted) . '<br />';
            } else {
                echo get_string('attemptstillinprogress',
                        'feedbackbox') . ' ' . userdate($response->submitted) . '<br />';
            }
        }
    } else {
        print_string('noresponses', 'feedbackbox');
    }

    return true;
}

// Given a course and a time, this module should find recent activity
// that has occurred in feedbackbox activities and print it out.
// Return true if there was output, or false is there was none.
/**
 * $course, $isteacher and $timestart are unused, but API requires them. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_print_recent_activity($course, $isteacher, $timestart) {
    return false;  // True if anything was printed, otherwise false.
}

// Must return an array of grades for a given instance of this module,
// indexed by user.  It also returns a maximum allowed grade.
/**
 * $feedbackboxid is unused, but API requires it. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_grades($feedbackboxid) {
    return null;
}

/**
 * Return grade for given user or all users.
 *
 * @param int $feedbackboxid id of assignment
 * @param int $userid        optional user id, 0 means all users
 * @return array array of grades, false if none
 */
function feedbackbox_get_user_grades($feedbackbox, $userid = 0) {
    global $DB;
    $params = [];
    $usersql = '';
    if (!empty($userid)) {
        $usersql = "AND u.id = ?";
        $params[] = $userid;
    }

    $sql = "SELECT r.id, u.id AS userid, r.grade AS rawgrade, r.submitted AS dategraded, r.submitted AS datesubmitted
            FROM {user} u, {feedbackbox_response} r
            WHERE u.id = r.userid AND r.feedbackboxid = $feedbackbox->id AND r.complete = 'y' $usersql";
    return $DB->get_records_sql($sql, $params);
}

/**
 * Update grades by firing grade_updated event
 *
 * @param object $assignment null means all assignments
 * @param int    $userid     specific user only, 0 mean all
 *
 * $nullifnone is unused, but API requires it. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_update_grades($feedbackbox = null, $userid = 0, $nullifnone = true) {
    global $CFG, $DB;

    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir . '/gradelib.php');
    }

    if ($feedbackbox != null) {
        if ($graderecs = feedbackbox_get_user_grades($feedbackbox, $userid)) {
            $grades = [];
            foreach ($graderecs as $v) {
                if (!isset($grades[$v->userid])) {
                    $grades[$v->userid] = new stdClass();
                    if ($v->rawgrade == -1) {
                        $grades[$v->userid]->rawgrade = null;
                    } else {
                        $grades[$v->userid]->rawgrade = $v->rawgrade;
                    }
                    $grades[$v->userid]->userid = $v->userid;
                } else if (isset($grades[$v->userid]) && ($v->rawgrade > $grades[$v->userid]->rawgrade)) {
                    $grades[$v->userid]->rawgrade = $v->rawgrade;
                }
            }
            feedbackbox_grade_item_update($feedbackbox, $grades);
        } else {
            feedbackbox_grade_item_update($feedbackbox);
        }

    } else {
        $sql = "SELECT q.*, cm.idnumber as cmidnumber, q.course as courseid
                  FROM {feedbackbox} q, {course_modules} cm, {modules} m
                 WHERE m.name='feedbackbox' AND m.id=cm.module AND cm.instance=q.id";
        if ($rs = $DB->get_recordset_sql($sql)) {
            foreach ($rs as $feedbackbox) {
                if ($feedbackbox->grade != 0) {
                    feedbackbox_update_grades($feedbackbox);
                } else {
                    feedbackbox_grade_item_update($feedbackbox);
                }
            }
            $rs->close();
        }
    }
}

/**
 * Create grade item for given feedbackbox
 *
 * @param object $feedbackbox object with extra cmidnumber
 * @param mixed optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function feedbackbox_grade_item_update($feedbackbox, $grades = null) {
    global $CFG;
    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir . '/gradelib.php');
    }

    if (!isset($feedbackbox->courseid)) {
        $feedbackbox->courseid = $feedbackbox->course;
    }

    if ($feedbackbox->cmidnumber != '') {
        $params = ['itemname' => $feedbackbox->name, 'idnumber' => $feedbackbox->cmidnumber];
    } else {
        $params = ['itemname' => $feedbackbox->name];
    }

    if ($feedbackbox->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = $feedbackbox->grade;
        $params['grademin'] = 0;

    } else if ($feedbackbox->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid'] = -$feedbackbox->grade;

    } else if ($feedbackbox->grade == 0) { // No Grade..be sure to delete the grade item if it exists.
        $grades = null;
        $params = ['deleted' => 1];

    } else {
        $params = null; // Allow text comments only.
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/feedbackbox',
        $feedbackbox->courseid,
        'mod',
        'feedbackbox',
        $feedbackbox->id,
        0,
        $grades,
        $params);
}

/**
 * This function returns if a scale is being used by one feedbackbox
 * it it has support for grading and scales. Commented code should be
 * modified if necessary. See forum, glossary or journal modules
 * as reference.
 *
 * @param $feedbackboxid int
 * @param $scaleid       int
 * @return boolean True if the scale is used by any feedbackbox
 *
 * Function parameters are unused, but API requires them. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_scale_used($feedbackboxid, $scaleid) {
    return false;
}

/**
 * Checks if scale is being used by any instance of feedbackbox
 *
 * This is used to find out if scale used anywhere
 *
 * @param $scaleid int
 * @return boolean True if the scale is used by any feedbackbox
 *
 * Function parameters are unused, but API requires them. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_scale_used_anywhere($scaleid) {
    return false;
}

/**
 * Serves the feedbackbox attachments. Implements needed access control ;-)
 *
 * @param object $course
 * @param object $cm
 * @param object $context
 * @param string $filearea
 * @param array  $args
 * @param bool   $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 *
 * $forcedownload is unused, but API requires it. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $fileareas = ['intro', 'info', 'thankbody', 'question', 'feedbacknotes', 'sectionheading', 'feedback'];
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $componentid = (int) array_shift($args);

    if ($filearea == 'question') {
        if (!$DB->record_exists('feedbackbox_question', ['id' => $componentid])) {
            return false;
        }
    } else if ($filearea == 'sectionheading') {
        if (!$DB->record_exists('feedbackbox_fb_sections', ['id' => $componentid])) {
            return false;
        }
    } else if ($filearea == 'feedback') {
        if (!$DB->record_exists('feedbackbox_feedback', ['id' => $componentid])) {
            return false;
        }
    } else {
        if (!$DB->record_exists('feedbackbox_survey', ['id' => $componentid])) {
            return false;
        }
    }

    if (!$DB->record_exists('feedbackbox', ['id' => $cm->instance])) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_feedbackbox/$filearea/$componentid/$relativepath";
    if (!($file = $fs->get_file_by_hash(sha1($fullpath))) || $file->is_directory()) {
        return false;
    }

    // Finally send the file.
    send_stored_file($file, 0, 0, true); // Download MUST be forced - security!
}

// Any other feedbackbox functions go here.  Each of them must have a name that
// starts with feedbackbox_.

function feedbackbox_get_view_actions() {
    return ['view', 'view all'];
}

function feedbackbox_get_post_actions() {
    return ['submit', 'update'];
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the feedbackbox.
 *
 * @param $mform the course reset form that is being built.
 */
function feedbackbox_reset_course_form_definition($mform) {
    $mform->addElement('header', 'feedbackboxheader', get_string('modulenameplural', 'feedbackbox'));
    $mform->addElement('advcheckbox',
        'reset_feedbackbox',
        get_string('removeallfeedbackboxattempts', 'feedbackbox'));
}

/**
 * Course reset form defaults.
 *
 * @return array the defaults.
 *
 * Function parameters are unused, but API requires them. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_reset_course_form_defaults($course) {
    return ['reset_feedbackbox' => 1];
}

/**
 * Actual implementation of the reset course functionality, delete all the
 * feedbackbox responses for course $data->courseid.
 *
 * @param object $data the data submitted from the reset course.
 * @return array status array
 */
function feedbackbox_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

    $componentstr = get_string('modulenameplural', 'feedbackbox');
    $status = [];

    if (!empty($data->reset_feedbackbox)) {
        $surveys = feedbackbox_get_survey_list($data->courseid, '');

        // Delete responses.
        foreach ($surveys as $survey) {
            // Get all responses for this feedbackbox.
            $sql = "SELECT qr.id, qr.feedbackboxid, qr.submitted, qr.userid, q.sid
                 FROM {feedbackbox} q
                 INNER JOIN {feedbackbox_response} qr ON q.id = qr.feedbackboxid
                 WHERE q.sid = ?
                 ORDER BY qr.id";
            $resps = $DB->get_records_sql($sql, [$survey->id]);
            if (!empty($resps)) {
                $feedbackbox = $DB->get_record("feedbackbox", ["sid" => $survey->id, "course" => $survey->courseid]);
                $feedbackbox->course = $DB->get_record("course", ["id" => $feedbackbox->course]);
                foreach ($resps as $response) {
                    feedbackbox_delete_response($response, $feedbackbox);
                }
            }
            // Remove this feedbackbox's grades (and feedback) from gradebook (if any).
            $select = "itemmodule = 'feedbackbox' AND iteminstance = " . $survey->qid;
            $fields = 'id';
            if ($itemid = $DB->get_record_select('grade_items', $select, null, $fields)) {
                $itemid = $itemid->id;
                $DB->delete_records_select('grade_grades', 'itemid = ' . $itemid);

            }
        }
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('deletedallresp', 'feedbackbox'),
            'error' => false];

        $status[] = [
            'component' => $componentstr,
            'item' => get_string('gradesdeleted', 'feedbackbox'),
            'error' => false];
    }
    return $status;
}

/**
 * Obtains the automatic completion state for this feedbackbox based on the condition
 * in feedbackbox settings.
 *
 * @param object $course Course
 * @param object $cm     Course-module
 * @param int    $userid User ID
 * @param bool   $type   Type of comparison (or/and; can be used as return value if no conditions)
 * @return bool True if completed, false if not, $type if conditions not set.
 *
 * $course is unused, but API requires it. Suppress PHPMD warning.
 *
 * @SuppressWarnings(PHPMD.UnusedFormalParameter)
 */
function feedbackbox_get_completion_state($course, $cm, $userid, $type) {
    global $DB;

    // Get feedbackbox details.
    $feedbackbox = $DB->get_record('feedbackbox', ['id' => $cm->instance], '*', MUST_EXIST);

    // If completion option is enabled, evaluate it and return true/false.
    if ($feedbackbox->completionsubmit) {
        $params = ['userid' => $userid, 'feedbackboxid' => $feedbackbox->id, 'complete' => 'y'];
        return $DB->record_exists('feedbackbox_response', $params);
    } else {
        // Completion option is not enabled so just return $type.
        return $type;
    }
}

/**
 * This function receives a calendar event and returns the action associated with it, or null if there is none.
 *
 * This is used by block_myoverview in order to display the event appropriately. If null is returned then the event
 * is not displayed on the block.
 *
 * @param calendar_event                $event
 * @param \core_calendar\action_factory $factory
 * @return \core_calendar\local\event\entities\action_interface|null
 */
function mod_feedbackbox_core_calendar_provide_event_action(calendar_event $event,
    \core_calendar\action_factory $factory) {
    $cm = get_fast_modinfo($event->courseid)->instances['feedbackbox'][$event->instance];

    $completion = new \completion_info($cm->get_course());

    $completiondata = $completion->get_data($cm, false);

    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    return $factory->create_instance(
        get_string('view'),
        new \moodle_url('/mod/feedbackbox/view.php', ['id' => $cm->id]),
        1,
        true
    );
}