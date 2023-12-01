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
use mod_feedbackbox\question\question;
use mod_feedbackbox\responsetype\multiple;
use mod_feedbackbox\responsetype\response\response;
use mod_feedbackbox\responsetype\single;
use mod_feedbackbox\responsetype\text;
use moodle_exception;
use moodle_url;
use pix_icon;
use plugin_renderer_base;
use popup_action;
use renderable;
use renderer_base;
use stdClass;
use templatable;

defined('MOODLE_INTERNAL') || die();

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
    public $opendate = null;
    public $closedate = null;
    public $turnus = null;
    public $resume = null;
    public $usehtmleditor = null;
    public $notifications = null;

    public $intro = null;
    public $introformat = null;
    public $strfeedbackboxs = '';
    public $strfeedbackbox = '';

    /**
     * feedbackbox constructor.
     *
     * @param      $id
     * @param      $feedbackbox
     * @param      $course
     * @param      $cm
     * @param bool $addquestions
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
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
     * @param $id
     * @param $response
     * @return string
     * @throws dml_exception
     */
    public static function encode_id($id, $response) {
        $secret = get_config('feedbackbox', 'secret');
        $ivlen = openssl_cipher_iv_length($cipher = "AES-128-CBC");
        $iv = openssl_random_pseudo_bytes($ivlen);
        $cipher_text_raw = openssl_encrypt($response . '|' . $id,
            'AES-128-CBC',
            $secret,
            OPENSSL_RAW_DATA,
            $iv);
        $hmac = hash_hmac('sha256', $cipher_text_raw, $secret, $as_binary = true);
        return base64_encode(gzdeflate($iv . $hmac . $cipher_text_raw));
    }

    /**
     * @param $idraw
     * @param $response
     * @return bool|int
     * @throws dml_exception
     */
    public static function decode_id($idraw, $response) {
        $secret = get_config('feedbackbox', 'secret');
        $c = gzinflate(base64_decode($idraw));
        $ivlen = openssl_cipher_iv_length("AES-128-CBC");
        $iv = substr($c, 0, $ivlen);
        $hmac = substr($c, $ivlen, $sha2len = 32);
        $ciphertext_raw = substr($c, $ivlen + $sha2len);
        $original_plaintext = openssl_decrypt($ciphertext_raw, 'AES-128-CBC', $secret, OPENSSL_RAW_DATA, $iv);
        $calcmac = hash_hmac('sha256', $ciphertext_raw, $secret, $as_binary = true);
        if (hash_equals($hmac, $calcmac)) {
            $parts = explode('|', $original_plaintext);
            if (intval($parts[0]) == $response) {
                return intval($parts[1]);
            }
        }
        return false;
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

        $sql = 'SELECT r.* ' .
            'FROM {feedbackbox_response} r ' .
            $groupsql .
            'WHERE r.feedbackboxid = :feedbackboxid AND r.complete = :status' . $groupcnd;
        $params['feedbackboxid'] = $this->id;
        $params['status'] = 'y';
        if ($userid) {
            $sql .= ' AND r.userid = :userid';
            $params['userid'] = $userid;
        }

        $sql .= ' ORDER BY r.id';
        return $DB->get_records_sql($sql, $params);
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
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function view() {
        global $USER, $PAGE, $DB;

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
                ($viewform->submittype == "Submit Survey") && empty($msg)) { // Todo: translate
                if (!empty($viewform->rid)) {
                    $viewform->rid = (int) $viewform->rid;
                }
                if (!empty($viewform->sec)) {
                    $viewform->sec = (int) $viewform->sec;
                }
                $this->response_delete($viewform->rid, $viewform->sec);
                $this->rid = $this->response_insert($viewform, $quser);
                $this->response_commit($this->rid);

                // Update completion state.
                $completion = new completion_info($this->course);
                if ($completion->is_enabled($this->cm) && $this->completionsubmit) {
                    $completion->update_state($this->cm, COMPLETION_COMPLETE);
                }

                $turnus = $this->get_current_turnus();
                $records = array_values($DB->get_records_sql(
                    'SELECT * FROM {feedbackbox_response} WHERE ' .
                    'feedbackboxid=? AND submitted > ? AND submitted < ? AND complete=\'y\'',
                    [$this->id, $turnus->from, $turnus->to]));
                $orgrecords = $records;
                shuffle($records);
                for ($i = 0; $i < count($orgrecords); $i++) {
                    $obj = clone $records[$i];
                    $obj->userid = $orgrecords[$i]->userid;
                    $DB->update_record('feedbackbox_response', $obj);
                }

                $this->response_goto_thankyou();
            }
        }
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
            $msg = 'removenotinuse';
            $message = get_string($msg, 'feedbackbox');
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

    public function is_active() {
        return (!empty($this->survey));
    }

    public function is_open() {
        return ($this->opendate > 0) ? ($this->opendate < time()) : true;
    }

    // Access Methods.

    public function is_closed() {
        return ($this->closedate > 0) ? ($this->closedate < time()) : false;
    }

    public function user_is_eligible() {
        return ($this->capabilities->view && $this->capabilities->submit);
    }

    /**
     * @param $userid
     * @return bool
     * @throws dml_exception
     */
    public function user_can_take($userid) {
        if (!$this->is_active() || !$this->user_is_eligible()) {
            return false;
        } else if ($userid > 0) {
            return $this->user_time_for_new_attempt($userid);
        } else {
            return false;
        }
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
                $controlbuttons['submittype'] = ['type' => 'hidden', 'value' => 'Submit Survey']; // Todo: translate
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

        foreach (['resp_single', 'resp_multiple', 'response_text'] as $tbl) {
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

    /** @noinspection PhpUnusedPrivateMethodInspection */

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
        // If no questions autonumbering do not display missing question(s) number(s).
        if ($checkmissing && $missing) {
            if ($missing == 1) {
                $message = get_string('missingquestion', 'feedbackbox');
            }
            if ($wrongformat) {
                $message .= '<br />';
            }
        }
        if ($checkwrongformat && $wrongformat) {
            if ($wrongformat == 1) {
                $message .= get_string('wrongformat', 'feedbackbox') . $strwrongformat;
            } else {
                $message .= get_string('wrongformats', 'feedbackbox') . $strwrongformat;
            }
        }
        return ($message);
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
                [$qsql, $params] = $DB->get_in_or_equal($qids);
                $qsql = ' AND question_id ' . $qsql;
            }

        } else {
            /* delete all */
            $qsql = '';
            $params = [];
        }
        array_unshift($params, $rid);
        /* delete values */
        $select = 'response_id = ? ' . $qsql;
        foreach (['resp_single', 'response_text', 'resp_multiple'] as $tbl) {
            $DB->delete_records_select('feedbackbox_' . $tbl, $select, $params);
        }
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
     * Determine the next valid page and return it. Return false if no valid next page.
     *
     * @param $secnum
     * @param $rid
     * @return int | bool
     */
    public function next_page($secnum, $rid) {
        $secnum++;
        $numsections = isset($this->questionsbysec) ? count($this->questionsbysec) : 0;
        return $secnum;
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
        return $this->prev_page($response->sec);
    }

    /**
     * @param $secnum
     * @return int | bool
     */
    public function prev_page($secnum) {
        $secnum--;
        if ($secnum === 0) {
            $secnum = false;
        }
        return $secnum;
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
        global $CFG;
        require_once($CFG->libdir . '/filelib.php');
        $groupname = '';
        $timesubmitted = '';
        $ruser = '';
        if ($ruser) {
            $respinfo = '';
            if ($outputtarget == 'html') {
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
            $respinfo .= $groupname;
            $respinfo .= $timesubmitted;
            $this->page->add_to_page('respondentinfo', $this->renderer->respondent_info($respinfo));
        }

        if ($section == 1) {
            if (!empty($this->survey->title)) {
                $this->survey->title = format_string($this->survey->title);
                $this->page->add_to_page('title', $this->survey->title);
            }
        }

        if ($message) {
            $this->page->add_to_page('message',
                $this->renderer->notification($message, notification::NOTIFY_ERROR));
        }
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

    private function print_survey_end($section, $numsections) {

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
        return $DB->update_record('feedbackbox_response', $record);
    }

    public function get_current_turnus($time = null) {
        if ($time === null) {
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

    /**
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpPossiblePolymorphicInvocationInspection
     */
    private function response_goto_thankyou() {
        $this->page->add_to_page('title', get_string('check_feedbackbox', 'mod_feedbackbox'));
        $this->page->add_to_page('addinfo',
            ($this->renderer->pix_icon('b/fb_danke', 'Icon', 'mod_feedbackbox')) .
            get_string('check_feedbackbox_thanks', 'mod_feedbackbox'));
        // Default set currentgroup to view all participants.
        // TODO why not set to current respondent's groupid (if any)?
        $url = new moodle_url('/course/view.php', ['id' => $this->course->id]);
        $this->page->add_to_page('continue', $this->renderer->single_button($url, get_string('continue')));
        return;
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
     * Function to view all loaded responses.
     *
     * @noinspection PhpUnused
     */
    public function view_all_responses() {
        /*
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
        */
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

        $sql = 'SELECT COUNT(r.id) ' .
            'FROM {feedbackbox_response} r ' .
            $groupsql .
            'WHERE r.feedbackboxid = :feedbackboxid AND r.complete = :status' . $groupcnd;
        $params['feedbackboxid'] = $this->id;
        $params['status'] = 'y';
        if ($userid) {
            $sql .= ' AND r.userid = :userid';
            $params['userid'] = $userid;
        }
        return $DB->count_records_sql($sql, $params);
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
     * Important to remain because its needed from the block
     * block_feedbackbox
     *
     * @return bool|mixed|stdClass
     * @noinspection PhpUnused
     */
    public function get_last_turnus() {
        $data = array_reverse($this->get_turnus_zones());
        foreach ($data as $entry) {
            if ($this->turnus != 1 && $entry->to < time()) {
                $entry->fromstr = date('d.m.Y', $entry->from);
                $entry->tostr = date('d.m.Y', $entry->to);
                return $entry;
            }
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

    /**
     * Used from feedbackbox block
     *
     * @param $zone
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    public function get_feedback_responses_block($zone) {
        global $DB;
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
        $rankinglist = array_column($ranking, 'id');
        foreach ($radiochoise as $choise) {
            $result->rating[array_search($choise->choice_id, $rankinglist)] = $choise->result;
        }
        $result->ratinglabel = array_reverse(array_column($ranking, 'content'));

        $positions = [4, 5, 6, 7, 8];
        [$insql, $inparams] = $DB->get_in_or_equal($positions);
        $top3 = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND q.type_id = ? AND q.position ' . $insql .
            ' AND r.submitted > ? AND r.submitted < ? GROUP BY qc.content ORDER BY result DESC LIMIT 3',
            array_merge([$this->id, QUESCHECK], $inparams, [$zone->from, $zone->to]));
        $counter = 1;
        foreach ($top3 as $entry) {
            unset($entry->result);
            $entry->name = translate::patch($entry->name);
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
            $entry->name = translate::patch($entry->name);
            $entry->id = $counter++;
        }
        $result->bad = array_values($top3);
        return $result;
    }

    /**
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_feedback_responses() {
        global $DB;
        $result = new stdClass();
        $zones = $this->get_turnus_zones();
        $result->totalparticipants = count(get_enrolled_users(context_course::instance($this->course->id)));
        if (intval($DB->get_field_sql('SELECT count(*) FROM {feedbackbox_response}' .
                ' WHERE feedbackboxid=? AND complete=\'y\'',
                [$this->id])) < 3) {
            $result->zones = $zones;
            $result->good = [];
            $result->bad = [];
            $result->tolessresults = true;
            return $result;
        }


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
        $positions = [4, 5, 6, 7, 8];
        [$insql, $inparams] = $DB->get_in_or_equal($positions);
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
            $entry->name = translate::patch($entry->name);
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
            $entry->name = translate::patch($entry->name);
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
        global $DB;
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

        $ranking = $DB->get_records_sql('SELECT qc.id as "id", qc.content as "content"
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
        $rankinglist = array_column($ranking, 'id');
        foreach ($radiochoise as $choise) {
            if ($result->participants < 3) {
                $result->rating[array_search($choise->choice_id, $rankinglist)] = 0;
            } else {
                $result->rating[array_search($choise->choice_id, $rankinglist)] = $choise->result;
            }

        }
        $result->ratinglabel = array_reverse(array_column($ranking, 'content'));
        if ($result->participants < 3) {
            $result->goodchoises = [];
            $result->badchoises = [];
            $result->goodmessages = [];
            $result->badmessages = [];
            $result->tolessresults = true;
            return $result;
        }

        $positions = [4, 5, 6, 7, 8];
        [$insql, $inparams] = $DB->get_in_or_equal($positions);
        $good = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position ' .
            $insql . ' GROUP BY qc.content ORDER BY result DESC',
            array_merge([$this->id, $zone->from, $zone->to, QUESCHECK], $inparams));
        $result->goodchoises = array_values($good);
        foreach ($result->goodchoises as $goodchoise) {
            $goodchoise->name = translate::patch($goodchoise->name);
        }
        [$insql, $inparams] = $DB->get_in_or_equal($positions);
        $bad = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
            ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
            ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
            ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
            ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND NOT q.position ' .
            $insql . ' GROUP BY qc.content ORDER BY result DESC',
            array_merge([$this->id, $zone->from, $zone->to, QUESCHECK], $inparams));
        $result->badchoises = array_values($bad);
        foreach ($result->badchoises as $badchoise) {
            $badchoise->name = translate::patch($badchoise->name);
        }

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

    public function generate_csv() {
        global $DB;
        $head = get_string('csv_headingone', 'mod_feedbackbox');
        $head .= get_string('csv_headingtwo', 'mod_feedbackbox');
        $body = '';
        $zones = $this->get_turnus_zones();
        foreach ($zones as $zone) {
            $line = get_string('round', 'mod_feedbackbox') . ' ' . $zone->id . ';' . date('d.m.Y',
                    $zone->from) . ';' . date('d.m.Y', $zone->to) . ';';

            $ranking = $DB->get_records_sql('SELECT qc.id as "id", qc.content as "content"
            FROM {feedbackbox_quest_choice} qc
            JOIN {feedbackbox_question} q ON qc.question_id = q.id WHERE q.surveyid = ? AND type_id = ?',
                [$this->survey->id, QUESRADIO]);

            $participants = intval($DB->get_field_sql(
                'SELECT count(*) FROM {feedbackbox_response}
            WHERE feedbackboxid=? AND submitted > ? AND submitted < ? AND complete=\'y\'',
                [$this->id, $zone->from, $zone->to]));

            $totalparticipants = count(get_enrolled_users(context_course::instance($this->course->id)));

            $line .= $participants . ';' . $totalparticipants . ';';

            $radiochoise = $DB->get_records_sql(
                'SELECT choice_id, COUNT(*) as "result" FROM {feedbackbox_resp_single} rs' .
                ' JOIN {feedbackbox_response} r ON rs.response_id = r.id AND r.complete=\'y\'' .
                ' WHERE r.feedbackboxid=? AND r.submitted > ? AND r.submitted < ? GROUP BY rs.choice_id',
                [$this->id, $zone->from, $zone->to]);

            $rating = [0, 0, 0, 0];
            $rankinglist = array_column($ranking, 'id');
            foreach ($radiochoise as $choise) {
                if ($participants < 3) {
                    $rating[array_search($choise->choice_id, $rankinglist)] = 0;
                } else {
                    $rating[array_search($choise->choice_id, $rankinglist)] = $choise->result;
                }
            }
            foreach ($rating as $rate) {
                $line .= $rate . ';';
            }

            if ($participants < 3) {
                $line .= ';;;';
                $body .= $line . "\n";
                continue;
            }

            $positions = [4, 5, 6, 7, 8];
            [$insql, $inparams] = $DB->get_in_or_equal($positions);
            $good = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
                ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
                ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
                ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
                ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position ' .
                $insql . ' GROUP BY qc.content ORDER BY result DESC',
                array_merge([$this->id, $zone->from, $zone->to, QUESCHECK], $inparams));
            $goodchoises = array_values($good);

            [$insql, $inparams] = $DB->get_in_or_equal($positions);
            $bad = $DB->get_records_sql('SELECT qc.content as "name", COUNT(*) as "result" FROM {feedbackbox_response} r' .
                ' JOIN {feedbackbox_resp_multiple} rm ON rm.response_id = r.id AND r.complete=\'y\'' .
                ' JOIN {feedbackbox_question} q ON rm.question_id = q.id' .
                ' JOIN {feedbackbox_quest_choice} qc ON rm.choice_id = qc.id' .
                ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND NOT q.position ' .
                $insql . ' GROUP BY qc.content ORDER BY result DESC',
                array_merge([$this->id, $zone->from, $zone->to, QUESCHECK], $inparams));
            $badchoises = array_values($bad);

            $goodmessages = array_values($DB->get_fieldset_sql('SELECT rt.response FROM {feedbackbox_response} r' .
                ' JOIN {feedbackbox_response_text} rt ON rt.response_id = r.id AND r.complete=\'y\'' .
                ' JOIN {feedbackbox_question} q ON rt.question_id = q.id' .
                ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position = ?',
                [$this->id, $zone->from, $zone->to, QUESESSAY, 9]));
            $badmessages = array_values($DB->get_fieldset_sql('SELECT rt.response FROM {feedbackbox_response} r' .
                ' JOIN {feedbackbox_response_text} rt ON rt.response_id = r.id AND r.complete=\'y\'' .
                ' JOIN {feedbackbox_question} q ON rt.question_id = q.id' .
                ' WHERE r.feedbackboxid = ? AND r.submitted > ? AND r.submitted < ? AND q.type_id = ? AND q.position = ?',
                [$this->id, $zone->from, $zone->to, QUESESSAY, 17]));
            foreach ($goodchoises as $gc) {
                $line .= translate::patch($gc->name) . '(' . $gc->result . '), ';
            }
            $line = rtrim($line, ', ') . ';"';
            foreach ($goodmessages as $gm) {
                $line .= '„' . str_replace(';', '', $gm) . '“, ';
            }
            $line = rtrim($line, ', ') . '";';

            foreach ($badchoises as $bc) {
                $line .= translate::patch($bc->name) . '(' . $bc->result . '), ';
            }
            $line = rtrim($line, ', ') . ';"';
            foreach ($badmessages as $bm) {
                $line .= '„' . str_replace(';', '', $bm) . '“, ';
            }
            $line = rtrim($line, ', ') . '"';
            $body .= $line . "\n";
        }
        return $head . $body;
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

        if ($notifyusers = $this->get_notifiable_users($USER->id)) {
            $info = new stdClass();
            // Need to handle user differently for anonymous surveys.
            $info->userfrom = core_user::get_noreply_user();
            $info->username = '';
            $info->profileurl = '';
            $langstringtext = 'submissionnotificationtextanon';
            $langstringhtml = 'submissionnotificationhtmlanon';
            $info->name = format_string($this->name);

            $info->submissionurl = (new moodle_url('/mod/feedbackbox/report.php',
                ['action' => 'vresp', 'sid' => $this->survey->id, 'rid' => $rid, 'instance' => $this->id]))->out(false);
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

        return true;
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
}
