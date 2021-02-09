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

 * @package    mod_feedbackbox
 * @copyright  2016 Mike Churchward (mike.churchward@poetgroup.org)
 * @author     Mike Churchward
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
use mod_feedbackbox\feedbackbox;



defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/calendar/lib.php');
// Constants.

define('FEEDBACKBOXUNLIMITED', 0);
define('FEEDBACKBOXONCE', 1);
define('FEEDBACKBOXDAILY', 2);
define('FEEDBACKBOXWEEKLY', 3);
define('FEEDBACKBOXMONTHLY', 4);

define('FEEDBACKBOX_STUDENTVIEWRESPONSES_NEVER', 0);
define('FEEDBACKBOX_STUDENTVIEWRESPONSES_WHENANSWERED', 1);
define('FEEDBACKBOX_STUDENTVIEWRESPONSES_WHENCLOSED', 2);
define('FEEDBACKBOX_STUDENTVIEWRESPONSES_ALWAYS', 3);

define('FEEDBACKBOX_MAX_EVENT_LENGTH', 5 * 24 * 60 * 60);   // 5 days maximum.

define('FEEDBACKBOX_DEFAULT_PAGE_COUNT', 20);

global $feedbackboxtypes;
$feedbackboxtypes = [FEEDBACKBOXUNLIMITED => get_string('qtypeunlimited', 'feedbackbox'),
    FEEDBACKBOXONCE => get_string('qtypeonce', 'feedbackbox'),
    FEEDBACKBOXDAILY => get_string('qtypedaily', 'feedbackbox'),
    FEEDBACKBOXWEEKLY => get_string('qtypeweekly', 'feedbackbox'),
    FEEDBACKBOXMONTHLY => get_string('qtypemonthly', 'feedbackbox')];

global $feedbackboxrespondents;
$feedbackboxrespondents = ['fullname' => get_string('respondenttypefullname', 'feedbackbox'),
    'anonymous' => get_string('respondenttypeanonymous', 'feedbackbox')];

global $feedbackboxrealms;
$feedbackboxrealms = ['private' => get_string('private', 'feedbackbox'),
    'public' => get_string('public', 'feedbackbox'),
    'template' => get_string('template', 'feedbackbox')];

global $feedbackboxresponseviewers;
$feedbackboxresponseviewers = [
    FEEDBACKBOX_STUDENTVIEWRESPONSES_WHENANSWERED => get_string('responseviewstudentswhenanswered', 'feedbackbox'),
    FEEDBACKBOX_STUDENTVIEWRESPONSES_WHENCLOSED => get_string('responseviewstudentswhenclosed', 'feedbackbox'),
    FEEDBACKBOX_STUDENTVIEWRESPONSES_ALWAYS => get_string('responseviewstudentsalways', 'feedbackbox'),
    FEEDBACKBOX_STUDENTVIEWRESPONSES_NEVER => get_string('responseviewstudentsnever', 'feedbackbox')];

global $autonumbering;
$autonumbering = [0 => get_string('autonumberno', 'feedbackbox'),
    1 => get_string('autonumberquestions', 'feedbackbox'),
    2 => get_string('autonumberpages', 'feedbackbox'),
    3 => get_string('autonumberpagesandquestions', 'feedbackbox')];

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
            preg_match("/.*\/(.*)\..*$/", $imageurl, $matches);
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
    $r = preg_match_all("/^(\d{1,2}=)(.*)$/", $content, $matches);
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
 */
function feedbackbox_get_user_responses($feedbackboxid, $userid, $complete = true) {
    global $DB;
    $andcomplete = '';
    if ($complete) {
        $andcomplete = " AND complete = 'y' ";
    }
    return $DB->get_records_sql("SELECT *
        FROM {feedbackbox_response}
        WHERE feedbackboxid = ?
        AND userid = ?
        " . $andcomplete . "
        ORDER BY submitted ASC ",
        [$feedbackboxid, $userid]);
}

