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
 * This file contains the parent class for radio question types.
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

namespace mod_feedbackbox\question;

use coding_exception;
use mod_feedbackbox\question\choice\choice;
use mod_feedbackbox\responsetype\response\response;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class radio extends question {

    public function helpname() {
        return 'radiobuttons';
    }

    /**
     * Return true if the question has choices.
     */
    public function has_choices() {
        return true;
    }

    /**
     * Override and return a form template if provided. Output of question_survey_display is iterpreted based on this.
     *
     * @return boolean | string
     */
    public function question_template() {
        return 'mod_feedbackbox/question_radio';
    }

    /**
     * Override and return a response template if provided. Output of response_survey_display is iterpreted based on this.
     *
     * @return boolean | string
     */
    public function response_template() {
        return 'mod_feedbackbox/response_radio';
    }

    /**
     * Override this and return true if the question type allows dependent questions.
     *
     * @return boolean
     */
    public function allows_dependents() {
        return true;
    }

    /**
     * Check question's form data for complete response.
     *
     * @param object $responsedata The data entered into the response.
     * @return boolean
     */
    public function response_complete($responsedata) {
        if (isset($responsedata->{'q' . $this->id}) && ($this->required()) &&
            (strpos($responsedata->{'q' . $this->id}, 'other_') !== false)) {
            return (trim($responsedata->{'q' . $this->id . '' . substr($responsedata->{'q' . $this->id}, 5)}) != false);
        } else {
            return parent::response_complete($responsedata);
        }
    }

    /**
     * True if question provides mobile support.
     *
     * @return bool
     */
    public function supports_mobile() {
        return false;
    }

    protected function responseclass() {
        return '\\mod_feedbackbox\\responsetype\\single';
    }

    /**
     * Return the context tags for the check question template.
     *
     * @param response $response
     * @param array    $dependants Array of all questions/choices depending on this question.
     * @param boolean  $blankfeedbackbox
     * @return object The check question context tags.
     * @throws coding_exception
     */
    protected function question_survey_display($response, $dependants = [], $blankfeedbackbox = false) {
        global $idcounter;  // To make sure all radio buttons have unique ids. // JR 20 NOV 2007.

        $otherempty = false;
        $horizontal = $this->length;
        $ischecked = false;

        $choicetags = new stdClass();
        $choicetags->qelements = [];
        $icons = ['b/fb_1_hyper', 'b/fb_2_laeuft', 'b/fb_3_okay', 'b/fb_4_hilfe'];
        $counter = 0;
        foreach ($this->choices as $id => $choice) {
            $radio = new stdClass();
            if ($horizontal) {
                $radio->horizontal = $horizontal;
            }
            if (!$choice->is_other_choice()) { // This is a normal radio button.
                $htmlid = 'auto-rb' . sprintf('%04d', ++$idcounter);
                $radio->name = 'q' . $this->id;
                $radio->id = $htmlid;
                $radio->value = $id;
                if (isset($response->answers[$this->id][$id])) {
                    $radio->checked = true;
                    $ischecked = true;
                }
                $value = '';
                if ($blankfeedbackbox) {
                    $radio->disabled = true;
                    $value = ' (' . $choice->value . ') ';
                }
                $contents = feedbackbox_choice_values($choice->content);
                $radio->label = $value . format_text($contents->text,
                        FORMAT_HTML,
                        ['noclean' => true]) . $contents->image;

                $radio->piximage = $icons[$counter++];
            } else {             // Radio button with associated !other text field.
                $othertext = $choice->other_choice_display();
                $odata = isset($response->answers[$this->id][$id]) ? $response->answers[$this->id][$id]->value : '';
                $htmlid = 'auto-rb' . sprintf('%04d', ++$idcounter);

                $radio->name = 'q' . $this->id;
                $radio->id = $htmlid;
                $radio->value = $id;
                if (isset($response->answers[$this->id][$id]) || !empty($odata)) {
                    $radio->checked = true;
                    $ischecked = true;
                }
                $otherempty = !empty($radio->checked) && empty($odata);
                $radio->label = format_text($othertext, FORMAT_HTML, ['noclean' => true]);
                $radio->oname = 'q' . $this->id . choice::id_other_choice_name($id);
                $radio->oid = $htmlid . '-other';
                if (isset($odata)) {
                    $radio->ovalue = stripslashes($odata);
                }
                $radio->olabel = 'Text for ' . format_text($othertext, FORMAT_HTML, ['noclean' => true]);

            }
            $choicetags->qelements[] = (object) ['choice' => $radio];
        }

        // CONTRIB-846.
        if (!$this->required()) {
            $radio = new stdClass();
            $htmlid = 'auto-rb' . sprintf('%04d', ++$idcounter);
            if ($horizontal) {
                $radio->horizontal = $horizontal;
            }

            $radio->name = 'q' . $this->id;
            $radio->id = $htmlid;
            $radio->value = 0;

            if (!$ischecked && !$blankfeedbackbox) {
                $radio->checked = true;
            }
            $content = get_string('noanswer', 'feedbackbox');
            $radio->label = format_text($content, FORMAT_HTML, ['noclean' => true]);

            $choicetags->qelements[] = (object) ['choice' => $radio];
        }
        // End CONTRIB-846.

        if ($otherempty) {
            $this->add_notification(get_string('otherempty', 'feedbackbox'));
        }
        return $choicetags;
    }

    /**
     * Return the context tags for the radio response template.
     *
     * @param response $response
     * @return object The radio question response context tags.
     */
    protected function response_survey_display($response) {
        static $uniquetag = 0;  // To make sure all radios have unique names.

        $resptags = new stdClass();
        $resptags->choices = [];

        $qdata = new stdClass();
        $horizontal = $this->length;
        if (isset($response->answers[$this->id])) {
            $answer = reset($response->answers[$this->id]);
            $checked = $answer->choiceid;
        } else {
            $checked = null;
        }
        foreach ($this->choices as $id => $choice) {
            $chobj = new stdClass();
            if ($horizontal) {
                $chobj->horizontal = 1;
            }
            $chobj->name = $id . $uniquetag++;
            $contents = feedbackbox_choice_values($choice->content);
            $choice->content = $contents->text . $contents->image;
            if ($id == $checked) {
                $chobj->selected = 1;
                if ($choice->is_other_choice()) {
                    $chobj->othercontent = $answer->value;
                }
            }
            if ($choice->is_other_choice()) {
                $chobj->content = $choice->other_choice_display();
            } else {
                $chobj->content = ($choice->content === '' ? $id : format_text($choice->content,
                    FORMAT_HTML,
                    ['noclean' => true]));
            }
            $resptags->choices[] = $chobj;
        }

        return $resptags;
    }
}