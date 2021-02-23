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
 * This file contains the parent class for text question types.
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

namespace mod_feedbackbox\question;

use dml_exception;
use mod_feedbackbox\responsetype\response\response;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class text extends question {

    /**
     * Constructor. Use to set any default properties.
     *
     * @param int   $id
     * @param null  $question
     * @param null  $context
     * @param array $params
     * @throws dml_exception
     */
    public function __construct($id = 0, $question = null, $context = null, $params = []) {
        $this->length = 20;
        $this->precise = 25;
        return parent::__construct($id, $question, $context, $params);
    }

    /**
     * @return string
     */
    public function helpname() {
        return 'textbox';
    }

    /**
     * Override and return a form template if provided. Output of question_survey_display is iterpreted based on this.
     *
     * @return boolean | string
     */
    public function question_template() {
        return 'mod_feedbackbox/question_text';
    }

    /**
     * Override and return a response template if provided. Output of response_survey_display is iterpreted based on this.
     *
     * @return boolean | string
     */
    public function response_template() {
        return 'mod_feedbackbox/response_text';
    }

    /**
     * True if question provides mobile support.
     *
     * @return bool
     */
    public function supports_mobile() {
        return false;
    }

    /**
     * @return object|string
     */
    protected function responseclass() {
        return '\\mod_feedbackbox\\responsetype\\text';
    }

    /**
     * Return the context tags for the check question template.
     *
     * @param response                                        $response
     * @param                                                 $descendantsdata
     * @param boolean                                         $blankfeedbackbox
     * @return object The check question context tags.
     */
    protected function question_survey_display($response, $descendantsdata, $blankfeedbackbox = false) {
        $questiontags = new stdClass();
        $questiontags->qelements = new stdClass();
        $choice = new stdClass();
        $choice->onkeypress = 'return event.keyCode != 13;';
        $choice->size = $this->length;
        $choice->name = 'q' . $this->id;
        if ($this->precise > 0) {
            $choice->maxlength = 10000;
        }
        $choice->value = (isset($response->answers[$this->id][0]) ? stripslashes($response->answers[$this->id][0]->value) : '');
        $choice->id = self::qtypename($this->type_id) . $this->id;
        $questiontags->qelements->choice = $choice;
        return $questiontags;
    }

    /**
     * Return the context tags for the text response template.
     *
     * @param $response
     * @return object The radio question response context tags.
     */
    protected function response_survey_display($response) {
        $resptags = new stdClass();
        if (isset($response->answers[$this->id])) {
            $answer = reset($response->answers[$this->id]);
            $resptags->content = format_text($answer->value, FORMAT_HTML);
        }
        return $resptags;
    }
}