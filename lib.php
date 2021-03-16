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

use core_calendar\action_factory;
use core_calendar\local\event\entities\action_interface;
use core_completion\api;
use mod_feedbackbox\feedbackbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Get FeedbackBox Supports
 *
 * @param $feature
 * @return bool|null
 */
function feedbackbox_supports($feature) {
    switch ($feature) {
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_GRADE_OUTCOMES:
        case FEATURE_GROUPINGS:
        case FEATURE_GROUPS:
            return false;
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_COMPLETION_HAS_RULES:
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        default:
            return null;
    }
}

/**
 * Dynamic course module description to display the time spans
 *
 * @param cm_info $cm
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 * @noinspection PhpUnused
 */
function feedbackbox_cm_info_dynamic(cm_info $cm) {
    global $DB, $COURSE; // Required by include once.
    if ($COURSE->id == $cm->course) { // Avoid performance issues.
        $cousem = get_coursemodule_from_instance('feedbackbox', $cm->instance, $cm->course);
        $feedbackbox = new feedbackbox(0,
            $DB->get_record('feedbackbox', ['id' => $cousem->instance]),
            $cousem->course,
            $cousem);
        $zone = $feedbackbox->get_current_turnus();
        if ($zone !== false) {
            $calc = round(($zone->to - time()) / (60 * 60 * 24));
            if ($calc == 0) {
                $zone->daysleft = get_string('cminfo_until_time',
                    'mod_feedbackbox',
                    (object) ['date' => date('d.m.Y', $zone->to), 'time' => date('H:i', $zone->to)]);
                $cm->set_content(get_string('cminfodescription_time', 'mod_feedbackbox', $zone));
            } else {
                $zone->daysleft = $calc . ' ';
                if ($calc > 1) {
                    // Plural.
                    $zone->daysleft .= get_string('cminfo_days', 'mod_feedbackbox');
                } else {
                    // Singular.
                    $zone->daysleft .= get_string('cminfo_day', 'mod_feedbackbox');
                }
                $cm->set_content(get_string('cminfodescription', 'mod_feedbackbox', $zone));
            }
        } else {
            if ($feedbackbox->opendate > time()) {
                $cm->set_content(get_string('noturnusfound_open',
                    'mod_feedbackbox',
                    date('d.m.Y', $feedbackbox->opendate)));
            } else if ($feedbackbox->closedate < time()) {
                $cm->set_content(get_string('noturnusfound_close',
                    'mod_feedbackbox'));
            } else {
                $cm->set_content(get_string('noturnusfound', 'mod_feedbackbox'));
            }
        }
    }
}


/**
 * @return array all other caps used in module
 * @noinspection PhpUnused
 */
function feedbackbox_get_extra_capabilities() {
    return ['moodle/site:accessallgroups'];
}

/**
 * @param $feedbackboxid
 * @return mixed
 * @throws dml_exception
 * @noinspection PhpUnused
 */
function feedbackbox_get_instance($feedbackboxid) {
    global $DB;
    return $DB->get_record('feedbackbox', ['id' => $feedbackboxid]);
}


/**
 * Create a new private survey with a new feedbackbox instance
 *
 * @param $courseid
 * @param $turnus
 * @param $start
 * @param $end
 * @param $intro
 * @param $notifystudents
 * @return bool|int
 * @throws dml_exception
 * @throws coding_exception
 */
function feedbackbox_add_template_object($courseid, $turnus, $start, $end, $intro, $notifystudents) {
    global $DB;

    // Create survey.
    $survey = new stdClass();
    $survey->name = 'Feedback Box';
    $survey->courseid = $courseid;
    $survey->status = 0;
    $survey->title = 'Feedback Box';
    $surveyid = $DB->insert_record('feedbackbox_survey', $survey);

    // Create Feedbackbox.
    $feedbackbox = new stdClass();
    $feedbackbox->course = $courseid;
    $feedbackbox->name = 'Feedback Box';
    $feedbackbox->intro = $intro;
    $feedbackbox->introformat = 1;
    $feedbackbox->respondenttype = 'anonymous';
    $feedbackbox->notifications = 0;
    $feedbackbox->opendate = $start;
    $feedbackbox->closedate = $end;
    $feedbackbox->resume = 0;
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
        '<p><h4>Schritt 1/3:<br/><b>Hey, wie kommst du im Kurs zurecht?</b></h4></p>',
        'y',
        'n',
        ['Hy&shy;per&shy;ga&shy;lak&shy;tisch gut!', 'Läuft wie ge&shy;schmiert.', 'Es ist okay.', 'Hilfe - Ich komme gar nicht klar!']);
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

/**
 *
 * @param int $surveyid
 * @param string $name
 * @param int $type
 * @param int $length
 * @param int $precise
 * @param int $position
 * @param string $content
 * @param string $required
 * @param string $deleted
 * @param array $choices
 * @throws coding_exception
 * @throws dml_exception
 */
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
    global $DB;
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

