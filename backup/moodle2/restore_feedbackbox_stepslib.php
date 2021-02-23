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

use mod_feedbackbox\feedbackbox;

defined('MOODLE_INTERNAL') || die();

/**
 * Define all the restore steps that will be used by the restore_feedbackbox_activity_task
 */

/**
 * Structure step to restore one feedbackbox activity
 */
class restore_feedbackbox_activity_structure_step extends restore_activity_structure_step {

    /**
     * @return array
     * @throws base_step_exception
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('feedbackbox', '/activity/feedbackbox');
        $paths[] = new restore_path_element('feedbackbox_survey', '/activity/feedbackbox/surveys/survey');
        $paths[] = new restore_path_element('feedbackbox_question',
            '/activity/feedbackbox/surveys/survey/questions/question');
        $paths[] = new restore_path_element('feedbackbox_quest_choice',
            '/activity/feedbackbox/surveys/survey/questions/question/quest_choices/quest_choice');

        if ($userinfo) {
            $paths[] = new restore_path_element('feedbackbox_response', '/activity/feedbackbox/responses/response');
            $paths[] = new restore_path_element('feedbackbox_response_multiple',
                '/activity/feedbackbox/responses/response/response_multiples/response_multiple');
            $paths[] = new restore_path_element('feedbackbox_response_single',
                '/activity/feedbackbox/responses/response/response_singles/response_single');
            $paths[] = new restore_path_element('feedbackbox_response_text',
                '/activity/feedbackbox/responses/response/response_texts/response_text');
        }
        // Return the paths wrapped into standard activity structure.
        return $this->prepare_activity_structure($paths);
    }

    /**
     * @param $data
     * @throws base_step_exception
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->opendate = $this->apply_date_offset($data->opendate);
        $data->closedate = $this->apply_date_offset($data->closedate);
        $data->timemodified = $this->apply_date_offset($data->timemodified);
        // Insert the feedbackbox record.
        $newitemid = $DB->insert_record('feedbackbox', $data);
        // Immediately after inserting "activity" record, call this.
        $this->apply_activity_instance($newitemid);
    }

    /**
     * @param $data
     * @throws base_step_exception
     * @throws dml_exception
     * @throws restore_step_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_survey($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->courseid = $this->get_courseid();

        // Insert the feedbackbox_survey record.
        $newitemid = $DB->insert_record('feedbackbox_survey', $data);
        $this->set_mapping('feedbackbox_survey', $oldid, $newitemid, true);

        // Update the feedbackbox record we just created with the new survey id.
        $DB->set_field('feedbackbox', 'sid', $newitemid, ['id' => $this->get_new_parentid('feedbackbox')]);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @throws restore_step_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_question($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->surveyid = $this->get_new_parentid('feedbackbox_survey');

        // Insert the feedbackbox_question record.
        $newitemid = $DB->insert_record('feedbackbox_question', $data);
        $this->set_mapping('feedbackbox_question', $oldid, $newitemid, true);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @throws restore_step_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_quest_choice($data) {
        global $CFG, $DB;

        $data = (object) $data;

        /** @noinspection PhpIncludeInspection */
        require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

        // Replace the = separator with :: separator in quest_choice content.
        // This fixes radio button options using old "value"="display" formats.
        if (!preg_match("/^([0-9]{1,3}=.*|!other=.*)$/", $data->content)) {
            $content = feedbackbox_choice_values($data->content);
            if (strpos($content->text, '=')) {
                $data->content = str_replace('=', '::', $content->text);
            }
        }

        $oldid = $data->id;
        $data->question_id = $this->get_new_parentid('feedbackbox_question');

        // Insert the feedbackbox_quest_choice record.
        $newitemid = $DB->insert_record('feedbackbox_quest_choice', $data);
        $this->set_mapping('feedbackbox_quest_choice', $oldid, $newitemid);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @throws restore_step_exception
     * @throws moodle_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response($data) {
        global $DB;
        $data = (object) $data;
        $oldid = $data->id;
        $data->feedbackboxid = $this->get_new_parentid('feedbackbox');
        $data->userid = feedbackbox::decode_id($data->userid, $data->id);
        $data->submitted = feedbackbox::decode_id($data->submitted, $data->id);
        if (!$data->userid || !$data->submitted) {
            throw new moodle_exception('replay_attack_detected', 'feedbackbox');
        }
        $data->userid = $this->get_mappingid('user', $data->userid);
        // Insert the feedbackbox_response record.
        $newitemid = $DB->insert_record('feedbackbox_response', $data);
        $this->set_mapping('feedbackbox_response', $oldid, $newitemid);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response_multiple($data) {
        global $DB;

        $data = (object) $data;
        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);
        $data->choice_id = $this->get_mappingid('feedbackbox_quest_choice', $data->choice_id);

        // Insert the feedbackbox_resp_multiple record.
        $DB->insert_record('feedbackbox_resp_multiple', $data);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response_single($data) {
        global $DB;

        $data = (object) $data;
        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);
        $data->choice_id = $this->get_mappingid('feedbackbox_quest_choice', $data->choice_id);

        // Insert the feedbackbox_resp_single record.
        $DB->insert_record('feedbackbox_resp_single', $data);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response_text($data) {
        global $DB;

        $data = (object) $data;
        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);

        // Insert the feedbackbox_response_text record.
        $DB->insert_record('feedbackbox_response_text', $data);
    }

    protected function after_execute() {
        // Add feedbackbox related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_feedbackbox', 'intro', null);
        $this->add_related_files('mod_feedbackbox', 'info', 'feedbackbox_survey');
        $this->add_related_files('mod_feedbackbox', 'question', 'feedbackbox_question');
    }
}