/**
 * get the capabilities for the feedbackbox
 *
 * @param int $cmid
 * @return object the available capabilities from current user
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
    $cb->downloadresponses = has_capability('mod/feedbackbox:downloadresponses', $context);
    $cb->deleteresponses = has_capability('mod/feedbackbox:deleteresponses', $context);
    $cb->manage = has_capability('mod/feedbackbox:manage', $context);
    $cb->editquestions = has_capability('mod/feedbackbox:editquestions', $context);
    $cb->createtemplates = has_capability('mod/feedbackbox:createtemplates', $context);
    $cb->createpublic = has_capability('mod/feedbackbox:createpublic', $context);
    $cb->readownresponses = has_capability('mod/feedbackbox:readownresponses', $context);
    $cb->readallresponses = has_capability('mod/feedbackbox:readallresponses', $context);
    $cb->readallresponseanytime = has_capability('mod/feedbackbox:readallresponseanytime', $context);
    $cb->printblank = has_capability('mod/feedbackbox:printblank', $context);
    $cb->preview = has_capability('mod/feedbackbox:preview', $context);

    $cb->viewhiddenactivities = has_capability('moodle/course:viewhiddenactivities', $context, null, false);

    return $cb;
}

/**
 * returns the context-id related to the given coursemodule-id
 *
 * @param int $cmid the coursemodule-id
 * @return object $context
 */
function feedbackbox_get_context($cmid) {
    static $context;

    if (isset($context)) {
        return $context;
    }

    if (!$context = context_module::instance($cmid)) {
        print_error('badcontext');
    }
    return $context;
}

// This function *really* shouldn't be needed, but since sometimes we can end up with
// orphaned surveys, this will clean them up.
function feedbackbox_cleanup() {
    global $DB;

    // Find surveys that don't have feedbackboxs associated with them.
    $sql = 'SELECT qs.* FROM {feedbackbox_survey} qs ' .
        'LEFT JOIN {feedbackbox} q ON q.sid = qs.id ' .
        'WHERE q.sid IS NULL';

    if ($surveys = $DB->get_records_sql($sql)) {
        foreach ($surveys as $survey) {
            feedbackbox_delete_survey($survey->id, 0);
        }
    }
    // Find deleted questions and remove them from database (with their associated choices, etc.).
    return true;
}

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
            feedbackbox_delete_dependencies($question->id);
        }
        $status = $status && $DB->delete_records('feedbackbox_question', ['surveyid' => $sid]);
        // Just to make sure.
        $status = $status && $DB->delete_records('feedbackbox_dependency', ['surveyid' => $sid]);
    }

    // Delete all feedback sections and feedback messages for the survey.
    if ($fbsections = $DB->get_records('feedbackbox_fb_sections', ['surveyid' => $sid], 'id')) {
        foreach ($fbsections as $fbsection) {
            $DB->delete_records('feedbackbox_feedback', ['sectionid' => $fbsection->id]);
        }
        $status = $status && $DB->delete_records('feedbackbox_fb_sections', ['surveyid' => $sid]);
    }

    $status = $status && $DB->delete_records('feedbackbox_survey', ['id' => $sid]);

    return $status;
}

