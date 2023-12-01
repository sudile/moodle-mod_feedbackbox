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
 * This library replaces the phpESP application with Moodle specific code. It will eventually
 * replace all of the phpESP application, removing the dependency on that.
 *
 * @package    mod_feedbackbox
 * @copyright  2016 Mike Churchward (mike.churchward@poetgroup.org)
 * @author     Mike Churchward
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/calendar/lib.php');

/**
 * Get choice values
 *
 * @param $content
 * @return stdClass
 */
function feedbackbox_choice_values($content) {

    // If we run the content through format_text first, any filters we want to use (e.g. multilanguage) should work.
    // examines the content of a possible answer from radio button, check boxes or rate question
    // returns ->text to be displayed, ->image if present, ->modname name of modality, image ->title.
    $contents = new stdClass();
    $contents->text = '';
    $contents->image = '';
    $contents->modname = '';
    $contents->title = '';
    // Has image.
    if (preg_match('/(<img)\s .*(src="(.[^"]{1,})")/isxmU', $content, $matches)) {
        $contents->image = $matches[0];
        $imageurl = $matches[3];
        // Image has a title or alt text: use one of them.
        if (preg_match('/(title=.)([^"]{1,})/', $content, $matches)
            || preg_match('/(alt=.)([^"]{1,})/', $content, $matches)) {
            $contents->title = $matches[2];
        } else {
            // Image has no title nor alt text: use its filename (without the extension).
            preg_match('/.*\/(.*)\..*$/', $imageurl, $matches);
            $contents->title = $matches[1];
        }
        // Content has text or named modality plus an image.
        if (preg_match('/(.*)(<img.*)/', $content, $matches)) {
            $content = $matches[1];
        } else {
            // Just an image.
            return $contents;
        }
    }

    // Check for score value first (used e.g. by personality test feature).
    $r = preg_match_all('/^(\d{1,2}=)(.*)$/', $content, $matches);
    if ($r) {
        $content = $matches[2][0];
    }

    // Look for named modalities.
    $contents->text = $content;
    // DEV JR from version 2.5, a double colon :: must be used here instead of the equal sign.
    if ($pos = strpos($content, '::')) {
        $contents->text = substr($content, $pos + 2);
        $contents->modname = substr($content, 0, $pos);
    }
    return $contents;
}

/**
 * Get all the feedbackbox responses for a user
 *
 * @param      $feedbackboxid
 * @param      $userid
 * @param bool $complete
 * @return array
 * @throws dml_exception
 */
function feedbackbox_get_user_responses($feedbackboxid, $userid, $complete = true) {
    global $DB;
    $andcomplete = '';
    if ($complete) {
        $andcomplete = 'AND complete = \'y\'';
    }
    return $DB->get_records_sql('SELECT * FROM {feedbackbox_response} WHERE feedbackboxid = ? AND userid = ? ' .
        $andcomplete . ' ORDER BY submitted ASC ',
        [$feedbackboxid, $userid]);
}

/**
 * get the capabilities for the feedbackbox
 *
 * @param int $cmid
 * @return object the available capabilities from current user
 * @throws coding_exception
 * @throws moodle_exception
 */
function feedbackbox_load_capabilities($cmid) {
    static $cb;

    if (isset($cb)) {
        return $cb;
    }
    $context = feedbackbox_get_context($cmid);
    $cb = new stdClass();
    $cb->view = has_capability('mod/feedbackbox:view', $context);
    $cb->submit = has_capability('mod/feedbackbox:submit', $context);
    $cb->viewsingleresponse = has_capability('mod/feedbackbox:viewsingleresponse', $context);
    $cb->submissionnotification = has_capability('mod/feedbackbox:submissionnotification', $context);
    $cb->manage = has_capability('mod/feedbackbox:manage', $context);
    $cb->viewhiddenactivities = has_capability('moodle/course:viewhiddenactivities', $context, null, false);
    return $cb;
}

/**
 * returns the context-id related to the given coursemodule-id
 *
 * @param int $cmid the coursemodule-id
 * @return context $context
 * @throws moodle_exception
 */
