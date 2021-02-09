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
 * @package    mod_feedbackbox
 * @copyright  2016 Mike Churchward (mike.churchward@poetgroup.org)
 * @author     Mike Churchward
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_feedbackbox;
use coding_exception;
use completion_info;
use context_course;
use context_module;
use core\message\message;
use core\output\notification;
use core_user;
use dml_exception;
use mod_feedbackbox\question\choice\choice;
use mod_feedbackbox\question\question;
use mod_feedbackbox\responsetype\multiple;
use mod_feedbackbox\responsetype\response\response;
use mod_feedbackbox\responsetype\single;
use mod_feedbackbox\responsetype\text;
use moodle_exception;
use moodle_recordset;
use moodle_url;
use pix_icon;
use plugin_renderer_base;
use popup_action;
use renderable;
use renderer_base;
use stdClass;
use templatable;

defined('MOODLE_INTERNAL') || die();

/** @noinspection PhpIncludeInspection */
require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

class feedbackbox {

    // Class Properties.

    /**
     * @var question[] $quesitons
     */
    public $questions = [];

    /**
     * The survey record.
     *
     * @var object $survey
     */
    // Todo var $survey; TODO.

    /**
     * @var $renderer renderer_base Contains the page renderer when loaded, or false if not.
     */
    public $renderer = false;

    /**
     * @var $page templatable|renderable Contains the renderable, templatable page when loaded, or false if not.
     */
    public $page = false;

    /**
     * @var stdClass
     */
    public $cm = null;

    /**
     * @var stdClass
     */
    public $course = null;

    /**
     * @var context_module
     */
    public $context = null;

    public $capabilities = null;

    public $responses = [];

    /**
     * @var stdClass
     */
    public $survey = null;

    public $questionsbysec = [];

    public $sid = null;

    public $id = null;
    public $name = null;
    public $fullname = null;
    public $rid = null;
    public $completionsubmit = null;
    public $respondenttype = null;
    public $grade = null;
    public $opendate = null;
    public $closedate = null;
    public $qtype = null;
    public $turnus = null;
    /* @codingStandardsIgnoreLine */
    public $resp_view = null;
    public $navigate = null;
    public $resume = null;
    public $progressbar = null;
    public $usehtmleditor = null;
    public $notifications = null;

    /**
     * feedbackbox constructor.
     *
     * @param      $id
     * @param      $feedbackbox
     * @param      $course
     * @param      $cm
     * @param bool $addquestions
     * @throws dml_exception
     */
    public function __construct($id, $feedbackbox, &$course, &$cm, $addquestions = true) {
        global $DB;

        if ($id) {
            $feedbackbox = $DB->get_record('feedbackbox', ['id' => $id]);
        }

        if (is_object($feedbackbox)) {
            $properties = get_object_vars($feedbackbox);
            foreach ($properties as $property => $value) {
                $this->$property = $value;
            }
        }

        if (!empty($this->sid)) {
            $this->add_survey($this->sid);
        }

        $this->course = $course;
        $this->cm = $cm;
        // When we are creating a brand new feedbackbox, we will not yet have a context.
        if (!empty($cm) && !empty($this->id)) {
            $this->context = context_module::instance($cm->id);
        } else {
            $this->context = null;
        }

        if ($addquestions && !empty($this->sid)) {
            $this->add_questions($this->sid);
        }

        // Load the capabilities for this user and feedbackbox, if not creating a new one.
        if (!empty($this->cm->id)) {
            $this->capabilities = feedbackbox_load_capabilities($this->cm->id);
        }

        // Don't automatically add responses.
        $this->responses = [];
    }

    /**
     * Adding a survey record to the object.
     *
     * @param int  $sid
     * @param null $survey
     * @throws dml_exception
     */
    public function add_survey($sid = 0, $survey = null) {
        global $DB;

        if ($sid) {
            $this->survey = $DB->get_record('feedbackbox_survey', ['id' => $sid]);
        } else if (is_object($survey)) {
            $this->survey = clone($survey);
        }
    }

    /**
     * Adding questions to the object.
     *
     * @param bool $sid
     * @throws dml_exception
     */
    public function add_questions($sid = false) {
        global $DB;

        if ($sid === false) {
            $sid = $this->sid;
        }

        if (!isset($this->questions)) {
            $this->questions = [];
            $this->questionsbysec = [];
        }

        $select = 'surveyid = ? AND deleted = ?';
        $params = [$sid, 'n'];
        if ($records = $DB->get_records_select('feedbackbox_question', $select, $params, 'position')) {
            $sec = 1;
            $isbreak = false;
            foreach ($records as $record) {

                $this->questions[$record->id] = question::question_builder($record->type_id,
                    $record,
                    $this->context);

                if ($record->type_id != QUESPAGEBREAK) {
                    $this->questionsbysec[$sec][] = $record->id;
                    $isbreak = false;
                } else {
                    // Sanity check: no section break allowed as first position, no 2 consecutive section breaks.
                    if ($record->position != 1 && $isbreak == false) {
                        $sec++;
                        $isbreak = true;
                    }
                }
            }
        }
    }

    /**
     * Load all response information for this user.
     *
     * @param int $userid
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    public function add_user_responses($userid = null) {
        global $USER;

        // Empty feedbackboxs cannot have responses.
        if (empty($this->id)) {
            return;
        }

        if ($userid === null) {
            $userid = $USER->id;
        }

        $responses = $this->get_responses($userid);
        foreach ($responses as $response) {
            $this->responses[$response->id] = response::create_from_data($response);
        }
    }

    /**
     * Load the specified response information.
     *
     * @param $responseid
     * @throws dml_exception
     */
    public function add_response($responseid) {
        global $DB;

        // Empty feedbackboxs cannot have responses.
        if (empty($this->id)) {
            return;
        }

        $response = $DB->get_record('feedbackbox_response', ['id' => $responseid]);
        $this->responses[$response->id] = response::create_from_data($response);
    }

    /**
     * Load the response information from a submitted web form.
     *
     * @param $formdata
     * @throws dml_exception
     */
    public function add_response_from_formdata($formdata) {
        $this->responses[0] = response::response_from_webform($formdata,
            $this->questions);
    }

    /**
     * Return a response object from a submitted mobile app form.
     *
     * @param     $appdata
     * @param int $sec
     * @return bool|response
     * @throws dml_exception
     */
    public function build_response_from_appdata($appdata, $sec = 0) {
        $questions = [];
        if ($sec == 0) {
            $questions = $this->questions;
        } else {
            foreach ($this->questionsbysec[$sec] as $questionid) {
                $questions[$questionid] = $this->questions[$questionid];
            }
        }
        return response::response_from_appdata($this->id,
            0,
            $appdata,
            $questions);
    }

    /**
     * Add the renderer to the feedbackbox object.
     *
     * @param plugin_renderer_base $renderer The module renderer, extended from core renderer.
     */
    public function add_renderer($renderer) {
        $this->renderer = $renderer;
    }

    /**
     * Add the templatable page to the feedbackbox object.
     *
     * @param renderable, \templatable $page The page to rendere, implementing core classes.
     */
    public function add_page($page) {
        $this->page = $page;
    }

    /**
     * Return true if questions should be automatically numbered.
     *
     * @return bool
     */
    public function questions_autonumbered() {
        // Value of 1 if questions should be numbered. Value of 3 if both questions and pages should be numbered.
        return (!empty($this->autonum) && (($this->autonum == 1) || ($this->autonum == 3)));
    }

