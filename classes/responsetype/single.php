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
use dml_exception;
use mod_feedbackbox\question\choice\choice;
use mod_feedbackbox\question\question;
use mod_feedbackbox\responsetype\response\response;
use stdClass;

defined('MOODLE_INTERNAL') || die();

/**
 * Class for single response types.
 *
 * @author  Mike Churchward
 * @package responsetypes
 */
class single extends responsetype {
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
        if (isset($responsedata->{'q' . $question->id}) && isset($question->choices[$responsedata->{'q' . $question->id}])) {
            $record = new stdClass();
            $record->responseid = $responsedata->rid;
            $record->questionid = $question->id;
            $record->choiceid = $responsedata->{'q' . $question->id};
            // If this choice is an "other" choice, look for the added input.
            if ($question->choices[$responsedata->{'q' . $question->id}]->is_other_choice()) {
                $cname = 'q' . $question->id .
                    choice::id_other_choice_name($responsedata->{'q' . $question->id});
                $record->value = isset($responsedata->{$cname}) ? $responsedata->{$cname} : '';
            }
            $answers[$responsedata->{'q' . $question->id}] = answer\answer::create_from_data($record);
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
        $answers = [];
        $qname = 'q' . $question->id;
        if (isset($responsedata->{$qname}[0]) && !empty($responsedata->{$qname}[0])) {
            $record = new stdClass();
            $record->responseid = $responsedata->rid;
            $record->questionid = $question->id;
            $record->choiceid = $responsedata->{$qname}[0];
            // If this choice is an "other" choice, look for the added input.
            if ($question->choices[$record->choiceid]->is_other_choice()) {
                $cname = choice::id_other_choice_name($record->choiceid);
                $record->value =
                    isset($responsedata->{$qname}[$cname]) ? $responsedata->{$qname}[$cname] : '';
            } else {
                $record->value = '';
            }
            $answers[] = answer\answer::create_from_data($record);
        }
        return $answers;
    }

    /**
     * Return an array of answer objects by question for the given response id.
     * THIS SHOULD REPLACE response_select.
     *
     * @param int $rid The response id.
     * @return array array answer
     * @throws dml_exception
     */
    static public function response_answers_by_question($rid) {
        global $DB;

        $answers = [];
        $sql = 'SELECT r.id as id, r.response_id as responseid, r.question_id as questionid, r.choice_id as choiceid, ' .
            '1 as value FROM {' . static::response_table() . '} r WHERE r.response_id = ? ';
        $records = $DB->get_records_sql($sql, [$rid]);
        foreach ($records as $record) {
            $answers[$record->questionid][$record->choiceid] = answer\answer::create_from_data($record);
        }

        return $answers;
    }

    /**
     * @return string
     */
    static public function response_table() {
        return 'feedbackbox_resp_single';
    }

    /**
     * @param response|stdClass $responsedata
     * @return bool|int
     * @throws coding_exception
     * @throws dml_exception
     */
    public function insert_response($responsedata) {
        global $DB;

        if (!$responsedata instanceof response) {
            $response = response::response_from_webform($responsedata,
                [$this->question]);
        } else {
            $response = $responsedata;
        }

        $resid = false;
        if (!empty($response) && isset($response->answers[$this->question->id])) {
            foreach ($response->answers[$this->question->id] as $answer) {
                if (isset($this->question->choices[$answer->choiceid])) {
                    // Record the choice selection.
                    $record = new stdClass();
                    $record->response_id = $response->id;
                    $record->question_id = $this->question->id;
                    $record->choice_id = $answer->choiceid;
                    $resid = $DB->insert_record(static::response_table(), $record);
                }
            }
        }
        return $resid;
    }

    /**
     * Return the JSON structure required for the template.
     *
     * @param bool   $rids
     * @param string $sort
     * @param bool   $anonymous
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     */
    public function display_results($rids = false, $sort = '', $anonymous = false) {
        global $DB;

        $rows = $this->get_results($rids, $anonymous);
        if (is_array($rids)) {
            $prtotal = 1;
        } else if (is_int($rids)) {
            $prtotal = 0;
        }
        $numresps = count($rids);

        $responsecountsql = 'SELECT COUNT(DISTINCT r.response_id) ' .
            'FROM {' . $this->response_table() . '} r ' .
            'WHERE r.question_id = ? ';
        $numrespondents = $DB->count_records_sql($responsecountsql, [$this->question->id]);

        if ($rows) {
            $counts = [];
            foreach ($rows as $idx => $row) {
                if (strpos($idx, 'other') === 0) {
                    $answer = $row->response;
                    $ccontent = $row->content;
                    $content = choice::content_other_choice_display($ccontent);
                    $content .= ' ' . clean_text($answer);
                    $textidx = $content;
                    $counts[$textidx] = !empty($counts[$textidx]) ? ($counts[$textidx] + 1) : 1;
                } else {
                    $contents = feedbackbox_choice_values($row->content);
                    $textidx = $contents->text . $contents->image;
                    $counts[$textidx] = !empty($counts[$textidx]) ? ($counts[$textidx] + 1) : 1;
                }
            }
            $pagetags = $this->get_results_tags($counts, $numresps, $numrespondents, $prtotal, $sort);
        } else {
            $pagetags = new stdClass();
        }
        return $pagetags;
    }

    /**
     * @param bool $rids
     * @param bool $anonymous
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public function get_results($rids = false, $anonymous = false) {
        global $DB;

        $rsql = '';
        $params = [$this->question->id];
        if (!empty($rids)) {
            list($rsql, $rparams) = $DB->get_in_or_equal($rids);
            $params = array_merge($params, $rparams);
            $rsql = ' AND response_id ' . $rsql;
        }

        // Added qc.id to preserve original choices ordering.
        $sql = 'SELECT rt.id, qc.id as cid, qc.content ' .
            'FROM {feedbackbox_quest_choice} qc, ' .
            '{' . static::response_table() . '} rt ' .
            'WHERE qc.question_id= ? AND qc.content NOT LIKE \'!other%\' AND ' .
            'rt.question_id=qc.question_id AND rt.choice_id=qc.id' . $rsql . ' ' .
            'ORDER BY qc.id';

        $rows = $DB->get_records_sql($sql, $params);
        return $rows;
    }

    /**
     * Return sql for getting responses in bulk.
     *
     * @return string
     * @author Guy Thomas
     */
    protected function bulk_sql() {
        global $DB;

        $userfields = $this->user_fields_sql();
        $alias = 'qrs';
        $extraselect = 'qrs.choice_id, ' . $DB->sql_order_by_text('qro.response',
                1000) . ' AS response, 0 AS rankvalue';

        return "
            SELECT " . $DB->sql_concat_join("'_'", ['qr.id', "'" . $this->question->helpname() . "'", $alias . '.id']) . " AS id,
                   qr.submitted, qr.complete, qr.userid, $userfields, qr.id AS rid, $alias.question_id,
                   $extraselect
              FROM {feedbackbox_response} qr
              JOIN {" . static::response_table() . "} $alias ON $alias.response_id = qr.id
        ";
    }
}