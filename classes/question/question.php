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

namespace mod_feedbackbox\question;

use coding_exception;
use context;
use dml_exception;
use html_writer;
use mod_feedbackbox\responsetype\response\response;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * This file contains the parent class for feedbackbox question types.
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

/**
 * Class for describing a question
 *
 * @author  Mike Churchward
 * @package questiontypes
 */

// Constants.
define('QUESCHOOSE', 0);
define('QUESYESNO', 1);
define('QUESTEXT', 2);
define('QUESESSAY', 3);
define('QUESRADIO', 4);
define('QUESCHECK', 5);
define('QUESDROP', 6);
define('QUESRATE', 8);
define('QUESDATE', 9);
define('QUESNUMERIC', 10);
define('QUESPAGEBREAK', 99);
define('QUESSECTIONTEXT', 100);

global $idcounter, $CFG;
$idcounter = 0;

require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

abstract class question {

    // Class Properties.
    /** @var array $qtypenames List of all question names. */
    private static $qtypenames = [
        QUESYESNO => 'yesno',
        QUESTEXT => 'text',
        QUESESSAY => 'essay',
        QUESRADIO => 'radio',
        QUESCHECK => 'check',
        QUESDROP => 'drop',
        QUESRATE => 'rate',
        QUESDATE => 'date',
        QUESNUMERIC => 'numerical',
        QUESPAGEBREAK => 'pagebreak',
        QUESSECTIONTEXT => 'sectiontext'
    ];
    /** @var int $id The database id of this question. */
    public $id = 0;
    /** @var int $surveyid The database id of the survey this question belongs to. */
    public $surveyid = 0;
    /** @var string $name The name of this question. */
    public $name = '';
    /** @var string $type The name of the question type. */
    public $type = '';
    /** @var array $choices Array holding any choices for this question. */
    public $choices = [];
    /** @var array $dependencies Array holding any dependencies for this question. */
    public $dependencies = [];
    /** @var string $responsetable The table name for responses. */
    public $responsetable = '';
    /** @var int $length The length field. */
    public $length = 0;
    /** @var int $precise The precision field. */
    public $precise = 0;
    /** @var int $position Position in the feedbackbox */
    public $position = 0;
    /** @var string $content The question's content. */
    public $content = '';
    /** @var boolean $required The required flag. */
    public $required = 'n';
    /** @var boolean $deleted The deleted flag. */
    public $deleted = 'n';
    /** @var array $notifications Array of extra messages for display purposes. */
    private $notifications = [];

    private $context = null;

    private $qid = 0;
    /**
     * The class constructor
     *
     * @param int   $id
     * @param null  $question
     * @param context  $context
     * @param array $params
     * @throws dml_exception
     */
    public function __construct($id = 0, $question = null, $context = null, $params = []) {
        global $DB;
        static $qtypes = null;

        if ($qtypes === null) {
            $qtypes = $DB->get_records('feedbackbox_question_type',
                [],
                'typeid',
                'typeid, type, has_choices, response_table');
        }

        if ($id) {
            $question = $DB->get_record('feedbackbox_question', ['id' => $id]);
        }

        if (is_object($question)) {
            $this->id = $question->id;
            $this->surveyid = $question->surveyid;
            $this->name = $question->name;
            $this->length = $question->length;
            $this->precise = $question->precise;
            $this->position = $question->position;
            $this->content = $question->content;
            $this->required = $question->required;
            $this->deleted = $question->deleted;

            $this->type_id = $question->type_id;
            $this->type = $qtypes[$this->type_id]->type;
            $this->responsetable = $qtypes[$this->type_id]->response_table;

            if (!empty($question->choices)) {
                $this->choices = $question->choices;
            } else if ($qtypes[$this->type_id]->has_choices == 'y') {
                $this->get_choices();
            }
        }
        $this->context = $context;

        foreach ($params as $property => $value) {
            $this->$property = $value;
        }

        if ($respclass = $this->responseclass()) {
            $this->responsetype = new $respclass($this);
        }
    }

    /**
     * @throws dml_exception
     */
    private function get_choices() {
        global $DB;

        if ($choices = $DB->get_records('feedbackbox_quest_choice', ['question_id' => $this->id], 'id ASC')) {
            foreach ($choices as $choice) {
                $this->choices[$choice->id] = choice\choice::create_from_data($choice);
            }
        } else {
            $this->choices = [];
        }
    }

