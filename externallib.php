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
 * feedbackbox module external API
 *
 * @package    mod_feedbackbox
 * @category   external
 * @copyright  2018 Igor Sazonov <sovletig@gmail.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.0
 */

use mod_feedbackbox\feedbackbox;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->libdir . '/externallib.php');
require_once($CFG->dirroot . '/mod/feedbackbox/lib.php');

/**
 * feedbackbox module external functions
 *
 * @package    mod_feedbackbox
 * @category   external
 * @copyright  2018 Igor Sazonov <sovletig@yandex.ru>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @since      Moodle 3.0
 */
class mod_feedbackbox_external extends external_api {

    /**
     * @return external_function_parameters
     * @noinspection PhpUnused
     */
    public static function get_chartdata_single_parameters() {
        return new external_function_parameters(
            [
                'feedbackboxid' => new external_value(PARAM_INT, 'feedbackbox id'),
                'turnus' => new external_value(PARAM_INT, 'Course module id', VALUE_OPTIONAL),
            ]
        );
    }

    /**
     * @param $feedbackboxid
     * @param $turnus
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpUnused
     */
    public static function get_chartdata_single($feedbackboxid, $turnus) {
        global $DB, $USER;
        $result = new stdClass();
        if (!$cm = get_coursemodule_from_instance('feedbackbox', $feedbackboxid)) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
        if (!has_capability('mod/feedbackbox:manage', context_module::instance($cm->id), $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $course = $DB->get_record("course", ["id" => $cm->course]);
        $feedbackbox = $DB->get_record('feedbackbox', ['id' => $feedbackboxid]);
        $feedbackbox = new feedbackbox($feedbackboxid, $feedbackbox, $course, $cm);
        $data = $feedbackbox->get_turnus_responses($turnus);
        $result->data = $data->rating;
        $result->labels = $data->ratinglabel;
        return $result;
    }

    /**
     * @return external_single_structure
     * @noinspection PhpUnused
     */
    public static function get_chartdata_single_returns() {
        return new external_single_structure(
            [
                'data' => new external_multiple_structure(
                    new external_value(PARAM_FLOAT, 'data', VALUE_OPTIONAL)
                ),
                'labels' => new external_multiple_structure(
                    new external_value(PARAM_TEXT, 'label', VALUE_OPTIONAL)
                )
            ]
        );
    }

    /**
     * @return external_function_parameters
     * @noinspection PhpUnused
     */
    public static function get_chartdata_multiple_parameters() {
        return new external_function_parameters(
            [
                'feedbackboxid' => new external_value(PARAM_INT, 'feedbackbox id'),
                'turnus' => new external_value(PARAM_INT, 'Course module id', VALUE_OPTIONAL),
            ]
        );
    }

    /**
     * @param $feedbackboxid
     * @return stdClass
     * @throws coding_exception
     * @throws dml_exception
     * @throws moodle_exception
     * @noinspection PhpUnused
     */
    public static function get_chartdata_multiple($feedbackboxid) {
        global $DB, $USER;
        $result = new stdClass();
        if (!$cm = get_coursemodule_from_instance('feedbackbox', $feedbackboxid)) {
            throw new \moodle_exception('invalidcoursemodule', 'error');
        }
        if (!has_capability('mod/feedbackbox:manage', context_module::instance($cm->id), $USER->id)) {
            throw new \moodle_exception('nopermissions', 'error');
        }
        $course = $DB->get_record("course", ["id" => $cm->course]);
        $feedbackbox = $DB->get_record('feedbackbox', ['id' => $feedbackboxid]);
        $feedbackbox = new feedbackbox($feedbackboxid, $feedbackbox, $course, $cm);
        $data = null;
        $data = $feedbackbox->get_feedback_responses();
        $result->data = [];
        foreach ($data->zones as $entry) { // round
            $result->data[] = (object) ['rating' => round($entry->rating, 2), 'participants' => $entry->participants];
        }
        return $result;
    }

    /**
     * @return external_single_structure
     * @noinspection PhpUnused
     */
    public static function get_chartdata_multiple_returns() {
        return new external_single_structure(
            [
                'data' => new external_multiple_structure(
                    new external_single_structure([
                        'rating' => new external_value(PARAM_FLOAT, 'rating', VALUE_OPTIONAL),
                        'participants' => new external_value(PARAM_INT, 'participants', VALUE_OPTIONAL),
                    ])
                )
            ]
        );
    }
}