function feedbackbox_get_context($cmid) {
    static $context;

    if (isset($context)) {
        return $context;
    }

    if (!$context = context_module::instance($cmid)) {
        throw new \moodle_exception('badcontext', 'error');
    }
    return $context;
}

/**
 * This function *really* shouldn't be needed, but since sometimes we can end up with
 * orphaned surveys, this will clean them up.
 *
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function feedbackbox_cleanup() {
    global $DB;

    // Find surveys that don't have feedbackboxs associated with them.
    $sql = 'SELECT qs.* FROM {feedbackbox_survey} qs LEFT JOIN {feedbackbox} q ON q.sid = qs.id WHERE q.sid IS NULL';
    if ($surveys = $DB->get_records_sql($sql)) {
        foreach ($surveys as $survey) {
            feedbackbox_delete_survey($survey->id, 0);
        }
    }
}

/**
 * Delete Survey
 *
 * @param int $sid
 * @param int $feedbackboxid
 * @return bool
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function feedbackbox_delete_survey($sid, $feedbackboxid) {
    global $DB;
    $status = true;
    // Delete all survey attempts and responses.
    if ($responses = $DB->get_records('feedbackbox_response', ['feedbackboxid' => $feedbackboxid], 'id')) {
        foreach ($responses as $response) {
            $status = $status && feedbackbox_delete_response($response);
        }
    }

    // There really shouldn't be any more, but just to make sure...
    $DB->delete_records('feedbackbox_response', ['feedbackboxid' => $feedbackboxid]);
    // Delete all question data for the survey.
    if ($questions = $DB->get_records('feedbackbox_question', ['surveyid' => $sid], 'id')) {
        foreach ($questions as $question) {
            $DB->delete_records('feedbackbox_quest_choice', ['question_id' => $question->id]);
        }
        $status = $status && $DB->delete_records('feedbackbox_question', ['surveyid' => $sid]);
    }
    $status = $status && $DB->delete_records('feedbackbox_survey', ['id' => $sid]);
    return $status;
}

/**
 * Delete response from feedbackbox
 *
 * @param stdClass $response
 * @param stdClass $feedbackbox
 * @return bool
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function feedbackbox_delete_response($response, $feedbackbox = null) {
    global $DB;
    $cm = '';
    $rid = $response->id;
    // The feedbackbox_delete_survey function does not send the feedbackbox array.
    if ($feedbackbox != null) {
        $cm = get_coursemodule_from_instance('feedbackbox', $feedbackbox->id, $feedbackbox->course->id);
    }

    // Delete all of the response data for a response.
    $DB->delete_records('feedbackbox_resp_multiple', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_resp_single', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_response_text', ['response_id' => $rid]);

    $status = $DB->delete_records('feedbackbox_response', ['id' => $rid]);

    if ($status && $cm) {
        // Update completion state if necessary.
        $completion = new completion_info($feedbackbox->course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && $feedbackbox->completionsubmit) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $response->userid);
        }
    }

    return $status;
}

/**
 * Delete all responses for question with id
 *
 * @param $qid
 * @return bool
 * @throws dml_exception
 * @noinspection PhpUnused maybe used later on
 */
function feedbackbox_delete_responses($qid) {
    global $DB;
    // Delete all of the response data for a question.
    $DB->delete_records('feedbackbox_resp_multiple', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_resp_single', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_response_text', ['question_id' => $qid]);
    return true;
}

/**
 * Get list of all surveys to iterate through
 *
 * @param int $courseid
 * @return array|bool
 * @throws dml_exception
 */
function feedbackbox_get_survey_list($courseid = 0) {
    global $DB;
    if ($courseid == 0) {
        if (is_siteadmin()) {
            $sql = 'SELECT id,name,courseid,status {feedbackbox_survey} ORDER BY name ';
            $params = null;
        } else {
            return false;
        }
    } else {
        // Current get_survey_list is called from function feedbackbox_reset_userdata so we need to get a
        // complete list of all feedbackboxs in current course to reset them.
        $sql = 'SELECT s.id,s.name,s.courseid,s.s.status,q.id as qid,q.name as qname ' .
            'FROM {feedbackbox} q ' .
            'INNER JOIN {feedbackbox_survey} s ON s.id = q.sid AND s.courseid = q.course ' .
            'WHERE s.courseid = ? ' .
            'ORDER BY name ';
        $params = [$courseid];
    }
    return $DB->get_records_sql($sql, $params);
}