    /**
     * Each question type must define its response class.
     *
     * @return object The response object based off of feedbackbox_response_base.
     *
     */
    abstract protected function responseclass();

    /**
     * Build a question from data.
     *
     * @return array question object.
     * @var int|array|object $qdata   Either the id of the record, or a structure containing the question data, or null.
     * @var object           $context The context for the question.
     * @var int              $qtype   The question type code.
     */
    static public function question_builder($qtype, $qdata = null, $context = null) {
        $qclassname = '\\mod_feedbackbox\\question\\' . self::qtypename($qtype);
        $qid = 0;
        if (!empty($qdata) && is_array($qdata)) {
            $qdata = (object) $qdata;
        } else if (!empty($qdata) && is_int($qdata)) {
            $qid = $qdata;
        }
        return new $qclassname($qid, $qdata, $context, ['type_id' => $qtype]);
    }

    /**
     * Return the different question type names.
     *
     * @param $qtype
     * @return string
     */
    static public function qtypename($qtype) {
        if (array_key_exists($qtype, self::$qtypenames)) {
            return self::$qtypenames[$qtype];
        } else {
            return ('');
        }
    }

    /**
     * Return all of the different question type names.
     *
     * @return array
     */
    static public function qtypenames() {
        return self::$qtypenames;
    }

    /**
     * Short name for this question type - no spaces, etc..
     *
     * @return string
     */
    abstract public function helpname();

    /**
     * Override this and return true if the question type allows dependent questions.
     *
     * @return boolean
     */
    public function allows_dependents() {
        return false;
    }

    /**
     * Insert response data method.
     *
     * @param object $responsedata All of the responsedata.
     * @return bool
     */
    public function insert_response($responsedata) {
        if (isset($this->responsetype) && is_object($this->responsetype) &&
            is_subclass_of($this->responsetype, '\\mod_feedbackbox\\responsetype\\responsetype')) {
            return $this->responsetype->insert_response($responsedata);
        } else {
            return false;
        }
    }

    /**
     * Get results data method.
     *
     * @param bool $rids
     * @return bool
     */
    public function get_results($rids = false) {
        if (isset ($this->responsetype) && is_object($this->responsetype) &&
            is_subclass_of($this->responsetype, '\\mod_feedbackbox\\responsetype\\responsetype')) {
            return $this->responsetype->get_results($rids);
        } else {
            return false;
        }
    }

    /**
     * Add a notification.
     *
     * @param string $message
     */
    public function add_notification($message) {
        $this->notifications[] = $message;
    }

    /**
     * Get any notifications.
     *
     * @return array | boolean The notifications array or false.
     */
    public function get_notifications() {
        if (empty($this->notifications)) {
            return false;
        } else {
            return $this->notifications;
        }
    }

    /**
     * True if question type allows responses.
     */
    public function supports_responses() {
        return !empty($this->responseclass());
    }

    /**
     * Check question's form data for complete response.
     *
     * @param object $responsedata The data entered into the response.
     * @return boolean
     */
    public function response_complete($responsedata) {
        if (is_a($responsedata, 'mod_feedbackbox\responsetype\response\response')) {
            // If $responsedata is a response object, look through the answers.
            if (isset($responsedata->answers[$this->id]) && !empty($responsedata->answers[$this->id])) {
                $answer = $responsedata->answers[$this->id][0];
                if (!empty($answer->choiceid) && isset($this->choices[$answer->choiceid]) &&
                    $this->choices[$answer->choiceid]->is_other_choice()) {
                    $answered = !empty($answer->value);
                } else {
                    $answered = (!empty($answer->choiceid) || !empty($answer->value));
                }
            } else {
                $answered = false;
            }
        } else {
            // If $responsedata is webform data, check that its not empty.
            $answered = isset($responsedata->{'q' . $this->id}) && ($responsedata->{'q' . $this->id} != '');
        }
        return !($this->required() && ($this->deleted == 'n') && !$answered);
    }

    /**
     * Return true if this question has been marked as required.
     *
     * @return boolean
     */
    public function required() {
        return ($this->required == 'y');
    }

    /**
     * Check question's form data for valid response. Override this if type has specific format requirements.
     *
     * @param object $responsedata The data entered into the response.
     * @return boolean
     */
    public function response_valid($responsedata) {
        return true;
    }

