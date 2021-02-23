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
 * Contains class mod_feedbackbox\output\renderer
 *
 * @package    mod_feedbackbox
 * @copyright  2016 Mike Churchward (mike.churchward@poetgroup.org)
 * @author     Mike Churchward
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_feedbackbox\output;

use flexible_table;
use html_writer;
use mod_feedbackbox\question\question;
use mod_feedbackbox\responsetype\response\response;
use moodle_exception;
use plugin_renderer_base;

defined('MOODLE_INTERNAL') || die();

class renderer extends plugin_renderer_base {

    /**
     * Render the respondent information line.
     *
     * @param string $text The respondent information.
     * @return string
     */
    public function respondent_info($text) {
        return html_writer::tag('span', $text, ['class' => 'respondentinfo']);
    }

    /**
     * Render the completion form start HTML.
     *
     * @param string $action       The action URL.
     * @param array  $hiddeninputs Name/value pairs of hidden inputs used by the form.
     * @return string The output for the page.
     */
    public function complete_formstart($action, $hiddeninputs = []) {
        $output = '';
        $output .= html_writer::start_tag('form',
                ['id' => 'phpesp_response', 'method' => 'post', 'action' => $action]) . "\n";
        foreach ($hiddeninputs as $name => $value) {
            $output .= html_writer::empty_tag('input',
                    ['type' => 'hidden', 'name' => $name, 'value' => $value]) . "\n";
        }
        return $output;
    }

    /**
     * Render the completion form end HTML.
     *
     * @param array $inputs Type/attribute array of inputs and values used by the form.
     * @return string The output for the page.
     */
    public function complete_formend($inputs = []) {
        $output = '';
        foreach ($inputs as $type => $attributes) {
            $output .= html_writer::empty_tag('input', array_merge(['type' => $type], $attributes)) . "\n";
        }
        $output .= html_writer::end_tag('form') . "\n";
        return $output;
    }

    /**
     * Render the completion form control buttons.
     *
     * @param array | string $inputs Name/(Type/attribute) array of input types and values used by the form.
     * @return string The output for the page.
     */
    public function complete_controlbuttons($inputs = null) {
        $output = '';
        if (is_array($inputs)) {
            foreach ($inputs as $name => $attributes) {
                $output .= html_writer::empty_tag('input', array_merge(['name' => $name], $attributes)) . ' ';
            }
        } else if (is_string($inputs)) {
            $output .= html_writer::tag('p', $inputs);
        }
        return $output;
    }

    /**
     * Render a question for a survey.
     *
     * @param question $question         The question object.
     * @param response $response         Any current response data.
     * @param array    $dependants       Array of all questions/choices depending on $question.
     * @param int      $qnum             The question number.
     * @param boolean  $blankfeedbackbox Used for printing a blank one.
     * @return string The output for the page.
     * @throws moodle_exception
     */
    public function question_output($question, $response, $dependants, $qnum, $blankfeedbackbox) {

        $pagetags = $question->question_output($response, $dependants, $qnum, $blankfeedbackbox);

        // If the question has a template, then render it from the 'qformelement' context. If no template, then 'qformelement'
        // already contains HTML.
        if (($template = $question->question_template())) {
            $pagetags->qformelement = $this->render_from_template($template, $pagetags->qformelement);
        }
        if ($question->type_id == 5) { // Used to set the display type.
            $key = strip_tags($question->content);
            $icons = [
                'STRUKTUR &amp; WORKLOAD' => 'b/fb_struktur',
                'ORGANISATORISCHES' => 'b/fb_orga',
                'GRUPPENDYNAMIK &amp; WEITERES' => 'b/fb_gruppe',
                'INHALTE' => 'b/fb_inhalte',
                'MEDIEN &amp; KOMMUNIKATION' => 'b/fb_medien'];
            $pagetags->caticon = $icons[$key];
            $pagetags->subquestion = true;

        }
        return $this->render_from_template('mod_feedbackbox/question_container', $pagetags);
    }

    /**
     * Render the back to home link on the save page.
     *
     * @param string $url  The url to link to.
     * @param string $text The text to apply the link to.
     * @return string The rendered HTML.
     */
    public function homelink($url, $text) {
        $output = '';
        $output .= html_writer::start_tag('div', ['class' => 'homelink']);
        $output .= html_writer::tag('a', $text, ['href' => $url, 'class' => 'btn btn-primary']);
        $output .= html_writer::end_tag('div');
        return $output;
    }

    /**
     * Helper method dealing with the fact we can not just fetch the output of flexible_table
     *
     * @param flexible_table $table
     * @param bool           $buffering True if already buffering.
     * @return false|string
     */
    public function flexible_table(flexible_table $table, $buffering = false) {
        if (!$buffering) {
            ob_start();
        }
        $table->finish_output();
        $o = ob_get_contents();
        ob_end_clean();

        return $o;
    }
}