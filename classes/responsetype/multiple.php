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
 * This file contains the parent class for feedbackbox question types.
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

namespace mod_feedbackbox\responsetype;

use coding_exception;
use mod_feedbackbox\question\choice\choice;
use mod_feedbackbox\question\question;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for multiple response types.
 *
 * @author  Mike Churchward
 * @package responsetypes
 */
class multiple extends single {
    /**
     * The only differences between multuple and single responses are the
     * response table and the insert logic.
     */
    static public function response_table() {
        return 'feedbackbox_resp_multiple';
    }

    /**
     * Provide an array of answer objects from web form data for the question.
     *
     * @param stdClass $responsedata All of the responsedata as an object.
     * @param question $question
     * @return array \mod_feedbackbox\responsetype\answer\answer An array of answer objects.
     * @throws coding_exception
     */
    static public function answers_from_webform($responsedata, $question) {
        $answers = [];
        if (isset($responsedata->{'q' . $question->id})) {
            foreach ($responsedata->{'q' . $question->id} as $cid => $cvalue) {
                $cid = clean_param($cid, PARAM_CLEAN);
                if (isset($question->choices[$cid])) {
                    $record = new stdClass();
                    $record->responseid = $responsedata->rid;
                    $record->questionid = $question->id;
                    $record->choiceid = $cid;
                    // If this choice is an "other" choice, look for the added input.
                    if ($question->choices[$cid]->is_other_choice()) {
                        $cname = choice::id_other_choice_name($cid);
                        $record->value = isset($responsedata->{'q' . $question->id}[$cname]) ?
                            $responsedata->{'q' . $question->id}[$cname] : '';
                    }
                    $answers[$cid] = answer\answer::create_from_data($record);
                }
            }
        }
        return $answers;
    }

    /**
     * Provide an array of answer objects from mobile data for the question.
     *
     * @param stdClass $responsedata All of the responsedata as an object.
     * @param question $question
     * @return array \mod_feedbackbox\responsetype\answer\answer An array of answer objects.
     */
    static public function answers_from_appdata($responsedata, $question) {
        // Need to override "single" class' implementation.
        $answers = [];
        $qname = 'q' . $question->id;
        if (isset($responsedata->{$qname}) && !empty($responsedata->{$qname})) {
            foreach ($responsedata->{$qname} as $choiceid => $choicevalue) {
                if ($choicevalue) {
                    $record = new stdClass();
                    $record->responseid = $responsedata->rid;
                    $record->questionid = $question->id;
                    $record->choiceid = $choiceid;
                    // If this choice is an "other" choice, look for the added input.
                    if (isset($question->choices[$choiceid]) && $question->choices[$choiceid]->is_other_choice()) {
                        $cname = choice::id_other_choice_name($choiceid);
                        $record->value =
                            isset($responsedata->{$qname}[$cname]) ? $responsedata->{$qname}[$cname] : '';
                    } else {
                        $record->value = $choicevalue;
                    }
                    $answers[] = answer\answer::create_from_data($record);
                }
            }
        }
        return $answers;
    }
}