    /**
     * Update data record from object or optional question data.
     *
     * @param object  $questionrecord An object with all updated question record data.
     * @param boolean $updatechoices  True if choices should also be updated.
     * @throws dml_exception
     */
    public function update($questionrecord = null, $updatechoices = true) {
        global $DB;

        if ($questionrecord === null) {
            $questionrecord = new stdClass();
            $questionrecord->id = $this->id;
            $questionrecord->surveyid = $this->surveyid;
            $questionrecord->name = $this->name;
            $questionrecord->type_id = $this->type_id;
            $questionrecord->length = $this->length;
            $questionrecord->precise = $this->precise;
            $questionrecord->position = $this->position;
            $questionrecord->content = $this->content;
            $questionrecord->required = $this->required;
            $questionrecord->deleted = $this->deleted;
        } else {
            // Make sure the "id" field is this question's.
            if (isset($this->qid) && ($this->qid > 0)) {
                $questionrecord->id = $this->qid;
            } else {
                $questionrecord->id = $this->id;
            }
        }
        $DB->update_record('feedbackbox_question', $questionrecord);

        if ($updatechoices && $this->has_choices()) {
            $this->update_choices();
        }
    }

    /**
     * Override and return true if the question has choices.
     */
    public function has_choices() {
        return false;
    }

    /**
     * @return bool
     * @throws dml_exception
     */
    public function update_choices() {
        $retvalue = true;
        if ($this->has_choices() && isset($this->choices)) {
            // Need to fix this messed-up qid/id issue.
            if (isset($this->qid) && ($this->qid > 0)) {
                $qid = $this->qid;
            } else {
                $qid = $this->id;
            }
            foreach ($this->choices as $key => $choice) {
                $choicerecord = new stdClass();
                $choicerecord->id = $key;
                $choicerecord->question_id = $qid;
                $choicerecord->content = $choice->content;
                $choicerecord->value = $choice->value;
                $retvalue &= $this->update_choice($choicerecord);
            }
        }
        return $retvalue;
    }

    /**
     * @param $choicerecord
     * @return bool
     * @throws dml_exception
     */
    public function update_choice($choicerecord) {
        global $DB;
        return $DB->update_record('feedbackbox_quest_choice', $choicerecord);
    }

    /**
     * Add the question to the database from supplied arguments.
     *
     * @param object  $questionrecord The required data for adding the question.
     * @param array   $choicerecords  An array of choice records with 'content' and 'value' properties.
     * @param boolean $calcposition   Whether or not to calculate the next available position in the survey.
     * @throws dml_exception
     */
    public function add($questionrecord, array $choicerecords = null, $calcposition = true) {
        global $DB;

        // Create new question.
        if ($calcposition) {
            // Set the position to the end.
            $sql = 'SELECT MAX(position) as maxpos ' .
                'FROM {feedbackbox_question} ' .
                'WHERE surveyid = ? AND deleted = ?';
            $params = ['surveyid' => $questionrecord->surveyid, 'deleted' => 'n'];
            if ($record = $DB->get_record_sql($sql, $params)) {
                $questionrecord->position = $record->maxpos + 1;
            } else {
                $questionrecord->position = 1;
            }
        }

        // Make sure we add all necessary data.
        if (!isset($questionrecord->type_id) || empty($questionrecord->type_id)) {
            $questionrecord->type_id = $this->type_id;
        }

        $this->qid = $DB->insert_record('feedbackbox_question', $questionrecord);

        if ($this->has_choices() && !empty($choicerecords)) {
            foreach ($choicerecords as $choicerecord) {
                $choicerecord->question_id = $this->qid;
                $this->add_choice($choicerecord);
            }
        }
    }

    /**
     * @param $choicerecord
     * @return bool
     * @throws dml_exception
     */
    public function add_choice($choicerecord) {
        global $DB;
        $retvalue = true;
        if ($cid = $DB->insert_record('feedbackbox_quest_choice', $choicerecord)) {
            $this->choices[$cid] = new stdClass();
            $this->choices[$cid]->content = $choicerecord->content;
            $this->choices[$cid]->value = isset($choicerecord->value) ? $choicerecord->value : null;
        } else {
            $retvalue = false;
        }
        return $retvalue;
    }

    /**
     * Override and return a form template if provided. Output of question_survey_display is interpreted based on this.
     *
     * @return boolean | string
     */
    public function question_template() {
        return false;
    }

    /**
     * Override and return a form template if provided. Output of response_survey_display is interpreted based on this.
     *
     * @return boolean | string
     */
    public function response_template() {
        return false;
    }