function feedbackbox_delete_response($response, $feedbackbox = '') {
    global $DB;
    $status = true;
    $cm = '';
    $rid = $response->id;
    // The feedbackbox_delete_survey function does not send the feedbackbox array.
    if ($feedbackbox != '') {
        $cm = get_coursemodule_from_instance("feedbackbox", $feedbackbox->id, $feedbackbox->course->id);
    }

    // Delete all of the response data for a response.
    $DB->delete_records('feedbackbox_response_bool', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_response_date', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_resp_multiple', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_response_other', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_response_rank', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_resp_single', ['response_id' => $rid]);
    $DB->delete_records('feedbackbox_response_text', ['response_id' => $rid]);

    $status = $status && $DB->delete_records('feedbackbox_response', ['id' => $rid]);

    if ($status && $cm) {
        // Update completion state if necessary.
        $completion = new completion_info($feedbackbox->course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && $feedbackbox->completionsubmit) {
            $completion->update_state($cm, COMPLETION_INCOMPLETE, $response->userid);
        }
    }

    return $status;
}

function feedbackbox_delete_responses($qid) {
    global $DB;

    // Delete all of the response data for a question.
    $DB->delete_records('feedbackbox_response_bool', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_response_date', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_resp_multiple', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_response_other', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_response_rank', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_resp_single', ['question_id' => $qid]);
    $DB->delete_records('feedbackbox_response_text', ['question_id' => $qid]);

    return true;
}

function feedbackbox_delete_dependencies($qid) {
    global $DB;

    // Delete all dependencies for this question.
    $DB->delete_records('feedbackbox_dependency', ['questionid' => $qid]);
    $DB->delete_records('feedbackbox_dependency', ['dependquestionid' => $qid]);

    return true;
}

function feedbackbox_get_survey_list($courseid = 0, $type = '') {
    global $DB;

    if ($courseid == 0) {
        if (isadmin()) {
            $sql = "SELECT id,name,courseid,realm,status " .
                "{feedbackbox_survey} " .
                "ORDER BY realm,name ";
            $params = null;
        } else {
            return false;
        }
    } else {
        if ($type == 'public') {
            $sql = "SELECT s.id,s.name,s.courseid,s.realm,s.status,s.title,q.id as qid,q.name as qname " .
                "FROM {feedbackbox} q " .
                "INNER JOIN {feedbackbox_survey} s ON s.id = q.sid AND s.courseid = q.course " .
                "WHERE realm = ? " .
                "ORDER BY realm,name ";
            $params = [$type];
        } else if ($type == 'template') {
            $sql = "SELECT s.id,s.name,s.courseid,s.realm,s.status,s.title,q.id as qid,q.name as qname " .
                "FROM {feedbackbox} q " .
                "INNER JOIN {feedbackbox_survey} s ON s.id = q.sid AND s.courseid = q.course " .
                "WHERE (realm = ?) " .
                "ORDER BY realm,name ";
            $params = [$type];
        } else if ($type == 'private') {
            $sql = "SELECT s.id,s.name,s.courseid,s.realm,s.status,q.id as qid,q.name as qname " .
                "FROM {feedbackbox} q " .
                "INNER JOIN {feedbackbox_survey} s ON s.id = q.sid " .
                "WHERE s.courseid = ? and realm = ? " .
                "ORDER BY realm,name ";
            $params = [$courseid, $type];

        } else {
            // Current get_survey_list is called from function feedbackbox_reset_userdata so we need to get a
            // complete list of all feedbackboxs in current course to reset them.
            $sql = "SELECT s.id,s.name,s.courseid,s.realm,s.status,q.id as qid,q.name as qname " .
                "FROM {feedbackbox} q " .
                "INNER JOIN {feedbackbox_survey} s ON s.id = q.sid AND s.courseid = q.course " .
                "WHERE s.courseid = ? " .
                "ORDER BY realm,name ";
            $params = [$courseid];
        }
    }
    return $DB->get_records_sql($sql, $params);
}

function feedbackbox_get_survey_select($courseid = 0, $type = '') {
    global $OUTPUT, $DB;

    $surveylist = [];

    if ($surveys = feedbackbox_get_survey_list($courseid, $type)) {
        $strpreview = get_string('preview_feedbackbox', 'feedbackbox');
        foreach ($surveys as $survey) {
            $originalcourse = $DB->get_record('course', ['id' => $survey->courseid]);
            if (!$originalcourse) {
                // This should not happen, but we found a case where a public survey
                // still existed in a course that had been deleted, and so this
                // code lead to a notice, and a broken link. Since that is useless
                // we just skip surveys like this.
                continue;
            }

            // Prevent creating a copy of a public feedbackbox IN THE SAME COURSE as the original.
            if (($type == 'public') && ($survey->courseid == $courseid)) {
                continue;
            } else {
                $args = "sid={$survey->id}&popup=1";
                if (!empty($survey->qid)) {
                    $args .= "&qid={$survey->qid}";
                }
                $link = new moodle_url("/mod/feedbackbox/preview.php?{$args}");
                $action = new popup_action('click', $link);
                $label = $OUTPUT->action_link($link,
                    $survey->qname . ' [' . $originalcourse->fullname . ']',
                    $action,
                    ['title' => $strpreview]);
                $surveylist[$type . '-' . $survey->id] = $label;
            }
        }
    }
    return $surveylist;
}

function feedbackbox_get_type($id) {
    switch ($id) {
        case 1:
            return get_string('yesno', 'feedbackbox');
        case 2:
            return get_string('textbox', 'feedbackbox');
        case 3:
            return get_string('essaybox', 'feedbackbox');
        case 4:
            return get_string('radiobuttons', 'feedbackbox');
        case 5:
            return get_string('checkboxes', 'feedbackbox');
        case 6:
            return get_string('dropdown', 'feedbackbox');
        case 8:
            return get_string('ratescale', 'feedbackbox');
        case 9:
            return get_string('date', 'feedbackbox');
        case 10:
            return get_string('numeric', 'feedbackbox');
        case 100:
            return get_string('sectiontext', 'feedbackbox');
        case 99:
            return get_string('sectionbreak', 'feedbackbox');
        default:
            return $id;
    }
}

/**
 * This creates new events given as opendate and closedate by $feedbackbox.
 *
 * @param object $feedbackbox
 * @return void
 */
/* added by JR 16 march 2009 based on lesson_process_post_save script */

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
    $event->visible = instance_is_visible('feedbackbox', $feedbackbox);
    $event->timeduration = ($feedbackbox->closedate - $feedbackbox->opendate);

    if ($feedbackbox->closedate && $feedbackbox->opendate && ($event->timeduration <= FEEDBACKBOX_MAX_EVENT_LENGTH)) {
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
 * Get users who have not completed the feedbackbox
 *
 * @param object $cm
 * @param int    $group single groupid
 * @param string $sort
 * @param int    $startpage
 * @param int    $pagecount
 * @return object the userrecords
 * @global object
 * @uses CONTEXT_MODULE
 */
function feedbackbox_get_incomplete_users($cm,
    $sid,
    $group = false,
    $sort = '',
    $startpage = false,
    $pagecount = false) {

    global $DB;

    $context = context_module::instance($cm->id);

    // First get all users who can complete this feedbackbox.
    $cap = 'mod/feedbackbox:submit';
    $fields = 'u.id, u.username';
    if (!$allusers = get_users_by_capability($context,
        $cap,
        $fields,
        $sort,
        '',
        '',
        $group,
        '',
        true)) {
        return false;
    }
    $allusers = array_keys($allusers);

    // Nnow get all completed feedbackboxs.
    $params = ['feedbackboxid' => $cm->instance, 'complete' => 'y'];
    $sql = "SELECT userid FROM {feedbackbox_response} " .
        "WHERE feedbackboxid = :feedbackboxid AND complete = :complete " .
        "GROUP BY userid ";

    if (!$completedusers = $DB->get_records_sql($sql, $params)) {
        return $allusers;
    }
    $completedusers = array_keys($completedusers);
    // Now strike all completedusers from allusers.
    $allusers = array_diff($allusers, $completedusers);
    // For paging I use array_slice().
    if (($startpage !== false) && ($pagecount !== false)) {
        $allusers = array_slice($allusers, $startpage, $pagecount);
    }
    return $allusers;
}

/**
 * Called by HTML editor in showrespondents and Essay question. Based on question/essay/renderer.
 * Pending general solution to using the HTML editor outside of moodleforms in Moodle pages.
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

// Get the parent of a child question.
// TODO - This needs to be refactored or removed.
function feedbackbox_get_parent($question) {
    global $DB;
    $qid = $question->id;
    $parent = [];
    $dependquestion = $DB->get_record('feedbackbox_question',
        ['id' => $question->dependquestionid],
        'id, position, name, type_id');
    if (is_object($dependquestion)) {
        $qdependchoice = '';
        switch ($dependquestion->type_id) {
            case QUESRADIO:
            case QUESDROP:
            case QUESCHECK:
                $dependchoice = $DB->get_record('feedbackbox_quest_choice',
                    ['id' => $question->dependchoiceid],
                    'id,content');
                $qdependchoice = $dependchoice->id;
                $dependchoice = $dependchoice->content;

                $contents = feedbackbox_choice_values($dependchoice);
                if ($contents->modname) {
                    $dependchoice = $contents->modname;
                }
                break;
            case QUESYESNO:
                switch ($question->dependchoiceid) {
                    case 0:
                        $dependchoice = get_string('yes');
                        $qdependchoice = 'y';
                        break;
                    case 1:
                        $dependchoice = get_string('no');
                        $qdependchoice = 'n';
                        break;
                }
                break;
        }
        // Qdependquestion, parenttype and qdependchoice fields to be used in preview mode.
        $parent [$qid]['qdependquestion'] = 'q' . $dependquestion->id;
        $parent [$qid]['qdependchoice'] = $qdependchoice;
        $parent [$qid]['parenttype'] = $dependquestion->type_id;
        // Other fields to be used in Questions edit mode.
        $parent [$qid]['position'] = $question->position;
        $parent [$qid]['name'] = $question->name;
        $parent [$qid]['content'] = $question->content;
        $parent [$qid]['parentposition'] = $dependquestion->position;
        $parent [$qid]['parent'] = format_string($dependquestion->name) . '->' . format_string($dependchoice);
    }
    return $parent;
}

/**
 * Get parent position of all child questions in current feedbackbox.
 * Use the parent with the largest position value.
 *
 * @param array $questions
 * @return array An array with Child-ID->Parentposition.
 */
function feedbackbox_get_parent_positions($questions) {
    $parentpositions = [];
    foreach ($questions as $question) {
        foreach ($question->dependencies as $dependency) {
            $dependquestion = $dependency->dependquestionid;
            if (isset($dependquestion) && $dependquestion != 0) {
                $childid = $question->id;
                $parentpos = $questions[$dependquestion]->position;

                if (!isset($parentpositions[$childid])) {
                    $parentpositions[$childid] = $parentpos;
                }
                if (isset ($parentpositions[$childid]) && $parentpos > $parentpositions[$childid]) {
                    $parentpositions[$childid] = $parentpos;
                }
            }
        }
    }
    return $parentpositions;
}

/**
 * Get child position of all parent questions in current feedbackbox.
 * Use the child with the smallest position value.
 *
 * @param array $questions
 * @return array An array with Parent-ID->Childposition.
 */
function feedbackbox_get_child_positions($questions) {
    $childpositions = [];
    foreach ($questions as $question) {
        foreach ($question->dependencies as $dependency) {
            $dependquestion = $dependency->dependquestionid;
            if (isset($dependquestion) && $dependquestion != 0) {
                $parentid = $questions[$dependquestion]->id; // Equals $dependquestion?.
                $childpos = $question->position;

                if (!isset($childpositions[$parentid])) {
                    $childpositions[$parentid] = $childpos;
                }

                if (isset ($childpositions[$parentid]) && $childpos < $childpositions[$parentid]) {
                    $childpositions[$parentid] = $childpos;
                }
            }
        }
    }
    return $childpositions;
}

// Check that the needed page breaks are present to separate child questions.
function feedbackbox_check_page_breaks($feedbackbox) {
    global $DB;
    $msg = '';
    // Store the new page breaks ids.
    $newpbids = [];
    $delpb = 0;
    $sid = $feedbackbox->survey->id;
    $questions = $DB->get_records('feedbackbox_question', ['surveyid' => $sid, 'deleted' => 'n'], 'id');
    $positions = [];
    foreach ($questions as $key => $qu) {
        $positions[$qu->position]['question_id'] = $key;
        $positions[$qu->position]['type_id'] = $qu->type_id;
        $positions[$qu->position]['qname'] = $qu->name;
        $positions[$qu->position]['qpos'] = $qu->position;

        $dependencies = $DB->get_records('feedbackbox_dependency',
            ['questionid' => $key, 'surveyid' => $sid],
            'id ASC',
            'id, dependquestionid, dependchoiceid, dependlogic');
        $positions[$qu->position]['dependencies'] = $dependencies;
    }
    $count = count($positions);

    for ($i = $count; $i > 0; $i--) {
        $qu = $positions[$i];
        $questionnb = $i;
        if ($qu['type_id'] == QUESPAGEBREAK) {
            $questionnb--;
            // If more than one consecutive page breaks, remove extra one(s).
            $prevqu = null;
            $prevtypeid = null;
            if ($i > 1) {
                $prevqu = $positions[$i - 1];
                $prevtypeid = $prevqu['type_id'];
            }
            // If $i == $count then remove that extra page break in last position.
            if ($prevtypeid == QUESPAGEBREAK || $i == $count || $qu['qpos'] == 1) {
                $qid = $qu['question_id'];
                $delpb++;
                $msg .= get_string("checkbreaksremoved", "feedbackbox", $delpb) . '<br />';
                // Need to reload questions.
                $questions = $DB->get_records('feedbackbox_question', ['surveyid' => $sid, 'deleted' => 'n'], 'id');
                $DB->set_field('feedbackbox_question', 'deleted', 'y', ['id' => $qid, 'surveyid' => $sid]);
                $select = 'surveyid = ' . $sid . ' AND deleted = \'n\' AND position > ' .
                    $questions[$qid]->position;
                if ($records = $DB->get_records_select('feedbackbox_question', $select, null, 'position ASC')) {
                    foreach ($records as $record) {
                        $DB->set_field('feedbackbox_question',
                            'position',
                            $record->position - 1,
                            ['id' => $record->id]);
                    }
                }
            }
        }
        // Add pagebreak between question child and not dependent question that follows.
        if ($qu['type_id'] != QUESPAGEBREAK) {
            $j = $i - 1;
            if ($j != 0) {
                $prevtypeid = $positions[$j]['type_id'];
                $prevdependencies = $positions[$j]['dependencies'];

                $outerdependencies = count($qu['dependencies']) >= count($prevdependencies) ?
                    $qu['dependencies'] : $prevdependencies;
                $innerdependencies = count($qu['dependencies']) < count($prevdependencies) ?
                    $qu['dependencies'] : $prevdependencies;

                foreach ($outerdependencies as $okey => $outerdependency) {
                    foreach ($innerdependencies as $ikey => $innerdependency) {
                        if ($outerdependency->dependquestionid === $innerdependency->dependquestionid &&
                            $outerdependency->dependchoiceid === $innerdependency->dependchoiceid &&
                            $outerdependency->dependlogic === $innerdependency->dependlogic) {
                            unset($outerdependencies[$okey]);
                            unset($innerdependencies[$ikey]);
                        }
                    }
                }

                $diffdependencies = count($outerdependencies) + count($innerdependencies);

                if (($prevtypeid != QUESPAGEBREAK && $diffdependencies != 0)
                    || (!isset($qu['dependencies']) && isset($prevdependencies))) {
                    $sql = 'SELECT MAX(position) as maxpos FROM {feedbackbox_question} ' .
                        'WHERE surveyid = ' . $feedbackbox->survey->id . ' AND deleted = \'n\'';
                    if ($record = $DB->get_record_sql($sql)) {
                        $pos = $record->maxpos + 1;
                    } else {
                        $pos = 1;
                    }
                    $question = new stdClass();
                    $question->surveyid = $feedbackbox->survey->id;
                    $question->type_id = QUESPAGEBREAK;
                    $question->position = $pos;
                    $question->content = 'break';

                    if (!($newqid = $DB->insert_record('feedbackbox_question', $question))) {
                        return (false);
                    }
                    $newpbids[] = $newqid;
                    $movetopos = $i;
                    $feedbackbox = new feedbackbox($feedbackbox->id, null, $course, $cm);
                    $feedbackbox->move_question($newqid, $movetopos);
                }
            }
        }
    }
    if (empty($newpbids) && !$msg) {
        $msg = get_string('checkbreaksok', 'feedbackbox');
    } else if ($newpbids) {
        $msg .= get_string('checkbreaksadded', 'feedbackbox') . '&nbsp;';
        $newpbids = array_reverse($newpbids);
        $feedbackbox = new feedbackbox($feedbackbox->id, null, $course, $cm);
        foreach ($newpbids as $newpbid) {
            $msg .= $feedbackbox->questions[$newpbid]->position . '&nbsp;';
        }
    }
    return ($msg);
}

/**
 * Code snippet used to set up the questionform.
 */
function feedbackbox_prep_for_questionform($feedbackbox, $qid, $qtype) {
    $context = context_module::instance($feedbackbox->cm->id);
    if ($qid != 0) {
        $question = clone($feedbackbox->questions[$qid]);
        $question->qid = $question->id;
        $question->sid = $feedbackbox->survey->id;
        $question->id = $feedbackbox->cm->id;
        $draftideditor = file_get_submitted_draft_itemid('question');
        $content = file_prepare_draft_area($draftideditor,
            $context->id,
            'mod_feedbackbox',
            'question',
            $qid,
            ['subdirs' => true],
            $question->content);
        $question->content = ['text' => $content, 'format' => FORMAT_HTML, 'itemid' => $draftideditor];

        if (isset($question->dependencies)) {
            foreach ($question->dependencies as $dependencies) {
                if ($dependencies->dependandor === "and") {
                    $question->dependquestions_and[] = $dependencies->dependquestionid . ',' . $dependencies->dependchoiceid;
                    $question->dependlogic_and[] = $dependencies->dependlogic;
                } else if ($dependencies->dependandor === "or") {
                    $question->dependquestions_or[] = $dependencies->dependquestionid . ',' . $dependencies->dependchoiceid;
                    $question->dependlogic_or[] = $dependencies->dependlogic;
                }
            }
        }
    } else {
        $question = \mod_feedbackbox\question\question::question_builder($qtype);
        $question->sid = $feedbackbox->survey->id;
        $question->id = $feedbackbox->cm->id;
        $question->type_id = $qtype;
        $question->type = '';
        $draftideditor = file_get_submitted_draft_itemid('question');
        $content = file_prepare_draft_area($draftideditor,
            $context->id,
            'mod_feedbackbox',
            'question',
            null,
            ['subdirs' => true],
            '');
        $question->content = ['text' => $content, 'format' => FORMAT_HTML, 'itemid' => $draftideditor];
    }
    return $question;
}

/**
 * Get the standard page contructs and check for validity.
 *
 * @param int $id The coursemodule id.
 * @param int $a  The module instance id.
 * @return array An array with the $cm, $course, and $feedbackbox records in that order.
 */
function feedbackbox_get_standard_page_items($id = null, $a = null) {
    global $DB;

    if ($id) {
        if (!$cm = get_coursemodule_from_id('feedbackbox', $id)) {
            print_error('invalidcoursemodule');
        }

        if (!$course = $DB->get_record("course", ["id" => $cm->course])) {
            print_error('coursemisconf');
        }

        if (!$feedbackbox = $DB->get_record("feedbackbox", ["id" => $cm->instance])) {
            print_error('invalidcoursemodule');
        }

    } else {
        if (!$feedbackbox = $DB->get_record("feedbackbox", ["id" => $a])) {
            print_error('invalidcoursemodule');
        }
        if (!$course = $DB->get_record("course", ["id" => $feedbackbox->course])) {
            print_error('coursemisconf');
        }
        if (!$cm = get_coursemodule_from_instance("feedbackbox", $feedbackbox->id, $course->id)) {
            print_error('invalidcoursemodule');
        }
    }

    return ([$cm, $course, $feedbackbox]);
}