    /**
     * Return true if pages should be automatically numbered.
     *
     * @return bool
     * @noinspection PhpUnused
     */
    public function pages_autonumbered() {
        // Value of 2 if pages should be numbered. Value of 3 if both questions and pages should be numbered.
        return (!empty($this->autonum) && (($this->autonum == 2) || ($this->autonum == 3)));
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function view() {
        global $USER, $PAGE;

        $PAGE->set_title(format_string($this->name));
        $PAGE->set_heading(format_string($this->course->fullname));

        $message = $this->user_access_messages($USER->id, true);
        if ($message !== false) {
            $this->page->add_to_page('notifications', $message);
        } else {
            // Handle the main feedbackbox completion page.
            $quser = $USER->id;
            $msg = $this->print_survey($USER->id, $quser);

            $viewform = data_submitted();
            if ($viewform && confirm_sesskey() && isset($viewform->submit) && isset($viewform->submittype) &&
                ($viewform->submittype == "Submit Survey") && empty($msg)) {
                if (!empty($viewform->rid)) {
                    $viewform->rid = (int) $viewform->rid;
                }
                if (!empty($viewform->sec)) {
                    $viewform->sec = (int) $viewform->sec;
                }
                $this->response_delete($viewform->rid, $viewform->sec);
                $this->rid = $this->response_insert($viewform, $quser);
                $this->response_commit($this->rid);

                $this->update_grades($quser);

                // Update completion state.
                $completion = new completion_info($this->course);
                if ($completion->is_enabled($this->cm) && $this->completionsubmit) {
                    $completion->update_state($this->cm, COMPLETION_COMPLETE);
                }
                $this->submission_notify($this->rid);
                $this->response_goto_thankyou();
            }
        }
    }

    /**
     * @param $rid
     * @param $sec
     * @param $quser
     * @return bool|int|null
     * @throws coding_exception
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    public function delete_insert_response($rid, $sec, $quser) {
        $this->response_delete($rid, $sec);
        $this->rid = $this->response_insert((object) ['sec' => $sec, 'rid' => $rid], $quser);
        return $this->rid;
    }

    /**
     * @param $rid
     * @param $quser
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function commit_submission_response($rid, $quser) {
        $this->response_commit($rid);
        $this->update_grades($quser);

        // Update completion state.
        $completion = new completion_info($this->course);
        if ($completion->is_enabled($this->cm) && $this->completionsubmit) {
            $completion->update_state($this->cm, COMPLETION_COMPLETE);
        }
    }

    /**
     * Update the grade for this feedbackbox and user.
     *
     * @param $userid
     */
    private function update_grades($userid) {
        if ($this->grade != 0) {
            $feedbackbox = new stdClass();
            $feedbackbox->id = $this->id;
            $feedbackbox->name = $this->name;
            $feedbackbox->grade = $this->grade;
            $feedbackbox->cmidnumber = $this->cm->idnumber;
            $feedbackbox->courseid = $this->course->id;
            feedbackbox_update_grades($feedbackbox, $userid);
        }
    }

    /**
     * Function to view an entire responses data.
     *
     * @param        $rid
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function view_response($rid) {
        $outputtarget = 'html';
        $this->print_survey_start('', 1, $rid, false, $outputtarget);

        $i = 0;
        $this->add_response($rid);
        $pdf = ($outputtarget == 'pdf') ? true : false;
        foreach ($this->questions as $question) {
            if ($question->type_id < QUESPAGEBREAK) {
                $i++;
            }
            if ($question->type_id != QUESPAGEBREAK) {
                $this->page->add_to_page('responses',
                    $this->renderer->response_output($question, $this->responses[$rid], $i, $pdf));
            }
        }
    }

    /**
     * Function to view all loaded responses.
     *
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpUnused
     */
    public function view_all_responses() {
        $this->print_survey_start('', 1);

        // If a student's responses have been deleted by teacher while student was viewing the report,
        // then responses may have become empty, hence this test is necessary.

        if (!empty($this->responses)) {
            $this->page->add_to_page('responses',
                $this->renderer->all_response_output($this->responses, $this->questions));
        } else {
            $this->page->add_to_page('responses',
                $this->renderer->all_response_output(get_string('noresponses', 'feedbackbox')));
        }

        $this->print_survey_end(1, 1);
    }

    // Access Methods.
    public function is_active() {
        return (!empty($this->survey));
    }

    public function is_open() {
        return ($this->opendate > 0) ? ($this->opendate < time()) : true;
    }

    public function is_closed() {
        return ($this->closedate > 0) ? ($this->closedate < time()) : false;
    }

    /**
     * @param $userid
     * @return bool
     * @throws dml_exception
     */
    public function user_can_take($userid) {
        if (!$this->is_active() || !$this->user_is_eligible()) {
            return false;
        } else if ($this->qtype == FEEDBACKBOXUNLIMITED) {
            return true;
        } else if ($userid > 0) {
            return $this->user_time_for_new_attempt($userid);
        } else {
            return false;
        }
    }

    public function user_is_eligible() {
        return ($this->capabilities->view && $this->capabilities->submit);
    }

    /**
     * Return any message if the user cannot complete this feedbackbox, explaining why.
     *
     * @param int  $userid
     * @param bool $asnotification Return as a rendered notification.
     * @return bool|string
     * @throws coding_exception
     * @throws dml_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    public function user_access_messages($userid = 0, $asnotification = false) {
        global $USER;

        if ($userid == 0) {
            $userid = $USER->id;
        }
        $message = false;

        if (!$this->is_active()) {
            if ($this->capabilities->manage) {
                $msg = 'removenotinuse';
            } else {
                $msg = 'notavail';
            }
            $message = get_string($msg, 'feedbackbox');

        } else if ($this->survey->realm == 'template') {
            $message = get_string('templatenotviewable', 'feedbackbox');

        } else if (!$this->is_open()) {
            $message = get_string('notopen', 'feedbackbox', userdate($this->opendate));

        } else if ($this->is_closed()) {
            $message = get_string('closed', 'feedbackbox', userdate($this->closedate));

        } else if (!$this->user_is_eligible()) {
            $message = get_string('noteligible', 'feedbackbox');

        } else {
            if (!$this->user_can_take($userid)) {
                $data = $this->get_turnus_zones();
                if ($this->turnus == 1) {
                    $entry = end($data);
                    if ($entry->from < time() && $entry->to > time()) {
                        $msgstring = ' ' . get_string('oncefilled', 'feedbackbox');
                    }
                } else {
                    foreach ($data as $entry) {
                        if ($entry->from < time() && $entry->to > time()) {
                            $msgstring = ' ' . get_string('betweenfilled',
                                    'feedbackbox',
                                    date('d.m.Y', $entry->from) . ' - ' . date('d.m.Y', $entry->to));
                            break;
                        }
                    }
                }
                if (isset($msgstring)) {
                    $message = get_string("alreadyfilled", "feedbackbox", $msgstring);
                }

            }
        }

        if (($message !== false) && $asnotification) {
            $message = $this->renderer->notification($message, notification::NOTIFY_ERROR);
        }

        return $message;
    }

    /**
     * @param $userid
     * @return bool
     * @throws dml_exception
     */
    public function user_has_saved_response($userid) {
        global $DB;
        return $DB->record_exists('feedbackbox_response',
            ['feedbackboxid' => $this->id, 'userid' => $userid, 'complete' => 'n']);
    }

    /**
     * @param $userid
     * @return bool
     * @throws dml_exception
     */
    public function user_time_for_new_attempt($userid) {
        global $DB;
        $params = ['feedbackboxid' => $this->id, 'userid' => $userid, 'complete' => 'y'];
        $data = $this->get_turnus_zones();
        if (!($attempts = $DB->get_records('feedbackbox_response', $params, 'submitted ASC'))) {
            return true;
        }
        $attempt = end($attempts);
        $nextpick = false;
        foreach ($data as $entry) {
            if ($nextpick) {
                return $entry->from < time() && $entry->to > time();
            }
            if ($attempt->submitted < $entry->to && $attempt->submitted > $entry->from) {
                $nextpick = true; // Select the next timezone to be the next applicable slot for an attempt.
            }
        }
        return false; // If there is no next time slot for an attempt then return false.
    }

    public function is_survey_owner() {
        return (!empty($this->survey->courseid) && ($this->course->id == $this->survey->courseid));
    }

    /**
     * True if the user can view all of the responses to this feedbackbox any time, and there are valid responses.
     *
     * @param bool $grouplogic
     * @param bool $respslogic
     * @return bool
     */
    public function can_view_all_responses_anytime($grouplogic = true, $respslogic = true) {
        // Can view if you are a valid group user, this is the owning course, and there are responses, and you have no
        // response view restrictions.
        return $grouplogic && $respslogic && $this->is_survey_owner() && $this->capabilities->readallresponseanytime;
    }

    /**
     * True if the user can view all of the responses to this feedbackbox any time, and there are valid responses.
     *
     * @param null $usernumresp
     * @param bool $grouplogic
     * @param bool $respslogic
     * @return bool
     */
    public function can_view_all_responses_with_restrictions($usernumresp, $grouplogic = true, $respslogic = true) {
        // Can view if you are a valid group user, this is the owning course, and there are responses, and you can view
        // subject to viewing settings..
        return $grouplogic && $respslogic && $this->is_survey_owner() &&
            ($this->capabilities->readallresponses &&
                ($this->resp_view == FEEDBACKBOX_STUDENTVIEWRESPONSES_ALWAYS ||
                    ($this->resp_view == FEEDBACKBOX_STUDENTVIEWRESPONSES_WHENCLOSED && $this->is_closed()) ||
                    ($this->resp_view == FEEDBACKBOX_STUDENTVIEWRESPONSES_WHENANSWERED && $usernumresp)));

    }

    /**
     * @param bool $userid
     * @param int  $groupid
     * @return int
     * @throws dml_exception
     */
    public function count_submissions($userid = false, $groupid = 0) {
        global $DB;

        $params = [];
        $groupsql = '';
        $groupcnd = '';
        if ($groupid != 0) {
            $groupsql = 'INNER JOIN {groups_members} gm ON r.userid = gm.userid ';
            $groupcnd = ' AND gm.groupid = :groupid ';
            $params['groupid'] = $groupid;
        }

        // Since submission can be across feedbackboxs in the case of public feedbackboxs, need to check the realm.
        // Public feedbackboxs can have responses to multiple feedbackbox instances.
        if ($this->survey_is_public_master()) {
            $sql = 'SELECT COUNT(r.id) ' .
                'FROM {feedbackbox_response} r ' .
                'INNER JOIN {feedbackbox} q ON r.feedbackboxid = q.id ' .
                'INNER JOIN {feedbackbox_survey} s ON q.sid = s.id ' .
                $groupsql .
                'WHERE s.id = :surveyid AND r.complete = :status' . $groupcnd;
            $params['surveyid'] = $this->sid;
            $params['status'] = 'y';
        } else {
            $sql = 'SELECT COUNT(r.id) ' .
                'FROM {feedbackbox_response} r ' .
                $groupsql .
                'WHERE r.feedbackboxid = :feedbackboxid AND r.complete = :status' . $groupcnd;
            $params['feedbackboxid'] = $this->id;
            $params['status'] = 'y';
        }
        if ($userid) {
            $sql .= ' AND r.userid = :userid';
            $params['userid'] = $userid;
        }
        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Get the requested responses for this feedbackbox.
     *
     * @param int|bool $userid
     * @param int      $groupid
     * @return array
     * @throws dml_exception
     */
    public function get_responses($userid = false, $groupid = 0) {
        global $DB;

        $params = [];
        $groupsql = '';
        $groupcnd = '';
        if ($groupid != 0) {
            $groupsql = 'INNER JOIN {groups_members} gm ON r.userid = gm.userid ';
            $groupcnd = ' AND gm.groupid = :groupid ';
            $params['groupid'] = $groupid;
        }

        // Since submission can be across feedbackboxs in the case of public feedbackboxs, need to check the realm.
        // Public feedbackboxs can have responses to multiple feedbackbox instances.
        if ($this->survey_is_public_master()) {
            $sql = 'SELECT r.* ' .
                'FROM {feedbackbox_response} r ' .
                'INNER JOIN {feedbackbox} q ON r.feedbackboxid = q.id ' .
                'INNER JOIN {feedbackbox_survey} s ON q.sid = s.id ' .
                $groupsql .
                'WHERE s.id = :surveyid AND r.complete = :status' . $groupcnd;
            $params['surveyid'] = $this->sid;
            $params['status'] = 'y';
        } else {
            $sql = 'SELECT r.* ' .
                'FROM {feedbackbox_response} r ' .
                $groupsql .
                'WHERE r.feedbackboxid = :feedbackboxid AND r.complete = :status' . $groupcnd;
            $params['feedbackboxid'] = $this->id;
            $params['status'] = 'y';
        }
        if ($userid) {
            $sql .= ' AND r.userid = :userid';
            $params['userid'] = $userid;
        }

        $sql .= ' ORDER BY r.id';
        return $DB->get_records_sql($sql, $params);
    }

    /** @noinspection PhpUnusedPrivateMethodInspection */
    private function has_required($section = 0) {
        if (empty($this->questions)) {
            return false;
        } else if ($section <= 0) {
            foreach ($this->questions as $question) {
                if ($question->required()) {
                    return true;
                }
            }
        } else {
            foreach ($this->questionsbysec[$section] as $questionid) {
                if ($this->questions[$questionid]->required()) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Check if current feedbackbox has dependencies set and any question has dependencies.
     *
     * @return bool Whether dependencies are set or not.
     */
    public function has_dependencies() {
        $hasdependencies = false;
        if (($this->navigate > 0) && isset($this->questions) && !empty($this->questions)) {
            foreach ($this->questions as $question) {
                if ($question->has_dependencies()) {
                    $hasdependencies = true;
                    break;
                }
            }
        }
        return $hasdependencies;
    }

    /**
     * Get all descendants and choices for questions with descendants.
     *
     * @return array
     */
    public function get_dependants_and_choices() {
        $questions = array_reverse($this->questions, true);
        $parents = [];
        foreach ($questions as $question) {
            foreach ($question->dependencies as $dependency) {
                $child = new stdClass();
                $child->choiceid = $dependency->dependchoiceid;
                $child->logic = $dependency->dependlogic;
                $child->andor = $dependency->dependandor;
                $parents[$dependency->dependquestionid][$question->id][] = $child;
            }
        }
        return ($parents);
    }

    /**
     * Load needed parent question information into the dependencies structure for the requested question.
     *
     * @param $question
     * @return bool
     * @throws coding_exception
     */
    public function load_parents($question) {
        foreach ($question->dependencies as $did => $dependency) {
            $dependquestion = $this->questions[$dependency->dependquestionid];
            $qdependchoice = '';
            $dependchoice = null;
            switch ($dependquestion->type_id) {
                case QUESRADIO:
                case QUESDROP:
                case QUESCHECK:
                    $qdependchoice = $dependency->dependchoiceid;
                    $dependchoice = $dependquestion->choices[$dependency->dependchoiceid]->content;

                    $contents = feedbackbox_choice_values($dependchoice);
                    if ($contents->modname) {
                        $dependchoice = $contents->modname;
                    }
                    break;
                case QUESYESNO:
                    switch ($dependency->dependchoiceid) {
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
            $question->dependencies[$did]->qdependquestion = 'q' . $dependquestion->id;
            $question->dependencies[$did]->qdependchoice = $qdependchoice;
            $question->dependencies[$did]->parenttype = $dependquestion->type_id;
            // Other fields to be used in Questions edit mode.
            $question->dependencies[$did]->position = $question->position;
            $question->dependencies[$did]->name = $question->name;
            $question->dependencies[$did]->content = $question->content;
            $question->dependencies[$did]->parentposition = $dependquestion->position;
            $question->dependencies[$did]->parent = $dependquestion->name . '->' . $dependchoice;
        }
        return true;
    }

    /**
     * Determine the next valid page and return it. Return false if no valid next page.
     *
     * @param $secnum
     * @param $rid
     * @return int | bool
     */
    public function next_page($secnum, $rid) {
        $secnum++;
        $numsections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;
        if ($this->has_dependencies()) {
            while (!$this->eligible_questions_on_page($secnum, $rid)) {
                $secnum++;
                // We have reached the end of feedbackbox on a page without any question left.
                if ($secnum > $numsections) {
                    $secnum = false;
                    break;
                }
            }
        }
        return $secnum;
    }

    /**
     * @param $secnum
     * @param $rid
     * @return int | bool
     */
    public function prev_page($secnum, $rid) {
        $secnum--;
        if ($this->has_dependencies()) {
            while (($secnum > 0) && !$this->eligible_questions_on_page($secnum, $rid)) {
                $secnum--;
            }
        }
        if ($secnum === 0) {
            $secnum = false;
        }
        return $secnum;
    }

    /**
     * @param $response
     * @param $userid
     * @return bool|int|string
     * @throws coding_exception
     * @throws dml_exception
     */
    public function next_page_action($response, $userid) {
        $msg = $this->response_check_format($response->sec, $response);
        if (empty($msg)) {
            $response->rid = $this->existing_response_action($response, $userid);
            return $this->next_page($response->sec, $response->rid);
        } else {
            return $msg;
        }
    }

    /**
     * @param $response
     * @param $userid
     * @return bool|int
     * @throws coding_exception
     * @throws dml_exception
     */
    public function previous_page_action($response, $userid) {
        $response->rid = $this->existing_response_action($response, $userid);
        return $this->prev_page($response->sec, $response->rid);
    }

    /**
     * @param $response
     * @param $userid
     * @return bool|int
     * @throws coding_exception
     * @throws dml_exception
     */
    public function existing_response_action($response, $userid) {
        $this->response_delete($response->rid, $response->sec);
        return $this->response_insert($response, $userid);
    }

    /**
     * Are there any eligible questions to be displayed on the specified page/section.
     *
     * @param $secnum int The section number to check.
     * @param $rid    int The current response id.
     * @return bool
     */
    public function eligible_questions_on_page($secnum, $rid) {
        $questionstodisplay = false;

        foreach ($this->questionsbysec[$secnum] as $questionid) {
            if ($this->questions[$questionid]->dependency_fulfilled($rid, $this->questions)) {
                $questionstodisplay = true;
                break;
            }
        }
        return $questionstodisplay;
    }

    /**
     * @param $userid
     * @param $quser
     * @return string|void
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    public function print_survey($userid, $quser) {
        global $SESSION, $CFG;

        if (!($formdata = data_submitted()) || !confirm_sesskey()) {
            $formdata = new stdClass();
        }

        $formdata->rid = $this->get_latest_responseid($quser);
        // If student saved a "resume" feedbackbox OR left a feedbackbox unfinished
        // and there are more pages than one find the page of the last answered question.
        if (($formdata->rid != 0) && (empty($formdata->sec) || intval($formdata->sec) < 1)) {
            $formdata->sec = $this->response_select_max_sec($formdata->rid);
        }
        if (empty($formdata->sec)) {
            $formdata->sec = 1;
        } else {
            $formdata->sec = (intval($formdata->sec) > 0) ? intval($formdata->sec) : 1;
        }

        $numsections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;    // Indexed by section.
        $msg = '';
        $action = $CFG->wwwroot . '/mod/feedbackbox/complete.php?id=' . $this->cm->id;

        // TODO - Need to rework this. Too much crossover with ->view method.

        // Skip logic :: if this is page 1, it cannot be the end page with no questions on it!
        if ($formdata->sec == 1) {
            $SESSION->feedbackbox->end = false;
        }

        if (!empty($formdata->submit)) {
            // Skip logic: we have reached the last page without any questions on it.
            if (isset($SESSION->feedbackbox->end) && $SESSION->feedbackbox->end == true) {
                return;
            }

            $msg = $this->response_check_format($formdata->sec, $formdata);
            if (empty($msg)) {
                return;
            }
            $formdata->rid = $this->existing_response_action($formdata, $userid);
        }

        if (!empty($formdata->resume) && ($this->resume)) {
            $this->response_delete($formdata->rid, $formdata->sec);
            $formdata->rid = $this->response_insert($formdata, $quser, true);
            $this->response_goto_saved();
            return;
        }

        // Save each section 's $formdata somewhere in case user returns to that page when navigating the feedbackbox.
        if (!empty($formdata->next)) {
            $msg = $this->response_check_format($formdata->sec, $formdata);
            if ($msg) {
                $formdata->next = '';
                $formdata->rid = $this->existing_response_action($formdata, $userid);
            } else {
                $nextsec = $this->next_page_action($formdata, $userid);
                if ($nextsec === false) {
                    $SESSION->feedbackbox->end = true; // End of feedbackbox reached on a no questions page.
                    $formdata->sec = $numsections + 1;
                } else {
                    $formdata->sec = $nextsec;
                }
            }
        }

        if (!empty($formdata->prev)) {
            // If skip logic and this is last page reached with no questions,
            // unlock feedbackbox->end to allow navigate back to previous page.
            if (isset($SESSION->feedbackbox->end) && ($SESSION->feedbackbox->end == true)) {
                $SESSION->feedbackbox->end = false;
                $formdata->sec--;
            }

            // Prevent navigation to previous page if wrong format in answered questions).
            $msg = $this->response_check_format($formdata->sec, $formdata, false, true);
            if ($msg) {
                $formdata->prev = '';
                $formdata->rid = $this->existing_response_action($formdata, $userid);
            } else {
                $prevsec = $this->previous_page_action($formdata, $userid);
                if ($prevsec === false) {
                    $formdata->sec = 0;
                } else {
                    $formdata->sec = $prevsec;
                }
            }
        }

        if (!empty($formdata->rid)) {
            $this->add_response($formdata->rid);
        }

        $formdatareferer = !empty($formdata->referer) ? htmlspecialchars($formdata->referer) : '';
        $formdatarid = isset($formdata->rid) ? $formdata->rid : '0';
        $this->page->add_to_page('formstart',
            $this->renderer->complete_formstart($action,
                [
                    'referer' => $formdatareferer,
                    'a' => $this->id,
                    'sid' => $this->survey->id,
                    'rid' => $formdatarid,
                    'sec' => $formdata->sec,
                    'sesskey' => sesskey()
                ]));
        if (isset($this->questions) && $numsections) { // Sanity check.
            $this->survey_render($formdata->sec, $msg, $formdata);
            $controlbuttons = [];
            if ($formdata->sec > 1) {
                $controlbuttons['prev'] = ['type' => 'submit', 'class' => 'btn btn-secondary',
                    'value' => '<< ' . get_string('previouspage', 'feedbackbox')];
            }
            if ($this->resume) {
                $controlbuttons['resume'] = ['type' => 'submit', 'class' => 'btn btn-secondary',
                    'value' => get_string('save', 'feedbackbox')];
            }

            // Add a 'hidden' variable for the mod's 'view.php', and use a language variable for the submit button.

            if ($formdata->sec == $numsections) {
                $controlbuttons['submittype'] = ['type' => 'hidden', 'value' => 'Submit Survey'];
                $controlbuttons['submit'] = ['type' => 'submit', 'class' => 'btn btn-primary',
                    'value' => get_string('submitsurvey', 'feedbackbox')];
            } else {
                $controlbuttons['next'] = ['type' => 'submit', 'class' => 'btn btn-secondary',
                    'value' => get_string('nextpage', 'feedbackbox') . ' >>'];
            }
            $this->page->add_to_page('controlbuttons', $this->renderer->complete_controlbuttons($controlbuttons));
        } else {
            $this->page->add_to_page('controlbuttons',
                $this->renderer->complete_controlbuttons(get_string('noneinuse', 'feedbackbox')));
        }
        $this->page->add_to_page('formend', $this->renderer->complete_formend());

        return $msg;
    }

    /**
     * @param int    $section
     * @param string $message
     * @param        $formdata
     * @return bool|void
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    private function survey_render($section, $message, &$formdata) {

        $this->usehtmleditor = null;

        if (empty($section)) {
            $section = 1;
        }
        $numsections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;
        if ($section > $numsections) {
            $formdata->sec = $numsections;
            $this->page->add_to_page('notifications',
                $this->renderer->notification(get_string('finished', 'feedbackbox'),
                    notification::NOTIFY_WARNING));
            return (false);  // Invalid section.
        }
        // Find out what question number we are on $i New fix for question numbering.
        $i = 0;
        if ($section > 1) {
            for ($j = 2; $j <= $section; $j++) {
                foreach ($this->questionsbysec[$j - 1] as $questionid) {
                    if ($this->questions[$questionid]->type_id < QUESPAGEBREAK) {
                        $i++;
                    }
                }
            }
        }

        $this->print_survey_start($message, $section, '', 1);
        // Only show progress bar on feedbackboxs with more than one page.
        foreach ($this->questionsbysec[$section] as $questionid) {
            if ($this->questions[$questionid]->type_id != QUESSECTIONTEXT) {
                $i++;
            }
            // Need feedbackbox id to get the feedbackbox object in sectiontext (Label) question class.
            $formdata->feedbackbox_id = $this->id;
            if (isset($formdata->rid) && !empty($formdata->rid)) {
                $this->add_response($formdata->rid);
            } else {
                $this->add_response_from_formdata($formdata);
            }
            $this->page->add_to_page('questions',
                $this->renderer->question_output($this->questions[$questionid],
                    (isset($this->responses[$formdata->rid]) ? $this->responses[$formdata->rid] : []),
                    [],
                    $i,
                    $this->usehtmleditor));
        }

        $this->print_survey_end($section, $numsections);

        return;
    }

    /**
     * @param        $message
     * @param        $section
     * @param string $rid
     * @param bool   $blankfeedbackbox
     * @param string $outputtarget
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    private function print_survey_start($message,
        $section,
        $rid = '',
        $blankfeedbackbox = false,
        $outputtarget = 'html') {
        global $CFG, $DB;
        /** @noinspection PhpIncludeInspection */
        require_once($CFG->libdir . '/filelib.php');

        $userid = '';
        $resp = '';
        $groupname = '';
        $currentgroupid = 0;
        $timesubmitted = '';
        // Available group modes (0 = no groups; 1 = separate groups; 2 = visible groups).
        if ($rid) {
            $courseid = $this->course->id;
            if ($resp = $DB->get_record('feedbackbox_response', ['id' => $rid])) {
                if ($this->respondenttype == 'fullname') {
                    $userid = $resp->userid;
                    // Display name of group(s) that student belongs to... if feedbackbox is set to Groups separate or visible.
                    if (groups_get_activity_groupmode($this->cm, $this->course)) {
                        if ($groups = groups_get_all_groups($courseid, $resp->userid)) {
                            if (count($groups) == 1) {
                                $group = current($groups);
                                $currentgroupid = $group->id;
                                $groupname = ' (' . get_string('group') . ': ' . $group->name . ')';
                            } else {
                                $groupname = ' (' . get_string('groups') . ': ';
                                foreach ($groups as $group) {
                                    $groupname .= $group->name . ', ';
                                }
                                $groupname = substr($groupname, 0, strlen($groupname) - 2) . ')';
                            }
                        } else {
                            $groupname = ' (' . get_string('groupnonmembers') . ')';
                        }
                    }
                }
            }
        }
        $ruser = '';
        if ($resp && !$blankfeedbackbox) {
            if ($userid) {
                if ($user = $DB->get_record('user', ['id' => $userid])) {
                    $ruser = fullname($user);
                }
            }
            if ($this->respondenttype == 'anonymous') {
                $ruser = '- ' . get_string('anonymous', 'feedbackbox') . ' -';
            } else {
                // JR DEV comment following line out if you do NOT want time submitted displayed in Anonymous surveys.
                if ($resp->submitted) {
                    $timesubmitted = '&nbsp;' . get_string('submitted',
                            'feedbackbox') . '&nbsp;' . userdate($resp->submitted);
                }
            }
        }
        if ($ruser) {
            $respinfo = '';
            if ($outputtarget == 'html') {
                // Disable the pdf function for now, until it looks a lot better.
                if (false) {
                    $linkname = get_string('downloadpdf', 'mod_feedbackbox');
                    $link = new moodle_url('/mod/feedbackbox/report.php',
                        [
                            'action' => 'vresp',
                            'instance' => $this->id,
                            'target' => 'pdf',
                            'individualresponse' => 1,
                            'rid' => $rid
                        ]);
                    $downpdficon = new pix_icon('b/pdfdown', $linkname, 'mod_feedbackbox');
                    $respinfo .= $this->renderer->action_link($link, null, null, null, $downpdficon);
                }

                $linkname = get_string('print', 'mod_feedbackbox');
                $link = new moodle_url('/mod/feedbackbox/report.php',
                    ['action' => 'vresp', 'instance' => $this->id, 'target' => 'print', 'individualresponse' => 1, 'rid' => $rid]);
                $htmlicon = new pix_icon('t/print', $linkname);
                $options = ['menubar' => true, 'location' => false, 'scrollbars' => true, 'resizable' => true,
                    'height' => 600, 'width' => 800, 'title' => $linkname];
                $name = 'popup';
                $action = new popup_action('click', $link, $name, $options);
                $respinfo .= $this->renderer->action_link($link,
                        null,
                        $action,
                        ['title' => $linkname],
                        $htmlicon) . '&nbsp;';
            }
            $respinfo .= get_string('respondent', 'feedbackbox') . ': <strong>' . $ruser . '</strong>';
            if ($this->survey_is_public()) {
                // For a public feedbackbox, look for the course that used it.
                $coursename = '';
                $sql = 'SELECT q.id, q.course, c.fullname ' .
                    'FROM {feedbackbox_response} qr ' .
                    'INNER JOIN {feedbackbox} q ON qr.feedbackboxid = q.id ' .
                    'INNER JOIN {course} c ON q.course = c.id ' .
                    'WHERE qr.id = ? AND qr.complete = ? ';
                if ($record = $DB->get_record_sql($sql, [$rid, 'y'])) {
                    $coursename = $record->fullname;
                }
                $respinfo .= ' ' . get_string('course') . ': ' . $coursename;
            }
            $respinfo .= $groupname;
            $respinfo .= $timesubmitted;
            $this->page->add_to_page('respondentinfo', $this->renderer->respondent_info($respinfo));
        }

        if ($section == 1) {
            if (!empty($this->survey->title)) {
                $this->survey->title = format_string($this->survey->title);
                $this->page->add_to_page('title', $this->survey->title);
            }
            if (!empty($this->survey->subtitle)) {
                $this->survey->subtitle = format_string($this->survey->subtitle);
                $this->page->add_to_page('subtitle', $this->survey->subtitle);
            }
            if ($this->survey->info) {
                $infotext = file_rewrite_pluginfile_urls($this->survey->info,
                    'pluginfile.php',
                    $this->context->id,
                    'mod_feedbackbox',
                    'info',
                    $this->survey->id);
                $this->page->add_to_page('addinfo', $infotext);
            }
        }

        if ($message) {
            $this->page->add_to_page('message',
                $this->renderer->notification($message, notification::NOTIFY_ERROR));
        }
    }

    private function print_survey_end($section, $numsections) {

    }

    /**
     * @param      $section
     * @param      $formdata
     * @param bool $checkmissing
     * @param bool $checkwrongformat
     * @return string
     * @throws coding_exception
     */
    private function response_check_format($section, $formdata, $checkmissing = true, $checkwrongformat = true) {
        $missing = 0;
        $strmissing = '';     // Missing questions.
        $wrongformat = 0;
        $strwrongformat = ''; // Wrongly formatted questions (Numeric, 5:Check Boxes, Date).
        $i = 1;
        for ($j = 2; $j <= $section; $j++) {
            // ADDED A SIMPLE LOOP FOR MAKING SURE PAGE BREAKS (type 99) AND LABELS (type 100) ARE NOT ALLOWED.
            foreach ($this->questionsbysec[$j - 1] as $questionid) {
                $tid = $this->questions[$questionid]->type_id;
                if ($tid < QUESPAGEBREAK) {
                    $i++;
                }
            }
        }
        $qnum = $i - 1;

        foreach ($this->questionsbysec[$section] as $questionid) {
            $tid = $this->questions[$questionid]->type_id;
            if ($tid != QUESSECTIONTEXT) {
                $qnum++;
            }
            if (!$this->questions[$questionid]->response_complete($formdata)) {
                $missing++;
                $strmissing .= get_string('num', 'feedbackbox') . $qnum . '. ';
            }
            if (!$this->questions[$questionid]->response_valid($formdata)) {
                $wrongformat++;
                $strwrongformat .= get_string('num', 'feedbackbox') . $qnum . '. ';
            }
        }
        $message = '';
        $nonumbering = false;
        // If no questions autonumbering do not display missing question(s) number(s).
        if (!$this->questions_autonumbered()) {
            $nonumbering = true;
        }
        if ($checkmissing && $missing) {
            if ($nonumbering) {
                $strmissing = '';
            }
            if ($missing == 1) {
                $message = get_string('missingquestion', 'feedbackbox') . $strmissing;
            } else {
                $message = get_string('missingquestions', 'feedbackbox') . $strmissing;
            }
            if ($wrongformat) {
                $message .= '<br />';
            }
        }
        if ($checkwrongformat && $wrongformat) {
            if ($nonumbering) {
                $message .= get_string('wronganswers', 'feedbackbox');
            } else {
                if ($wrongformat == 1) {
                    $message .= get_string('wrongformat', 'feedbackbox') . $strwrongformat;
                } else {
                    $message .= get_string('wrongformats', 'feedbackbox') . $strwrongformat;
                }
            }
        }
        return ($message);
    }

    /**
     * @param      $rid
     * @param null $sec
     * @throws coding_exception
     * @throws dml_exception
     */
    private function response_delete($rid, $sec = null) {
        global $DB;

        if (empty($rid)) {
            return;
        }

        if ($sec != null) {
            if ($sec < 1) {
                return;
            }

            // Skip logic.
            $numsections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;
            $sec = min($numsections, $sec);

            /* get question_id's in this section */
            $qids = [];
            foreach ($this->questionsbysec[$sec] as $questionid) {
                $qids[] = $questionid;
            }
            if (empty($qids)) {
                return;
            } else {
                list($qsql, $params) = $DB->get_in_or_equal($qids);
                $qsql = ' AND question_id ' . $qsql;
            }

        } else {
            /* delete all */
            $qsql = '';
            $params = [];
        }

        /* delete values */
        $select = 'response_id = \'' . $rid . '\' ' . $qsql;
        foreach (['response_bool', 'resp_single', 'resp_multiple', 'response_rank', 'response_text',
                     'response_other', 'response_date'] as $tbl) {
            $DB->delete_records_select('feedbackbox_' . $tbl, $select, $params);
        }
    }

    /**
     * @param $rid
     * @return bool
     * @throws dml_exception
     */
    private function response_commit($rid) {
        global $DB;

        $record = new stdClass();
        $record->id = $rid;
        $record->complete = 'y';
        $record->submitted = time();

        if ($this->grade < 0) {
            $record->grade = 1;  // Don't know what to do if its a scale...
        } else {
            $record->grade = $this->grade;
        }
        return $DB->update_record('feedbackbox_response', $record);
    }

    /**
     * Get the latest response id for the user, or verify that the given response id is valid.
     *
     * @param int $userid
     * @return int
     * @throws dml_exception
     */
    public function get_latest_responseid($userid) {
        global $DB;

        // Find latest in progress rid.
        $params = ['feedbackboxid' => $this->id, 'userid' => $userid, 'complete' => 'n'];
        if ($records = $DB->get_records('feedbackbox_response', $params, 'submitted DESC', 'id,feedbackboxid', 0, 1)) {
            $rec = reset($records);
            return $rec->id;
        } else {
            return 0;
        }
    }

    /**
     * @param $rid
     * @return int
     * @throws dml_exception
     */
    private function response_select_max_sec($rid) {
        global $DB;

        $pos = $this->response_select_max_pos($rid);
        $select = 'surveyid = ? AND type_id = ? AND position < ? AND deleted = ?';
        $params = [$this->sid, QUESPAGEBREAK, $pos, 'n'];
        return $DB->count_records_select('feedbackbox_question', $select, $params) + 1;
    }

    /**
     * @param $rid
     * @return int
     * @throws dml_exception
     */
    private function response_select_max_pos($rid) {
        global $DB;

        $max = 0;

        foreach (['response_bool', 'resp_single', 'resp_multiple', 'response_rank', 'response_text',
                     'response_other', 'response_date'] as $tbl) {
            $sql = 'SELECT MAX(q.position) as num FROM {feedbackbox_' . $tbl . '} a, {feedbackbox_question} q ' .
                'WHERE a.response_id = ? AND ' .
                'q.id = a.question_id AND ' .
                'q.surveyid = ? AND ' .
                'q.deleted = \'n\'';
            if ($record = $DB->get_record_sql($sql, [$rid, $this->sid])) {
                $newmax = (int) $record->num;
                if ($newmax > $max) {
                    $max = $newmax;
                }
            }
        }
        return $max;
    }

    /**
     * Handle all submission notification actions.
     *
     * @param int $rid The id of the response record.
     * @return bool Operation success.
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function submission_notify($rid) {
        global $DB;

        $success = true;

        if (isset($this->survey)) {
            if (isset($this->survey->email)) {
                $email = $this->survey->email;
            } else {
                $email = $DB->get_field('feedbackbox_survey', 'email', ['id' => $this->survey->id]);
            }
        } else {
            $email = '';
        }

        if (!empty($email)) {
            $success = $this->response_send_email($rid, $email);
        }

        if (!empty($this->notifications)) {
            // Handle notification of submissions.
            $success = $this->send_submission_notifications($rid) && $success;
        }

        return $success;
    }

    /**
     * Send submission notifications to users with "submissionnotification" capability.
     *
     * @param int $rid The id of the response record.
     * @return bool Operation success.
     * @throws coding_exception
     * @throws dml_exception
     */
    private function send_submission_notifications($rid) {
        global $CFG, $USER;

        $this->add_response($rid);
        $message = '';

        if ($this->notifications == 2) {
            $message .= $this->get_full_submission_for_notifications($rid);
        }

        $success = true;
        if ($notifyusers = $this->get_notifiable_users($USER->id)) {
            $info = new stdClass();
            // Need to handle user differently for anonymous surveys.
            if ($this->respondenttype != 'anonymous') {
                $info->userfrom = $USER;
                $info->username = fullname($info->userfrom, true);
                $info->profileurl = $CFG->wwwroot . '/user/view.php?id=' . $info->userfrom->id . '&course=' . $this->course->id;
                $langstringtext = 'submissionnotificationtextuser';
                $langstringhtml = 'submissionnotificationhtmluser';
            } else {
                $info->userfrom = core_user::get_noreply_user();
                $info->username = '';
                $info->profileurl = '';
                $langstringtext = 'submissionnotificationtextanon';
                $langstringhtml = 'submissionnotificationhtmlanon';
            }
            $info->name = format_string($this->name);
            $info->submissionurl = $CFG->wwwroot . '/mod/feedbackbox/report.php?action=vresp&sid=' . $this->survey->id .
                '&rid=' . $rid . '&instance=' . $this->id;
            $info->coursename = $this->course->fullname;

            $info->postsubject = get_string('submissionnotificationsubject', 'feedbackbox');
            $info->posttext = get_string($langstringtext, 'feedbackbox', $info);
            $info->posthtml = '<p>' . get_string($langstringhtml, 'feedbackbox', $info) . '</p>';
            if (!empty($message)) {
                $info->posttext .= html_to_text($message);
                $info->posthtml .= $message;
            }

            foreach ($notifyusers as $notifyuser) {
                $info->userto = $notifyuser;
                $this->send_message($info, 'notification');
            }
        }

        return $success;
    }

    /**
     * Message someone about something.
     *
     * @param object $info The information for the message.
     * @param string $eventtype
     * @return void
     * @throws coding_exception
     */
    private function send_message($info, $eventtype) {
        $eventdata = new message();
        $eventdata->courseid = $this->course->id;
        $eventdata->modulename = 'feedbackbox';
        $eventdata->userfrom = $info->userfrom;
        $eventdata->userto = $info->userto;
        $eventdata->subject = $info->postsubject;
        $eventdata->fullmessage = $info->posttext;
        $eventdata->fullmessageformat = FORMAT_PLAIN;
        $eventdata->fullmessagehtml = $info->posthtml;
        $eventdata->smallmessage = $info->postsubject;

        $eventdata->name = $eventtype;
        $eventdata->component = 'mod_feedbackbox';
        $eventdata->notification = 1;
        $eventdata->contexturl = $info->submissionurl;
        $eventdata->contexturlname = $info->name;

        message_send($eventdata);
    }

    /**
     * Returns a list of users that should receive notification about given submission.
     *
     * @param int $userid The submission to grade
     * @return array
     */
    public function get_notifiable_users($userid) {
        // Potential users should be active users only.
        $potentialusers = get_enrolled_users($this->context,
            'mod/feedbackbox:submissionnotification',
            null,
            'u.*',
            null,
            null,
            null,
            true);

        $notifiableusers = [];
        if (groups_get_activity_groupmode($this->cm) == SEPARATEGROUPS) {
            if ($groups = groups_get_all_groups($this->course->id, $userid, $this->cm->groupingid)) {
                foreach ($groups as $group) {
                    foreach ($potentialusers as $potentialuser) {
                        if ($potentialuser->id == $userid) {
                            // Do not send self.
                            continue;
                        }
                        if (groups_is_member($group->id, $potentialuser->id)) {
                            $notifiableusers[$potentialuser->id] = $potentialuser;
                        }
                    }
                }
            } else {
                // User not in group, try to find graders without group.
                foreach ($potentialusers as $potentialuser) {
                    if ($potentialuser->id == $userid) {
                        // Do not send self.
                        continue;
                    }
                    if (!groups_has_membership($this->cm, $potentialuser->id)) {
                        $notifiableusers[$potentialuser->id] = $potentialuser;
                    }
                }
            }
        } else {
            foreach ($potentialusers as $potentialuser) {
                if ($potentialuser->id == $userid) {
                    // Do not send self.
                    continue;
                }
                $notifiableusers[$potentialuser->id] = $potentialuser;
            }
        }
        return $notifiableusers;
    }

    /**
     * Return a formatted string containing all the questions and answers for a specific submission.
     *
     * @param $rid
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     */
    private function get_full_submission_for_notifications($rid) {
        $responses = $this->get_full_submission_for_export($rid);
        $message = '';
        foreach ($responses as $response) {
            $message .= html_to_text($response->questionname) . "<br />\n";
            $message .= get_string('question') . ': ' . html_to_text($response->questiontext) . "<br />\n";
            $message .= get_string('answers', 'feedbackbox') . ":<br />\n";
            foreach ($response->answers as $answer) {
                $message .= html_to_text($answer) . "<br />\n";
            }
            $message .= "<br />\n";
        }

        return $message;
    }

    /**
     * Construct the response data for a given response and return a structured export.
     *
     * @param $rid
     * @return array
     * @throws dml_exception
     */
    public function get_structured_response($rid) {
        $this->add_response($rid);
        return $this->get_full_submission_for_export($rid);
    }

    public function get_turnus_zones() {
        if ($this->turnus == 1) {
            return [(object) ['id' => 1, 'from' => $this->opendate, 'to' => $this->closedate]];
        }
        $mapping = [2 => 60 * 60 * 24 * 7, 3 => 60 * 60 * 24 * 14, 4 => 60 * 60 * 24 * 21];
        $count = 1;
        $zones = [];
        for ($i = $this->opendate; $i < $this->closedate; $i += $mapping[$this->turnus]) {
            $end = ($i + $mapping[$this->turnus]);
            if ($end >= $this->closedate) {
                $end = $this->closedate;
            }
            $zones[] = (object) ['id' => $count, 'from' => $i, 'to' => $end];
            $count++;
        }
        return $zones;
    }

    public function get_current_turnus($time = null) {
        if($time === null){
            $time = time();
        }
        $data = $this->get_turnus_zones();
        foreach ($data as $entry) {
            if ($entry->from < $time && $entry->to > $time) {
                $entry->fromstr = date('d.m.Y', $entry->from);
                $entry->tostr = date('d.m.Y', $entry->to);
                return $entry;
            }
        }
        return false;
    }

    public function get_last_turnus() {
        $data = $this->get_turnus_zones();
        $last = new stdClass();
        foreach ($data as $entry) {
            if ($this->turnus != 1 && isset($last->to) && $entry->from < time() && $entry->to > time()) {
                $last->fromstr = date('d.m.Y', $last->from);
                $last->tostr = date('d.m.Y', $last->to);
                return $last;
            }
            $last = $entry;
        }
        if ($this->turnus == 1) {
            $entry = end($data);
            if ($entry->to < time()) {
                $entry->fromstr = date('d.m.Y', $entry->from);
                $entry->tostr = date('d.m.Y', $entry->to);
                return $entry;
            }
        }
        return false;
    }

    public function get_feedback_responses_block($zone) {
        GLOBAl $DB;
        $result = new stdClass();

        $ranking = $DB->get_records_sql('SELECT qc.id as "id",qc.content as "content"
            FROM {feedbackbox_quest_choice} qc
            JOIN {feedbackbox_question} q ON qc.question_id = q.id
            WHERE q.surveyid = ? AND type_id = ?',
            [$this->survey->id, QUESRADIO]);

        $result->participants = intval($DB->get_field_sql(
            'SELECT count(*)
            FROM {feedbackbox_response}
            WHERE feedbackboxid=? AND submitted > ? AND submitted < ? AND complete=\'y\'',
            [$this->id, $zone->from, $zone->to]));
        $result->totalparticipants = count(get_enrolled_users(context_course::instance($this->course->id)));
        $radiochoise = $DB->get_records_sql(
            'SELECT choice_id, COUNT(*) as "result" FROM {feedbackbox_resp_single} rs' .
            ' JOIN {feedbackbox_response} r ON rs.response_id = r.id AND r.complete=\'y\'' .
            ' WHERE r.feedbackboxid=? AND r.submitted > ? AND r.submitted < ? GROUP BY rs.choice_id',
            [$this->id, $zone->from, $zone->to]);
        $result->rating = [0, 0, 0, 0];
        $rankinglist = array_reverse(array_column($ranking, 'id'));
        foreach ($radiochoise as $choise) {
            $result->rating[array_search($choise->choice_id, $rankinglist)] = $choise->result;
        }
        $result->ratinglabel = array_reverse(array_column($ranking, 'content'));

        $positions = [4, 5, 6, 7, 8];
        list($insql, $inparams) = $DB->get_in_or_equal($positions);
        $top3 = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result"FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND q.type_id = ? AND q.position ' . $insql .
            ' AND r.submitted > ? AND r.submitted < ? GROUP BY qc.content ORDER BY result DESC LIMIT 3',
            array_merge([$this->id, QUESCHECK], $inparams, [$zone->from, $zone->to]));
        $counter = 1;
        foreach ($top3 as $entry) {
            unset($entry->result);
            $entry->id = $counter++;
        }
        $result->good = array_values($top3);
        $top3 = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND q.type_id = ? AND NOT q.position ' . $insql .
            ' AND r.submitted > ? AND r.submitted < ? GROUP BY qc.content ORDER BY result DESC LIMIT 3',
            array_merge([$this->id, QUESCHECK], $inparams, [$zone->from, $zone->to]));
        $counter = 1;
        foreach ($top3 as $entry) {
            unset($entry->result);
            $entry->id = $counter++;
        }
        $result->bad = array_values($top3);
        return $result;
    }

    public function get_feedback_responses() {
        GLOBAl $DB;
        $result = new stdClass();
        $zones = $this->get_turnus_zones();
        $ranking = $DB->get_fieldset_sql('SELECT qc.id FROM {feedbackbox_quest_choice} qc
            JOIN {feedbackbox_question} q ON qc.question_id = q.id WHERE q.surveyid = ? AND type_id = ?',
            [$this->survey->id, QUESRADIO]);
        foreach ($zones as $zone) {
            $responses = $DB->get_records_sql(
                'SELECT * FROM {feedbackbox_response}
                WHERE feedbackboxid=? AND submitted > ? AND submitted < ? AND complete=\'y\'',
                [$this->id, $zone->from, $zone->to]);
            $zone->participants = count($responses);
            $radiochoise = $DB->get_records_sql(
                'SELECT choice_id, COUNT(*) as "result" FROM {feedbackbox_resp_single} rs' .
                ' JOIN {feedbackbox_response} r ON rs.response_id = r.id' .
                ' WHERE r.feedbackboxid=? AND r.submitted > ?' .
                ' AND r.submitted < ? AND r.complete=\'y\' GROUP BY rs.choice_id',
                [$this->id, $zone->from, $zone->to]);
            $zone->rating = 0;
            $ranking = array_reverse($ranking);
            foreach ($radiochoise as $choise) {
                $zone->rating += (array_search($choise->choice_id, $ranking) + 1) * $choise->result;
            }
            if ($zone->rating != 0) {
                $zone->rating /= $zone->participants;
            }
        }
        $result->zones = $zones;
        $result->totalparticipants = count(get_enrolled_users(context_course::instance($this->course->id)));
        $positions = [4, 5, 6, 7, 8];
        list($insql, $inparams) = $DB->get_in_or_equal($positions);
        $top3 = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND q.type_id = ? AND q.position ' . $insql .
            ' GROUP BY qc.content ORDER BY result DESC LIMIT 3',
            array_merge([$this->id, QUESCHECK], $inparams));
        $counter = 1;
        foreach ($top3 as $entry) {
            unset($entry->result);
            $entry->id = $counter++;
        }
        $result->good = array_values($top3);
        $top3 = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND q.type_id = ? AND NOT q.position ' . $insql .
            ' GROUP BY qc.content ORDER BY result DESC LIMIT 3',
            array_merge([$this->id, QUESCHECK], $inparams));
        $counter = 1;
        foreach ($top3 as $entry) {
            unset($entry->result);
            $entry->id = $counter++;
        }
        $result->bad = array_values($top3);
        return $result;
    }


    /**
     * @param $turnus
     * @return bool|stdClass
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function get_turnus_responses($turnus) {
        GLOBAL $DB;
        $zones = $this->get_turnus_zones();
        $zoneid = array_search($turnus, array_column($zones, 'id'));
        if ($zoneid === false) {
            return false; // Not valid turnus requested.
        }
        $result = new stdClass();
        foreach ($zones as $zone) {
            $zone->fromdate = date('d.m.Y', $zone->from);
            $zone->todate = date('d.m.Y', $zone->to);
            if ($turnus == $zone->id) {
                $zone->currenta = true;
            }
            if ($zone->to < time()) {
                $zone->display = true;
            }
            if ($zone->from < time() && $zone->to > time()) {
                $zone->display = true;
            }
            $zone->url = new moodle_url('/mod/feedbackbox/report.php',
                ['instance' => $this->id, 'action' => 'single', 'turnus' => $zone->id]);
        }
        $zone = $zones[$zoneid];
        $result->zones = $zones;
        $result->zone = $zone;

        if ($zone->from < time() && $zone->to > time()) {
            $result->current = true;
        }

        $ranking = $DB->get_records_sql('SELECT qc.id as "id",qc.content as "content"
            FROM {feedbackbox_quest_choice} qc
            JOIN {feedbackbox_question} q ON qc.question_id = q.id WHERE q.surveyid = ? AND type_id = ?',
            [$this->survey->id, QUESRADIO]);

        $result->participants = intval($DB->get_field_sql(
            'SELECT count(*) FROM {feedbackbox_response}
            WHERE feedbackboxid=? AND submitted > ? AND submitted < ? AND complete=\'y\'',
            [$this->id, $zone->from, $zone->to]));
        $result->totalparticipants = count(get_enrolled_users(context_course::instance($this->course->id)));

        $radiochoise = $DB->get_records_sql(
            'SELECT choice_id, COUNT(*) as "result" FROM {feedbackbox_resp_single} rs' .
            ' JOIN {feedbackbox_response} r ON rs.response_id = r.id AND r.complete=\'y\'' .
            ' WHERE r.feedbackboxid=? AND r.submitted > ? AND r.submitted < ? GROUP BY rs.choice_id',
            [$this->id, $zone->from, $zone->to]);

        $result->rating = [0, 0, 0, 0];
        $rankinglist = array_reverse(array_column($ranking, 'id'));
        foreach ($radiochoise as $choise) {
            $result->rating[array_search($choise->choice_id, $rankinglist)] = $choise->result;
        }
        $result->ratinglabel = array_reverse(array_column($ranking, 'content'));

        $positions = [4, 5, 6, 7, 8];
        list($insql, $inparams) = $DB->get_in_or_equal($positions);
        $good = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position ' .
            $insql . ' GROUP BY qc.content ORDER BY result DESC',
            array_merge([$this->id, $zone->from, $zone->to, QUESCHECK], $inparams));
        $result->goodchoises = array_values($good);
        list($insql, $inparams) = $DB->get_in_or_equal($positions);
        $bad = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND NOT q.position ' .
            $insql . ' GROUP BY qc.content ORDER BY result DESC',
            array_merge([$this->id, $zone->from, $zone->to, QUESCHECK], $inparams));
        $result->badchoises = array_values($bad);

        $result->goodmessages = array_values($DB->get_fieldset_sql('SELECT rt.response FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_response_text} rt ON rt.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rt.question_id = q.id' .
            ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position = ?',
            [$this->id, $zone->from, $zone->to, QUESESSAY, 9]));
        $result->badmessages = array_values($DB->get_fieldset_sql('SELECT rt.response FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_response_text} rt ON rt.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rt.question_id = q.id' .
            ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position = ?',
            [$this->id, $zone->from, $zone->to, QUESESSAY, 17]));
        return $result;
    }

    /**
     * Return a JSON structure containing all the questions and answers for a specific submission.
     *
     * @param $rid
     * @return array
     * @throws dml_exception
     */
    private function get_full_submission_for_export($rid) {
        if (!isset($this->responses[$rid])) {
            $this->add_response($rid);
        }

        $exportstructure = [];
        foreach ($this->questions as $question) {
            $rqid = 'q' . $question->id;
            $response = new stdClass();
            $response->questionname = $question->position . '. ' . $question->name;
            $response->questiontext = $question->content;
            $response->answers = [];
            if ($question->type_id == 8) {
                $choices = [];
                $cids = [];
                foreach ($question->choices as $cid => $choice) {
                    if (!empty($choice->value) && (strpos($choice->content, '=') !== false)) {
                        $choices[$choice->value] = substr($choice->content, (strpos($choice->content, '=') + 1));
                    } else {
                        $cids[$rqid . '_' . $cid] = $choice->content;
                    }
                }
                if (isset($this->responses[$rid]->answers[$question->id])) {
                    foreach ($cids as $rqid => $choice) {
                        $cid = substr($rqid, (strpos($rqid, '_') + 1));
                        if (isset($this->responses[$rid]->answers[$question->id][$cid])) {
                            if (isset($question->choices[$cid]) &&
                                isset($choices[$this->responses[$rid]->answers[$question->id][$cid]->value])) {
                                $rating = $choices[$this->responses[$rid]->answers[$question->id][$cid]->value];
                            } else {
                                $rating = $this->responses[$rid]->answers[$question->id][$cid]->value;
                            }
                            $response->answers[] = $question->choices[$cid]->content . ' = ' . $rating;
                        }
                    }
                }
            } else if ($question->has_choices()) {
                $answertext = '';
                if (isset($this->responses[$rid]->answers[$question->id])) {
                    $i = 0;
                    foreach ($this->responses[$rid]->answers[$question->id] as $answer) {
                        if ($i > 0) {
                            $answertext .= '; ';
                        }
                        if ($question->choices[$answer->choiceid]->is_other_choice()) {
                            $answertext .= $answer->value;
                        } else {
                            $answertext .= $question->choices[$answer->choiceid]->content;
                        }
                        $i++;
                    }
                }
                $response->answers[] = $answertext;

            } else if (isset($this->responses[$rid]->answers[$question->id])) {
                $response->answers[] = $this->responses[$rid]->answers[$question->id][0]->value;
            }
            $exportstructure[] = $response;
        }

        return $exportstructure;
    }

    /**
     * Format the submission answers for legacy email delivery.
     *
     * @param array $answers The array of response answers.
     * @return array The formatted set of answers as plain text and HTML.
     * @throws coding_exception
     */
    private function get_formatted_answers_for_emails($answers) {
        global $USER;

        // Line endings for html and plaintext emails.
        $endhtml = "\r\n<br />";
        $endplaintext = "\r\n";

        reset($answers);

        $formatted = ['plaintext' => '', 'html' => ''];
        for ($i = 0; $i < count($answers[0]); $i++) {
            $sep = ' : ';

            switch ($i) {
                case 1:
                    $sep = ' ';
                    break;
                case 4:
                    $formatted['plaintext'] .= get_string('user') . ' ';
                    $formatted['html'] .= get_string('user') . ' ';
                    break;
                case 6:
                    if ($this->respondenttype != 'anonymous') {
                        $formatted['html'] .= get_string('email') . $sep . $USER->email . $endhtml;
                        $formatted['plaintext'] .= get_string('email') . $sep . $USER->email . $endplaintext;
                    }
            }
            $formatted['html'] .= $answers[0][$i] . $sep . $answers[1][$i] . $endhtml;
            $formatted['plaintext'] .= $answers[0][$i] . $sep . $answers[1][$i] . $endplaintext;
        }

        return $formatted;
    }

    /**
     * Send the full response submission to the defined email addresses.
     *
     * @param int    $rid   The id of the response record.
     * @param string $email The comma separated list of emails to send to.
     * @return bool
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    private function response_send_email($rid, $email) {
        global $CFG;

        $submission = $this->generate_csv($rid, '', null, 1, 0);
        if (!empty($submission)) {
            $answers = $this->get_formatted_answers_for_emails($submission);
        } else {
            $answers = ['html' => '', 'plaintext' => ''];
        }

        $name = s($this->name);
        if (empty($email)) {
            return (false);
        }

        // Line endings for html and plaintext emails.
        $endhtml = "\r\n<br>";
        $endplaintext = "\r\n";

        $subject = get_string('surveyresponse', 'feedbackbox') . ": $name [$rid]";
        $url = $CFG->wwwroot . '/mod/feedbackbox/report.php?action=vresp&amp;sid=' . $this->survey->id .
            '&amp;rid=' . $rid . '&amp;instance=' . $this->id;

        // Html and plaintext body.
        $bodyhtml = '<a href="' . $url . '">' . $url . '</a>' . $endhtml;
        $bodyplaintext = $url . $endplaintext;
        $bodyhtml .= get_string('surveyresponse', 'feedbackbox') . ' "' . $name . '"' . $endhtml;
        $bodyplaintext .= get_string('surveyresponse', 'feedbackbox') . ' "' . $name . '"' . $endplaintext;

        $bodyhtml .= $answers['html'];
        $bodyplaintext .= $answers['plaintext'];

        // Use plaintext version for altbody.
        $altbody = "\n$bodyplaintext\n";

        $return = true;
        $mailaddresses = preg_split('/[,;]/', $email);
        foreach ($mailaddresses as $email) {
            $userto = new stdClass();
            $userto->email = trim($email);
            $userto->mailformat = 1;
            // Dummy userid to keep email_to_user happy in moodle 2.6.
            $userto->id = -10;
            $userfrom = $CFG->noreplyaddress;
            if (email_to_user($userto, $userfrom, $subject, $altbody, $bodyhtml)) {
                $return = $return && true;
            } else {
                $return = false;
            }
        }
        return $return;
    }

    /**
     * @param object $responsedata An object containing all data for the response.
     * @param int    $userid
     * @param bool   $resume
     * @return bool|int
     * @throws dml_exception
     */
    public function response_insert($responsedata, $userid, $resume = false) {
        global $DB;

        $record = new stdClass();
        $record->submitted = time();

        if (empty($responsedata->rid)) {
            // Create a uniqe id for this response.
            $record->feedbackboxid = $this->id;
            $record->userid = $userid;
            $responsedata->rid = $DB->insert_record('feedbackbox_response', $record);
            $responsedata->id = $responsedata->rid;
        } else {
            $record->id = $responsedata->rid;
            $DB->update_record('feedbackbox_response', $record);
        }

        if (!isset($responsedata->sec)) {
            $responsedata->sec = 1;
        }
        if (!empty($this->questionsbysec[$responsedata->sec])) {
            foreach ($this->questionsbysec[$responsedata->sec] as $questionid) {
                $this->questions[$questionid]->insert_response($responsedata);
            }
        }
        return ($responsedata->rid);
    }

    /**
     * @param $rid
     * @return array
     * @noinspection PhpUnusedPrivateMethodInspection
     * @throws dml_exception
     */
    private function response_select($rid) {
        // Response_single (radio button or dropdown).
        $values = single::response_select($rid);

        // Response_multiple.
        $values += multiple::response_select($rid);

        // Response_text.
        $values += text::response_select($rid);

        return ($values);
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    private function response_goto_thankyou() {
        global $DB;

        $select = 'id = ' . $this->survey->id;
        $fields = 'thanks_page, thank_head, thank_body';
        if ($result = $DB->get_record_select('feedbackbox_survey', $select, null, $fields)) {
            $thankurl = $result->thanks_page;
        } else {
            $thankurl = '';
        }
        if (!empty($thankurl)) {
            if (!headers_sent()) {
                header("Location: $thankurl");
                exit;
            }
            echo '
                <script type="text/javascript">
                <!--
                window.location="' . $thankurl . '"
                //-->
                </script>
                <noscript>
                <h2 class="thankhead">Thank You for completing this survey.</h2>
                <blockquote class="thankbody">Please click
                <a href="' . $thankurl . '">here</a> to continue.</blockquote>
                </noscript>
            ';
            exit;
        }

        $this->page->add_to_page('title', 'Check! Dein Feedback wurde erfolgreich eingereicht.');
        $this->page->add_to_page('addinfo',
            ($this->renderer->pix_icon('b/fb_danke', 'Icon', 'mod_feedbackbox')) .
            '<br/><div style="padding-top: 5px; padding-bottom: 5px">
<b>Vielen Dank für deine Mithilfe und Zeit!</b><br/>
Bitte habe Verständnis, wenn nicht alle deine Wünsche direkt umgesetzt werden.<br/>
Die Auswertung erfolgt, wenn die laufende Feedbackrunde beendet ist.<br/>
Ihr könnt dann gemeinsam Lösungen finden.</div><br/>');
        // Default set currentgroup to view all participants.
        // TODO why not set to current respondent's groupid (if any)?
        $url = new moodle_url('/course/view.php', ['id' => $this->course->id]);
        $this->page->add_to_page('continue', $this->renderer->single_button($url, get_string('continue')));
        return;
    }

    /**
     * @throws coding_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    private function response_goto_saved() {
        global $CFG;
        $resumesurvey = get_string('resumesurvey', 'feedbackbox');
        $savedprogress = get_string('savedprogress', 'feedbackbox', '<strong>' . $resumesurvey . '</strong>');
        $this->page->add_to_page('notifications',
            $this->renderer->notification($savedprogress, notification::NOTIFY_SUCCESS));
        $this->page->add_to_page('respondentinfo',
            $this->renderer->homelink($CFG->wwwroot . '/course/view.php?id=' . $this->course->id,
                get_string("backto", "moodle", $this->course->fullname)));
        return;
    }

    /**
     * Get unique list of question types used in the current survey.
     *
     * @param bool $uniquebytable
     * @return array
     * @author: Guy Thomas
     */
    protected function get_survey_questiontypes($uniquebytable = false) {

        $uniquetypes = [];
        $uniquetables = [];

        foreach ($this->questions as $question) {
            $type = $question->type_id;
            $responsetable = $question->responsetable;
            // Build SQL for this question type if not already done.
            if (!$uniquebytable || !in_array($responsetable, $uniquetables)) {
                if (!in_array($type, $uniquetypes)) {
                    $uniquetypes[] = $type;
                }
                if (!in_array($responsetable, $uniquetables)) {
                    $uniquetables[] = $responsetable;
                }
            }
        }

        return $uniquetypes;
    }

    /**
     * Return array of all types considered to be choices.
     *
     * @return array
     */
    protected function choice_types() {
        return [QUESRADIO, QUESDROP, QUESCHECK, QUESRATE];
    }

    /**
     * Return all the fields to be used for users in feedbackbox sql.
     *
     * @return array|string
     * @author: Guy Thomas
     */
    protected function user_fields() {
        $userfieldsarr = get_all_user_name_fields();
        $userfieldsarr = array_merge($userfieldsarr, ['username', 'department', 'institution']);
        return $userfieldsarr;
    }

    /**
     * Get all survey responses in one go.
     *
     * @param string $rid
     * @param string $userid
     * @param bool   $groupid
     * @param int    $showincompletes
     * @return moodle_recordset
     * @throws dml_exception
     * @author: Guy Thomas
     */
    protected function get_survey_all_responses($rid = '', $userid = '', $groupid = false, $showincompletes = 0) {
        global $DB;
        $uniquetypes = $this->get_survey_questiontypes(true);
        $allresponsessql = "";
        $allresponsesparams = [];

        // If a feedbackbox is "public", and this is the master course, need to get responses from all instances.
        if ($this->survey_is_public_master()) {
            $qids = array_keys($DB->get_records('feedbackbox', ['sid' => $this->sid], 'id'));
        } else {
            $qids = $this->id;
        }

        foreach ($uniquetypes as $type) {
            $question = question::question_builder($type);
            if (!isset($question->responsetype)) {
                continue;
            }
            $allresponsessql .= $allresponsessql == '' ? '' : ' UNION ALL ';
            list ($sql, $params) = $question->responsetype->get_bulk_sql($qids,
                $rid,
                $userid,
                $groupid,
                $showincompletes);
            $allresponsesparams = array_merge($allresponsesparams, $params);
            $allresponsessql .= $sql;
        }

        $allresponsessql .= " ORDER BY usrid, id";
        return $DB->get_recordset_sql($allresponsessql, $allresponsesparams);
    }

    /**
     * Return true if the survey is a 'public' one.
     *
     * @return bool
     */
    public function survey_is_public() {
        return is_object($this->survey) && ($this->survey->realm == 'public');
    }

    /**
     * Return true if the survey is a 'public' one and this is the master instance.
     *
     * @return bool
     */
    public function survey_is_public_master() {
        return $this->survey_is_public() && ($this->course->id == $this->survey->courseid);
    }

    /**
     * Process individual row for csv output
     *
     * @param array    $row
     * @param stdClass $resprow resultset row
     * @param int      $currentgroupid
     * @param array    $questionsbyposition
     * @param int      $nbinfocols
     * @param int      $numrespcols
     * @param int      $showincompletes
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function process_csv_row(array &$row,
        stdClass $resprow,
        $currentgroupid,
        array &$questionsbyposition,
        $nbinfocols,
        $numrespcols,
        $showincompletes = 0) {
        global $DB;

        static $config = null;
        // If using an anonymous response, map users to unique user numbers so that number of unique anonymous users can be seen.
        static $anonumap = [];

        if ($config === null) {
            $config = get_config('feedbackbox', 'downloadoptions');
        }
        $options = empty($config) ? [] : explode(',', $config);
        if ($showincompletes == 1) {
            $options[] = 'complete';
        }

        $positioned = [];
        $user = new stdClass();
        foreach ($this->user_fields() as $userfield) {
            $user->$userfield = $resprow->$userfield;
        }
        $user->id = $resprow->userid;
        $isanonymous = ($this->respondenttype == 'anonymous');

        // Moodle:
        // Get the course name that this feedbackbox belongs to.
        if (!$this->survey_is_public()) {
            $courseid = $this->course->id;
            $coursename = $this->course->fullname;
        } else {
            // For a public feedbackbox, look for the course that used it.
            $sql = 'SELECT q.id, q.course, c.fullname ' .
                'FROM {feedbackbox_response} qr ' .
                'INNER JOIN {feedbackbox} q ON qr.feedbackboxid = q.id ' .
                'INNER JOIN {course} c ON q.course = c.id ' .
                'WHERE qr.id = ? AND qr.complete = ? ';
            if ($record = $DB->get_record_sql($sql, [$resprow->rid, 'y'])) {
                $courseid = $record->course;
                $coursename = $record->fullname;
            } else {
                $courseid = $this->course->id;
                $coursename = $this->course->fullname;
            }
        }

        // Moodle:
        // Determine if the user is a member of a group in this course or not.
        // TODO - review for performance.
        $groupname = '';
        if (groups_get_activity_groupmode($this->cm, $this->course)) {
            if ($currentgroupid > 0) {
                $groupname = groups_get_group_name($currentgroupid);
            } else {
                if ($user->id) {
                    if ($groups = groups_get_all_groups($courseid, $user->id)) {
                        foreach ($groups as $group) {
                            $groupname .= $group->name . ', ';
                        }
                        $groupname = substr($groupname, 0, strlen($groupname) - 2);
                    } else {
                        $groupname = ' (' . get_string('groupnonmembers') . ')';
                    }
                }
            }
        }

        if ($isanonymous) {
            if (!isset($anonumap[$user->id])) {
                $anonumap[$user->id] = count($anonumap) + 1;
            }
            $fullname = get_string('anonymous', 'feedbackbox') . $anonumap[$user->id];
            $username = '';
            $uid = '';
        } else {
            $uid = $user->id;
            $fullname = fullname($user);
            $username = $user->username;
        }

        if (in_array('response', $options)) {
            array_push($positioned, $resprow->rid);
        }
        if (in_array('submitted', $options)) {
            // For better compabitility & readability with Excel.
            $submitted = date(get_string('strfdateformatcsv', 'feedbackbox'), $resprow->submitted);
            array_push($positioned, $submitted);
        }
        if (in_array('institution', $options)) {
            array_push($positioned, $user->institution);
        }
        if (in_array('department', $options)) {
            array_push($positioned, $user->department);
        }
        if (in_array('course', $options)) {
            array_push($positioned, $coursename);
        }
        if (in_array('group', $options)) {
            array_push($positioned, $groupname);
        }
        if (in_array('id', $options)) {
            array_push($positioned, $uid);
        }
        if (in_array('fullname', $options)) {
            array_push($positioned, $fullname);
        }
        if (in_array('username', $options)) {
            array_push($positioned, $username);
        }
        if (in_array('complete', $options)) {
            array_push($positioned, $resprow->complete);
        }

        for ($c = $nbinfocols; $c < $numrespcols; $c++) {
            if (isset($row[$c])) {
                $positioned[] = $row[$c];
            } else if (isset($questionsbyposition[$c])) {
                $question = $questionsbyposition[$c];
                $qtype = intval($question->type_id);
                if ($qtype === QUESCHECK) {
                    $positioned[] = '0';
                } else {
                    $positioned[] = null;
                }
            } else {
                $positioned[] = null;
            }
        }
        return $positioned;
    }

    /* {{{ proto array survey_generate_csv(int surveyid)
    Exports the results of a survey to an array.
    */
    /**
     * @param     $rid
     * @param     $userid
     * @param     $choicecodes
     * @param     $choicetext
     * @param     $currentgroupid
     * @param int $showincompletes
     * @param int $rankaverages
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function generate_csv($rid,
        $userid,
        $choicecodes,
        $choicetext,
        $currentgroupid,
        $showincompletes = 0,
        $rankaverages = 0) {
        global $DB;

        raise_memory_limit('1G');

        $output = [];
        $stringother = get_string('other', 'feedbackbox');

        $config = get_config('feedbackbox', 'downloadoptions');
        $options = empty($config) ? [] : explode(',', $config);
        if ($showincompletes == 1) {
            $options[] = 'complete';
        }
        $columns = [];
        $types = [];
        foreach ($options as $option) {
            if (in_array($option, ['response', 'submitted', 'id'])) {
                $columns[] = get_string($option, 'feedbackbox');
                $types[] = 0;
            } else {
                $columns[] = get_string($option);
                $types[] = 1;
            }
        }
        $nbinfocols = count($columns);

        $idtocsvmap = [
            '0',    // 0: unused
            '0',    // 1: bool -> boolean
            '1',    // 2: text -> string
            '1',    // 3: essay -> string
            '0',    // 4: radio -> string
            '0',    // 5: check -> string
            '0',    // 6: dropdn -> string
            '0',    // 7: rating -> number
            '0',    // 8: rate -> number
            '1',    // 9: date -> string
            '0'     // 10: numeric -> number.
        ];

        if (!$survey = $DB->get_record('feedbackbox_survey', ['id' => $this->survey->id])) {
            print_error('surveynotexists', 'feedbackbox');
        }

        // Get all responses for this survey in one go.
        $allresponsesrs = $this->get_survey_all_responses($rid, $userid, $currentgroupid, $showincompletes);

        // Do we have any questions of type RADIO, DROP, CHECKBOX OR RATE? If so lets get all their choices in one go.
        $choicetypes = $this->choice_types();

        // Get unique list of question types used in this survey.
        $uniquetypes = $this->get_survey_questiontypes();

        if (count(array_intersect($choicetypes, $uniquetypes)) > 0) {
            $choiceparams = [$this->survey->id];
            $choicesql = "
                SELECT DISTINCT c.id as cid, q.id as qid, q.precise AS precise, q.name, c.content
                  FROM {feedbackbox_question} q
                  JOIN {feedbackbox_quest_choice} c ON question_id = q.id
                 WHERE q.surveyid = ? ORDER BY cid ASC
            ";
            $choicerecords = $DB->get_records_sql($choicesql, $choiceparams);
            $choicesbyqid = [];
            if (!empty($choicerecords)) {
                // Hash the options by question id.
                foreach ($choicerecords as $choicerecord) {
                    if (!isset($choicesbyqid[$choicerecord->qid])) {
                        // New question id detected, intialise empty array to store choices.
                        $choicesbyqid[$choicerecord->qid] = [];
                    }
                    $choicesbyqid[$choicerecord->qid][$choicerecord->cid] = $choicerecord;
                }
            }
        }

        $num = 1;

        $questionidcols = [];
        $choicesbyqid = [];
        foreach ($this->questions as $question) {
            // Skip questions that aren't response capable.
            if (!isset($question->responsetype)) {
                continue;
            }
            // Establish the table's field names.
            $qid = $question->id;
            $qpos = $question->position;
            $col = $question->name;
            $type = $question->type_id;
            if (in_array($type, $choicetypes)) {
                /* single or multiple or rate */
                if (!isset($choicesbyqid[$qid])) {
                    throw new coding_exception('Choice question has no choices!',
                        'question id ' . $qid . ' of type ' . $type);
                }
                $choices = $choicesbyqid[$qid];
                switch ($type) {
                    case QUESRADIO: // Single.
                    case QUESDROP:
                        $columns[][$qpos] = $col;
                        $questionidcols[][$qpos] = $qid;
                        array_push($types, $idtocsvmap[$type]);
                        foreach ($choices as $choice) {
                            $content = $choice->content;
                            // If "Other" add a column for the actual "other" text entered.
                            if (choice::content_is_other_choice($content)) {
                                $col = $choice->name . '_' . $stringother;
                                $columns[][$qpos] = $col;
                                $questionidcols[][$qpos] = null;
                                array_push($types, '0');
                            }
                        }
                        break;
                    case QUESCHECK: // Multiple.
                        foreach ($choices as $choice) {
                            $content = $choice->content;
                            $contents = feedbackbox_choice_values($content);
                            if ($contents->modname) {
                                $modality = $contents->modname;
                            } else if ($contents->title) {
                                $modality = $contents->title;
                            } else {
                                $modality = strip_tags($contents->text);
                            }
                            $col = $choice->name . '->' . $modality;
                            $columns[][$qpos] = $col;
                            $questionidcols[][$qpos] = $qid . '_' . $choice->cid;
                            array_push($types, '0');
                            // If "Other" add a column for the "other" checkbox.
                            // Then add a column for the actual "other" text entered.
                            if (choice::content_is_other_choice($content)) {
                                $content = $stringother;
                                $col = $choice->name . '->[' . $content . ']';
                                $columns[][$qpos] = $col;
                                $questionidcols[][$qpos] = null;
                                array_push($types, '0');
                            }
                        }
                        break;

                    case QUESRATE: // Rate.
                        foreach ($choices as $choice) {
                            $content = $choice->content;
                            $osgood = false;
                            if (!preg_match("/^[0-9]{1,3}=/", $content, $ndd)) {
                                if ($osgood) {
                                    list($contentleft, $contentright) = array_merge(preg_split('/[|]/', $content),
                                        [' ']);
                                    $contents = feedbackbox_choice_values($contentleft);
                                    if ($contents->title) {
                                        $contentleft = $contents->title;
                                    }
                                    $contents = feedbackbox_choice_values($contentright);
                                    if ($contents->title) {
                                        $contentright = $contents->title;
                                    }
                                    $modality = strip_tags($contentleft . '|' . $contentright);
                                    $modality = preg_replace("/[\r\n\t]/", ' ', $modality);
                                } else {
                                    $contents = feedbackbox_choice_values($content);
                                    if ($contents->modname) {
                                        $modality = $contents->modname;
                                    } else if ($contents->title) {
                                        $modality = $contents->title;
                                    } else {
                                        $modality = strip_tags($contents->text);
                                        $modality = preg_replace("/[\r\n\t]/", ' ', $modality);
                                    }
                                }
                                $col = $choice->name . '->' . $modality;
                                $columns[][$qpos] = $col;
                                $questionidcols[][$qpos] = $qid . '_' . $choice->cid;
                                array_push($types, $idtocsvmap[$type]);
                            }
                        }
                        break;
                }
            } else {
                $columns[][$qpos] = $col;
                $questionidcols[][$qpos] = $qid;
                array_push($types, $idtocsvmap[$type]);
            }
            $num++;
        }

        array_push($output, $columns);
        $numrespcols = count($output[0]); // Number of columns used for storing question responses.

        // Flatten questionidcols.
        $tmparr = [];
        for ($c = 0; $c < $nbinfocols; $c++) {
            $tmparr[] = null; // Pad with non question columns.
        }
        foreach ($questionidcols as $i => $positions) {
            foreach ($positions as $position => $qid) {
                $tmparr[] = $qid;
            }
        }
        $questionidcols = $tmparr;

        // Create array of question positions hashed by question / question + choiceid.
        // And array of questions hashed by position.
        $questionpositions = [];
        $questionsbyposition = [];
        $p = 0;
        foreach ($questionidcols as $qid) {
            if ($qid === null) {
                // This is just padding, skip.
                $p++;
                continue;
            }
            $questionpositions[$qid] = $p;
            if (strpos($qid, '_') !== false) {
                $tmparr = explode('_', $qid);
                $questionid = $tmparr[0];
            } else {
                $questionid = $qid;
            }
            $questionsbyposition[$p] = $this->questions[$questionid];
            $p++;
        }

        $formatoptions = new stdClass();
        $formatoptions->filter = false;  // To prevent any filtering in CSV output.
        $rids = [];
        $averages = [];
        if ($rankaverages) {
            $rids = [];
            $allresponsesrs2 = $this->get_survey_all_responses($rid, $userid, $currentgroupid);
            foreach ($allresponsesrs2 as $responserow) {
                if (!isset($rids[$responserow->rid])) {
                    $rids[$responserow->rid] = $responserow->rid;
                }
            }
        }

        // Get textual versions of responses, add them to output at the correct col position.
        $prevresprow = false; // Previous response row.
        $row = [];
        $averagerow = [];
        foreach ($allresponsesrs as $responserow) {
            $qid = $responserow->question_id;

            // It's possible for a response to exist for a deleted question. Ignore these.
            if (!isset($this->questions[$qid])) {
                break;
            }

            $question = $this->questions[$qid];
            $qtype = intval($question->type_id);
            if ($rankaverages) {
                if ($qtype === QUESRATE) {
                    if (empty($averages[$qid])) {
                        $results = $this->questions[$qid]->responsetype->get_results($rids);
                        foreach ($results as $qresult) {
                            $averages[$qid][$qresult->id] = $qresult->average;
                        }
                    }
                }
            }
            $questionobj = $this->questions[$qid];
            if ($qtype === QUESRATE || $qtype === QUESCHECK) {
                $key = $qid . '_' . $responserow->choice_id;
                $position = $questionpositions[$key];
                if ($qtype === QUESRATE) {
                    $choicetxt = $responserow->rankvalue;
                    if ($rankaverages) {
                        $averagerow[$position] = $averages[$qid][$responserow->choice_id];
                    }
                } else {
                    $content = $choicesbyqid[$qid][$responserow->choice_id]->content;
                    if (choice::content_is_other_choice($content)) {
                        // If this is an "other" column, put the text entered in the next position.
                        $row[$position + 1] = $responserow->response;
                        $choicetxt = empty($responserow->choice_id) ? '0' : '1';
                    } else if (!empty($responserow->choice_id)) {
                        $choicetxt = '1';
                    } else {
                        $choicetxt = '0';
                    }
                }
                $responsetxt = $choicetxt;
                $row[$position] = $responsetxt;
            } else {
                $position = $questionpositions[$qid];
                if ($questionobj->has_choices()) {
                    // This is choice type question, so process as so.
                    $c = 0;
                    if (in_array(intval($question->type_id), $choicetypes)) {
                        $choices = $choicesbyqid[$qid];
                        // Get position of choice.
                        foreach ($choices as $choice) {
                            $c++;
                            if ($responserow->choice_id === $choice->cid) {
                                break;
                            }
                        }
                    }

                    $content = $choicesbyqid[$qid][$responserow->choice_id]->content;
                    if (choice::content_is_other_choice($content)) {
                        // If this has an "other" text, use it.
                        $responsetxt = choice::content_other_choice_display($content);
                        $responsetxt1 = $responserow->response;
                    } else if (($choicecodes == 1) && ($choicetext == 1)) {
                        $responsetxt = $c . ' : ' . $content;
                    } else if ($choicecodes == 1) {
                        $responsetxt = $c;
                    } else {
                        $responsetxt = $content;
                    }
                } else if (intval($qtype) === QUESYESNO) {
                    // At this point, the boolean responses are returned as characters in the "response"
                    // field instead of "choice_id" for csv exports (CONTRIB-6436).
                    $responsetxt = $responserow->response === 'y' ? "1" : "0";
                } else {
                    // Strip potential html tags from modality name.
                    $responsetxt = $responserow->response;
                    if (!empty($responsetxt)) {
                        $responsetxt = $responserow->response;
                        $responsetxt = strip_tags($responsetxt);
                        $responsetxt = preg_replace("/[\r\n\t]/", ' ', $responsetxt);
                    }
                }
                $row[$position] = $responsetxt;
                // Check for "other" text and set it to the next position if present.
                if (!empty($responsetxt1)) {
                    $responsetxt1 = preg_replace("/[\r\n\t]/", ' ', $responsetxt1);
                    $row[$position + 1] = $responsetxt1;
                    unset($responsetxt1);
                }
            }

            $prevresprow = $responserow;
        }

        if ($prevresprow !== false) {
            // Add final row to output. May not exist if no response data was ever present.
            $output[] = $this->process_csv_row($row,
                $prevresprow,
                $currentgroupid,
                $questionsbyposition,
                $nbinfocols,
                $numrespcols,
                $showincompletes);
        }

        // Add averages row if appropriate.
        if ($rankaverages) {
            $summaryrow = [];
            $summaryrow[0] = get_string('averagesrow', 'feedbackbox');
            for ($i = 1; $i < $nbinfocols; $i++) {
                $summaryrow[$i] = '';
            }
            for ($i = $nbinfocols; $i < $numrespcols; $i++) {
                $summaryrow[$i] = isset($averagerow[$i]) ? $averagerow[$i] : '';
            }
            $output[] = $summaryrow;
        }

        // Change table headers to incorporate actual question numbers.
        $numquestion = 0;
        $oldkey = 0;

        for ($i = $nbinfocols; $i < $numrespcols; $i++) {
            $thisoutput = current($output[0][$i]);
            $thiskey = key($output[0][$i]);
            // Case of unnamed rate single possible answer (full stop char is used for support).
            if (strstr($thisoutput, '->.')) {
                $thisoutput = str_replace('->.', '', $thisoutput);
            }
            // If variable is not named no separator needed between Question number and potential sub-variables.
            if ($thisoutput == '' || strstr($thisoutput, '->.') || substr($thisoutput, 0, 2) == '->'
                || substr($thisoutput, 0, 1) == '_') {
                $sep = '';
            } else {
                $sep = '_';
            }
            if ($thiskey > $oldkey) {
                $oldkey = $thiskey;
                $numquestion++;
            }
            // Abbreviated modality name in multiple or rate questions (COLORS->blue=the color of the sky...).
            $pos = strpos($thisoutput, '=');
            if ($pos) {
                $thisoutput = substr($thisoutput, 0, $pos);
            }
            $out = 'Q' . sprintf("%02d", $numquestion) . $sep . $thisoutput;
            $output[0][$i] = $out;
        }
        return $output;
    }

    /**
     * Function to move a question to a new position.
     * Adapted from feedback plugin.
     *
     * @param int $moveqid   The id of the question to be moved.
     * @param int $movetopos The position to move question to.
     * @return bool
     * @throws dml_exception
     */

    public function move_question($moveqid, $movetopos) {
        global $DB;

        $questions = $this->questions;
        $movequestion = $this->questions[$moveqid];

        if (is_array($questions)) {
            $index = 1;
            foreach ($questions as $question) {
                if ($index == $movetopos) {
                    $index++;
                }
                if ($question->id == $movequestion->id) {
                    $movequestion->position = $movetopos;
                    $DB->update_record("feedbackbox_question", $movequestion);
                    continue;
                }
                $question->position = $index;
                $DB->update_record("feedbackbox_question", $question);
                $index++;
            }
            return true;
        }
        return false;
    }

    // Mobile support area.

    /**
     * @param       $userid
     * @param       $sec
     * @param       $completed
     * @param       $submit
     * @param array $responses
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save_mobile_data($userid, $sec, $completed, $rid, $submit, $action, array $responses) {
        $ret = [];
        $response = $this->build_response_from_appdata($responses, $sec);
        $response->sec = $sec;
        $response->rid = $rid;
        $response->id = $rid;

        if ($action == 'nextpage') {
            $result = $this->next_page_action($response, $userid);
            if (is_string($result)) {
                $ret['warnings'] = $result;
            } else {
                $ret['nextpagenum'] = $result;
            }
        } else if ($action == 'previouspage') {
            $ret['nextpagenum'] = $this->previous_page_action($response, $userid);
        } else if (!$completed) {
            // If reviewing a completed feedbackbox, don't insert a response.
            $msg = $this->response_check_format($response->sec, $response);
            if (empty($msg)) {
                $rid = $this->response_insert($response, $userid);
            } else {
                $ret['warnings'] = $msg;
                $ret['response'] = $response;
            }
        }

        if ($submit && (!isset($ret['warnings']) || empty($ret['warnings']))) {
            $this->commit_submission_response($rid, $userid);
        }
        return $ret;
    }

    /**
     * @return array
     * @throws dml_exception
     */
    public function get_all_file_areas() {
        global $DB;

        $areas = [];
        $areas['info'] = $this->sid;
        $areas['thankbody'] = $this->sid;

        // Add question areas.
        if (empty($this->questions)) {
            $this->add_questions();
        }
        $areas['question'] = [];
        foreach ($this->questions as $question) {
            $areas['question'][] = $question->id;
        }

        // Add feedback areas.
        $areas['feedbacknotes'] = $this->sid;
        $fbsections = $DB->get_records('feedbackbox_fb_sections', ['surveyid' => $this->sid]);
        if (!empty($fbsections)) {
            $areas['sectionheading'] = [];
            foreach ($fbsections as $section) {
                $areas['sectionheading'][] = $section->id;
                $feedbacks = $DB->get_records('feedbackbox_feedback', ['sectionid' => $section->id]);
                if (!empty($feedbacks)) {
                    $areas['feedback'] = [];
                    foreach ($feedbacks as $feedback) {
                        $areas['feedback'][] = $feedback->id;
                    }
                }
            }
        }

        return $areas;
    }
}
