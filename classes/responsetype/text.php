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
defined('MOODLE_INTERNAL') || die();

use coding_exception;
use dml_exception;
use mod_feedbackbox\db\bulk_sql_config;
use mod_feedbackbox\question\question;
use mod_feedbackbox\responsetype\response\response;
use stdClass;

/**
 * Class for text response types.
 *
 * @author  Mike Churchward
 * @package responsetypes
 */
class text extends responsetype {
    /**
     * Provide an array of answer objects from web form data for the question.
     *
     * @param stdClass $responsedata All of the responsedata as an object.
     * @param question $question
     * @return array \mod_feedbackbox\responsetype\answer\answer An array of answer objects.
     */
    static public function answers_from_webform($responsedata, $question) {
        $answers = [];
        if (isset($responsedata->{'q' . $question->id}) && (strlen($responsedata->{'q' . $question->id}) > 0)) {
            $val = $responsedata->{'q' . $question->id};
            $record = new stdClass();
            $record->responseid = $responsedata->rid;
            $record->questionid = $question->id;
            $record->value = $val;
            $answers[] = answer\answer::create_from_data($record);
        }
        return $answers;
    }

    /**
     * Return an array of answers by question/choice for the given response. Must be implemented by the subclass.
     *
     * @param int $rid The response id.
     * @return array
     * @throws dml_exception
     */
    static public function response_select($rid) {
        global $DB;

        $values = [];
        $sql = 'SELECT q.id, q.content, a.response as aresponse ' .
            'FROM {' . static::response_table() . '} a, {feedbackbox_question} q ' .
            'WHERE a.response_id=? AND a.question_id=q.id ';
        $records = $DB->get_records_sql($sql, [$rid]);
        foreach ($records as $qid => $row) {
            unset($row->id);
            $row = (array) $row;
            $newrow = [];
            foreach ($row as $key => $val) {
                if (!is_numeric($key)) {
                    $newrow[] = $val;
                }
            }
            $values[$qid] = $newrow;
            $val = array_pop($values[$qid]);
            array_push($values[$qid], $val, $val);
        }

        return $values;
    }

    /**
     * @return string
     */
    static public function response_table() {
        return 'feedbackbox_response_text';
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
        $sql = 'SELECT id, response_id as responseid, question_id as questionid, 0 as choiceid, response as value ' .
            'FROM {' . static::response_table() . '} ' .
            'WHERE response_id = ? ';
        $records = $DB->get_records_sql($sql, [$rid]);
        foreach ($records as $record) {
            $answers[$record->questionid][] = answer\answer::create_from_data($record);
        }

        return $answers;
    }