    /**
     * Get the output for question renderers / templates.
     *
     * @param response $response
     * @param array    $dependants Array of all questions/choices depending on this question.
     * @param string   $qnum
     * @param boolean  $blankfeedbackbox
     * @return stdClass
     * @throws coding_exception
     */
    public function question_output($response, $dependants, $qnum, $blankfeedbackbox) {
        $pagetags = $this->questionstart_survey_display($qnum, $response);
        $pagetags->qformelement = $this->question_survey_display($response, $dependants, $blankfeedbackbox);
        return $pagetags;
    }

    /**
     * Get the output for the start of the questions in a survey.
     *
     * @param integer        $qnum
     * @param response|array $response
     * @return stdClass
     * @throws coding_exception
     */
    public function questionstart_survey_display($qnum, $response = null) {
        global $OUTPUT, $PAGE;

        $pagetags = new stdClass();
        $pagetype = $PAGE->pagetype;
        $skippedclass = '';
        // If no questions autonumbering.
        $nonumbering = false;

        // For now, check what the response type is until we've got it all refactored.
        if ($response instanceof response) {
            $skippedquestion = !isset($response->answers[$this->id]);
        } else {
            $skippedquestion = !empty($response) && !array_key_exists('q' . $this->id, $response);
        }

        // If we are on report page and this feedbackbox has dependquestions and this question was skipped.
        if (($pagetype == 'mod-feedbackbox-myreport' || $pagetype == 'mod-feedbackbox-report') &&
            ($nonumbering == false) && !empty($this->dependencies) && $skippedquestion) {
            $skippedclass = ' unselected';
            $qnum = '<span class="' . $skippedclass . '">(' . $qnum . ')</span>';
        }
        // In preview mode, hide children questions that have not been answered.
        // In report mode, If feedbackbox is set to no numbering,
        // also hide answers to questions that have not been answered.
        $displayclass = 'qn-container';

        $pagetags->fieldset = (object) ['id' => $this->id, 'class' => $displayclass];

        // Do not display the info box for the label question type.
        if ($this->type_id != QUESSECTIONTEXT) {
            if (!$nonumbering) {
                $pagetags->qnum = $qnum;
            }
            $required = '';
            if ($this->required()) {
                $required = html_writer::start_tag('div', ['class' => 'accesshide']);
                $required .= get_string('required', 'feedbackbox');
                $required .= html_writer::end_tag('div');
                $required .= html_writer::empty_tag('img',
                    ['class' => 'req', 'title' => get_string('required', 'feedbackbox'),
                        'alt' => get_string('required', 'feedbackbox'), 'src' => $OUTPUT->image_url('req')]);
            }
            $pagetags->required = $required; // Need to replace this with better renderer / template?
        }
        // If question text is "empty", i.e. 2 non-breaking spaces were inserted, empty it.
        if ($this->content == '<p>  </p>') {
            $this->content = '';
        }
        $pagetags->skippedclass = $skippedclass;
        if ($this->type_id == QUESNUMERIC || $this->type_id == QUESTEXT) {
            $pagetags->label = (object) ['for' => self::qtypename($this->type_id) . $this->id];
        } else if ($this->type_id == QUESDROP) {
            $pagetags->label = (object) ['for' => self::qtypename($this->type_id) . $this->name];
        } else if ($this->type_id == QUESESSAY) {
            $pagetags->label = (object) ['for' => 'edit-q' . $this->id];
        }
        $options = ['noclean' => true, 'para' => false, 'filter' => true, 'context' => $this->context, 'overflowdiv' => true];
        $content = format_text(file_rewrite_pluginfile_urls($this->content,
            'pluginfile.php',
            $this->context->id,
            'mod_feedbackbox',
            'question',
            $this->id),
            FORMAT_HTML,
            $options);
        $pagetags->qcontent = $content;

        return $pagetags;
    }

    /**
     * Question specific display method.
     *
     * @param object  $formdata
     * @param         $descendantsdata
     * @param boolean $blankfeedbackbox
     */
    abstract protected function question_survey_display($formdata, $descendantsdata, $blankfeedbackbox);

    /**
     * True if question provides mobile support.
     *
     * @return bool
     */
    public function supports_mobile() {
        return false;
    }

    /**
     * Question specific response display method.
     *
     * @param object $data
     */
    abstract protected function response_survey_display($data);
}