/**
 * This creates new events given as opendate and closedate by $feedbackbox.
 * added by JR 16 march 2009 based on lesson_process_post_save script
 *
 * @param object $feedbackbox
 * @return void
 * @throws coding_exception
 * @throws dml_exception
 * @noinspection PhpUnused auto include
 */
function feedbackbox_set_events($feedbackbox) {
    // Adding the feedbackbox to the eventtable.
    global $DB;
    if ($events = $DB->get_records('event', ['modulename' => 'feedbackbox', 'instance' => $feedbackbox->id])) {
        foreach ($events as $event) {
            $event = calendar_event::load($event);
            $event->delete();
        }
    }

    // The open-event.
    $event = new stdClass;
    $event->description = $feedbackbox->name;
    $event->courseid = $feedbackbox->course;
    $event->groupid = 0;
    $event->userid = 0;
    $event->modulename = 'feedbackbox';
    $event->instance = $feedbackbox->id;
    $event->eventtype = 'open';
    $event->type = CALENDAR_EVENT_TYPE_ACTION;
    $event->timestart = $feedbackbox->opendate;
    // Moodle type Name does not exist
    /** @noinspection PhpParamsInspection */
    $event->visible = instance_is_visible('feedbackbox', $feedbackbox);
    $event->timeduration = ($feedbackbox->closedate - $feedbackbox->opendate);

    if ($feedbackbox->closedate && $feedbackbox->opendate) {
        // Single event for the whole feedbackbox.
        $event->name = $feedbackbox->name;
        $event->timesort = $feedbackbox->opendate;
        calendar_event::create($event);
    } else {
        // Separate start and end events.
        $event->timeduration = 0;
        if ($feedbackbox->opendate) {
            $event->name = $feedbackbox->name . ' (' . get_string('feedbackboxopens', 'feedbackbox') . ')';
            $event->timesort = $feedbackbox->opendate;
            calendar_event::create($event);
            unset($event->id); // So we can use the same object for the close event.
        }
        if ($feedbackbox->closedate) {
            $event->name = $feedbackbox->name . ' (' . get_string('feedbackboxcloses', 'feedbackbox') . ')';
            $event->timestart = $feedbackbox->closedate;
            $event->timesort = $feedbackbox->closedate;
            $event->eventtype = 'close';
            calendar_event::create($event);
        }
    }
}

/**
 * Called by HTML editor in showrespondents and Essay question. Based on question/essay/renderer.
 * Pending general solution to using the HTML editor outside of moodleforms in Moodle pages.
 *
 * @param $context
 * @return array
 */
function feedbackbox_get_editor_options($context) {
    return [
        'subdirs' => 0,
        'maxbytes' => 0,
        'maxfiles' => -1,
        'context' => $context,
        'noclean' => 0,
        'trusttext' => 0
    ];
}

/**
 * Get the standard page contructs and check for validity.
 *
 * @param int $id The coursemodule id.
 * @param int $a  The module instance id.
 * @return array An array with the $cm, $course, and $feedbackbox records in that order.
 * @throws coding_exception
 * @throws dml_exception
 * @throws moodle_exception
 */
function feedbackbox_get_standard_page_items($id = null, $a = null) {
    global $DB;

    if ($id) {
        if (!$cm = get_coursemodule_from_id('feedbackbox', $id)) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }

        if (!$course = $DB->get_record('course', ['id' => $cm->course])) {
            throw new \moodle_exception('coursemisconf', 'error');
        }

        if (!$feedbackbox = $DB->get_record('feedbackbox', ['id' => $cm->instance])) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }

    } else {
        if (!$feedbackbox = $DB->get_record('feedbackbox', ['id' => $a])) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
        if (!$course = $DB->get_record('course', ['id' => $feedbackbox->course])) {
            throw new \moodle_exception('coursemisconf', 'error');
        }
        if (!$cm = get_coursemodule_from_instance('feedbackbox', $feedbackbox->id, $course->id)) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
    }

    return ([$cm, $course, $feedbackbox]);
}