/**
 * @param $feedbackbox
 * @return bool|int
 * @throws coding_exception
 * @throws dml_exception
 * @noinspection PhpUnused
 */
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

/**
 * @param $feedbackbox
 * @return bool
 * @throws dml_exception
 * @noinspection PhpUnused
 */
function feedbackbox_update_instance($feedbackbox) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');
    $feedbackboxorg = $DB->get_record('feedbackbox', ['id' => $feedbackbox->instance]);
    // Check the realm and set it to the survey if its set.
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
    api::update_completion_date_event($feedbackbox->coursemodule,
        'feedbackbox',
        $feedbackboxorg->id,
        $completiontimeexpected);
    $feedbackboxorg->intro = $feedbackbox->intro;
    return $DB->update_record("feedbackbox", $feedbackboxorg);
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * and any data that depends on it.
 *
 * @param $id
 * @return bool
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 * @noinspection PhpUnused
 */
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

/**
 * Return a small object with summary information about what a
 * user has done with a given particular instance of this module
 * Used for user activity reports.
 * $return->time = the time they did it
 * $return->info = a short text description.
 * $course and $mod are unused, but API requires them. Suppress PHPMD warning.
 *
 * @param $course
 * @param $user
 * @param $mod
 * @param $feedbackbox
 * @return stdClass
 * @throws coding_exception
 * @throws dml_exception
 * @noinspection PhpUnused
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

/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 * $course and $mod are unused, but API requires them. Suppress PHPMD warning.
 *
 * @param $course
 * @param $user
 * @param $mod
 * @param $feedbackbox
 * @return bool
 * @throws coding_exception
 * @throws dml_exception
 * @noinspection PhpUnused
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

/**
 * Serves the feedbackbox attachments. Implements needed access control ;-)
 *
 * @param object $course
 * @param object $cm
 * @param context $context
 * @param string $filearea
 * @param array  $args
 * @param bool   $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 *
 * $forcedownload is unused, but API requires it. Suppress PHPMD warning.
 *
 * @throws coding_exception
 * @throws dml_exception
 * @noinspection PhpUnused
 */
function feedbackbox_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload) {
    global $DB, $USER;
    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    $fileareas = ['intro', 'info', 'question', 'sectionheading', 'csv'];
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $componentid = (int) array_shift($args);

    if ($filearea == 'question') {
        if (!$DB->record_exists('feedbackbox_question', ['id' => $componentid])) {
            return false;
        }
    } else if ($filearea == 'csv' && has_capability('mod/feedbackbox:manage', $context, $USER)){
        $course = $DB->get_record('course', ['id' => $cm->course]);
        $feedbackbox = $DB->get_record('feedbackbox', ['id' => $cm->instance]);
        $feedbackbox = new feedbackbox(0, $feedbackbox, $course, $cm);
        $csv = $feedbackbox->generate_csv();
        send_content_uncached($csv, $feedbackbox->name . '-' . $feedbackbox->id . '.csv');
        // generate csv
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


/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the feedbackbox.
 *
 * @param $mform MoodleQuickForm the course reset form that is being built.
 * @throws coding_exception
 */
function feedbackbox_reset_course_form_definition($mform) {
    $mform->addElement('header', 'feedbackboxheader', get_string('modulenameplural', 'feedbackbox'));
    $mform->addElement('advcheckbox',
        'reset_feedbackbox',
        get_string('removeallfeedbackboxattempts', 'feedbackbox'));
}


/**
 * Course reset form defaults.
 * Function parameters are unused, but API requires them. Suppress PHPMD warning.
 *
 * @param $course
 * @return array
 * @noinspection PhpUnused
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
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function feedbackbox_reset_userdata($data) {
    global $CFG, $DB;
    require_once($CFG->libdir . '/questionlib.php');
    require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

    $componentstr = get_string('modulenameplural', 'feedbackbox');
    $status = [];

    if (!empty($data->reset_feedbackbox)) {
        $surveys = feedbackbox_get_survey_list($data->courseid);

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
        }
        $status[] = [
            'component' => $componentstr,
            'item' => get_string('deletedallresp', 'feedbackbox'),
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
 * @throws dml_exception
 * @noinspection PhpUnused
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
 * @param calendar_event $event
 * @param action_factory $factory
 * @return action_interface|null
 * @throws coding_exception
 * @throws moodle_exception
 */
function mod_feedbackbox_core_calendar_provide_event_action(calendar_event $event,
    action_factory $factory) {
    $cm = get_fast_modinfo($event->courseid)->instances['feedbackbox'][$event->instance];

    $completion = new completion_info($cm->get_course());

    $completiondata = $completion->get_data($cm, false);

    if ($completiondata->completionstate != COMPLETION_INCOMPLETE) {
        return null;
    }

    return $factory->create_instance(
        get_string('view'),
        new moodle_url('/mod/feedbackbox/view.php', ['id' => $cm->id]),
        1,
        true
    );
}