    /**
     * @param response|stdClass $responsedata
     * @return bool|int
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

        if (!empty($response) && isset($response->answers[$this->question->id][0])) {
            $record = new stdClass();
            $record->response_id = $response->id;
            $record->question_id = $this->question->id;
            $record->response = $response->answers[$this->question->id][0]->value;
            if (strlen($record->response) > 10000) {
                $record->response = substr($record->response, 0, 10000);
            }
            return $DB->insert_record(static::response_table(), $record);
        } else {
            return false;
        }
    }

    /**
     * @param bool   $rids
     * @param string $sort
     * @param bool   $anonymous
     * @return string
     * @throws coding_exception
     * @throws dml_exception
     */
    public function display_results($rids = false, $sort = '', $anonymous = false) {
        $prtotal = 0;
        if (is_array($rids)) {
            $prtotal = 1;
        }
        if ($rows = $this->get_results($rids, $anonymous)) {
            $numrespondents = count($rids);
            $numresponses = count($rows);
            $pagetags = $this->get_results_tags($rows, $numrespondents, $numresponses, $prtotal);
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
        $params = [];
        $rsql = '';
        if (!empty($rids)) {
            [$rsql, $params] = $DB->get_in_or_equal($rids);
            $rsql = ' AND response_id ' . $rsql;
        }

        if ($anonymous) {
            $sql = 'SELECT t.id, t.response, r.submitted AS submitted, ' .
                'r.feedbackboxid, r.id AS rid ' .
                'FROM {' . static::response_table() . '} t, ' .
                '{feedbackbox_response} r ' .
                'WHERE question_id=' . $this->question->id . $rsql .
                ' AND t.response_id = r.id ' .
                'ORDER BY r.submitted DESC';
        } else {
            $sql = 'SELECT t.id, t.response, r.submitted AS submitted, r.userid, u.username AS username, ' .
                'u.id as usrid, ' .
                'r.feedbackboxid, r.id AS rid ' .
                'FROM {' . static::response_table() . '} t, ' .
                '{feedbackbox_response} r, ' .
                '{user} u ' .
                'WHERE question_id=' . $this->question->id . $rsql .
                ' AND t.response_id = r.id' .
                ' AND u.id = r.userid ' .
                'ORDER BY u.lastname, u.firstname, r.submitted';
        }
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Override the results tags function for templates for questions with dates.
     *
     * @param        $weights
     * @param        $participants Number of feedbackbox participants.
     * @param        $respondents  Number of question respondents.
     * @param int    $showtotals
     * @param string $sort
     * @return stdClass
     * @throws coding_exception
     */
    public function get_results_tags($weights, $participants, $respondents, $showtotals = 1, $sort = '') {
        $pagetags = new stdClass();
        if ($respondents == 0) {
            return $pagetags;
        }
        // If array element is an object, outputting non-numeric responses.
        if (is_object(reset($weights))) {
            $evencolor = false;
            foreach ($weights as $row) {
                $response = new stdClass();
                $response->text = format_text($row->response, FORMAT_HTML);
                $response->respondent = '';
                // The 'evencolor' attribute is used by the PDF template.
                $response->evencolor = $evencolor;
                $pagetags->responses[] = (object) ['response' => $response];
                $evencolor = !$evencolor;
            }

            if ($showtotals == 1) {
                $pagetags->total = new stdClass();
                $pagetags->total->total = "$respondents/$participants";
            }
        } else {
            $nbresponses = 0;
            $sum = 0;
            $strtotal = get_string('totalofnumbers', 'feedbackbox');
            $straverage = get_string('average', 'feedbackbox');

            if (!empty($weights) && is_array($weights)) {
                ksort($weights);
                $evencolor = false;
                foreach ($weights as $text => $num) {
                    $response = new stdClass();
                    $response->text = $text;
                    $response->respondent = $num;
                    // The 'evencolor' attribute is used by the PDF template.
                    $response->evencolor = $evencolor;
                    $nbresponses += $num;
                    $sum += $text * $num;
                    $evencolor = !$evencolor;
                    $pagetags->responses[] = (object) ['response' => $response];
                }

                $response = new stdClass();
                $response->text = $sum;
                $response->respondent = $strtotal;
                $response->evencolor = $evencolor;
                $pagetags->responses[] = (object) ['response' => $response];
                $evencolor = !$evencolor;

                $response = new stdClass();
                $response->respondent = $straverage;
                $avg = $sum / $nbresponses;
                $response->text = sprintf('%.' . $this->question->precise . 'f', $avg);
                $response->evencolor = $evencolor;
                $pagetags->responses[] = (object) ['response' => $response];
                $evencolor = !$evencolor;

                if ($showtotals == 1) {
                    $pagetags->total = new stdClass();
                    $pagetags->total->total = "$respondents/$participants";
                    $pagetags->total->evencolor = $evencolor;
                }
            }
        }

        return $pagetags;
    }

    /**
     * Configure bulk sql
     *
     * @return bulk_sql_config
     */
    protected function bulk_sql_config() {
        return new bulk_sql_config(static::response_table(), 'qrt', false, true, false);
    }
}

