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
 * This file contains the parent class for essay question types.
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

namespace mod_feedbackbox\question;
defined('MOODLE_INTERNAL') || die();

use html_writer;
use mod_feedbackbox\responsetype\response\response;

class essay extends text {

    /**
     * @return string
     */
    public function helpname() {
        return 'essaybox';
    }

    /**
     * Override and return a form template if provided. Output of question_survey_display is iterpreted based on this.
     *
     * @return boolean | string
     */
    public function question_template() {
        return false;
    }

    /**
     * Override and return a response template if provided. Output of response_survey_display is iterpreted based on this.
     *
     * @return boolean | string
     */
    public function response_template() {
        return false;
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
     * @param response                                        $response
     * @param                                                 $descendantsdata
     * @param bool                                            $blankfeedbackbox
     * @return object|string
     */
    protected function question_survey_display($response, $descendantsdata, $blankfeedbackbox = false) {
        $output = '';

        // Essay.
        // Columns and rows default values.
        $cols = 80;
        $rows = 7;
        // Use HTML editor or not?
        if ($this->precise == 0) {
            $canusehtmleditor = true;
            $rows = $this->length == 0 ? $rows : $this->length;
        } else {
            $canusehtmleditor = false;
            // Prior to version 2.6, "precise" was used for rows number.
            $rows = $this->precise > 1 ? $this->precise : $this->length;
        }
        $name = 'q' . $this->id;
        if (isset($response->answers[$this->id][0])) {
            $value = $response->answers[$this->id][0]->value;
        } else {
            $value = '';
        }
        $texteditor = html_writer::tag('textarea',
            $value,
            ['id' => $name, 'name' => $name, 'rows' => $rows, 'cols' => $cols]);

        // If the position equals to 9 its a good text request.
        if ($this->position == 9) {
            $output .= '<div class="infotext"><p><b>Erläutere deine Auswahl an Tags im Freitextfeld.</b><br/>
Dieser Teil des Feedbacks ist optional.<br/>
Es muss keine Doktorarbeit werden, aber versuche deine Auswahl zu begründen, damit dein<br/>
Feedback richtig verstanden wird. Damit dieses auch gern angenommen wird, formuliere den<br/>
Text bitte respektvoll und konstruktiv. Und vergiss nicht: Jede*r freut sich über lobende Worte!
</p></div><br/>';
        } else {
            $output .= '<div class="infotext"><p><b>Erläutere deine Auswahl an Tags im Freitextfeld.</b><br/>
Dieser Teil des Feedbacks ist optional.<br/>
Es muss keine Doktorarbeit werden, aber versuche deine Auswahl zu begründen, damit dein<br/>
Feedback richtig verstanden wird. Damit dieses auch gern angenommen wird, formuliere den<br/>
Text bitte respektvoll und konstruktiv. Hast du konkrete Lösungsvorschläge oder Alternativen?</br>
Immer her damit!
</p></div><br/>';
        }

        $output .= $texteditor;

        return $output;
    }

    /**
     * @param response $response
     * @return object|string
     */
    protected function response_survey_display($response) {
        if (isset($response->answers[$this->id])) {
            $answer = reset($response->answers[$this->id]);
            $answer = $answer->value;
        } else {
            $answer = '&nbsp;';
        }
        $output = '';
        $output .= '<div class="response text">';
        $output .= $answer;
        $output .= '</div>';
        return $output;
    }
}
