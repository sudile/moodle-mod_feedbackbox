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
 * Define all the backup steps that will be used by the backup_feedbackbox_activity_task
 */

/**
 * Define the complete choice structure for backup, with file and id annotations
 */
class backup_feedbackbox_activity_structure_step extends backup_activity_structure_step {

    /**
     * @return backup_nested_element
     * @throws base_element_struct_exception
     * @throws base_step_exception
     */
    protected function define_structure() {
        global $DB;
        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $feedbackbox = new backup_nested_element('feedbackbox', ['id'], [
            'course', 'name', 'intro', 'introformat',
            'notifications', 'opendate',
            'closedate', 'resume', 'sid', 'timemodified', 'completionsubmit',
            'notifystudents', 'turnus']);

        $surveys = new backup_nested_element('surveys');

        $survey = new backup_nested_element('survey', ['id'], [
            'name', 'courseid', 'status', 'title']);

        $questions = new backup_nested_element('questions');

        $question = new backup_nested_element('question', ['id'], ['surveyid', 'name', 'type_id', 'result_id',
            'length', 'precise', 'position', 'content', 'required', 'deleted']);

        $questchoices = new backup_nested_element('quest_choices');

        $questchoice = new backup_nested_element('quest_choice', ['id'], ['question_id', 'content']);

        $responses = new backup_nested_element('responses');

        $response = new backup_nested_element('response', ['id'], [
            'feedbackboxid', 'submitted', 'complete', 'userid']);

        $responsemultiples = new backup_nested_element('response_multiples');

        $responsemultiple = new backup_nested_element('response_multiple', ['id'], [
            'response_id', 'question_id', 'choice_id']);

        $responsesingles = new backup_nested_element('response_singles');

        $responsesingle = new backup_nested_element('response_single', ['id'], [
            'response_id', 'question_id', 'choice_id']);

        $responsetexts = new backup_nested_element('response_texts');

        $responsetext = new backup_nested_element('response_text', ['id'], ['response_id', 'question_id', 'response']);

        $userrelations = new backup_nested_element('userrelations');
        $userrelation = new backup_nested_element('userrelation', ['id'], ['userid']);

        // Build the tree.
        $feedbackbox->add_child($surveys);
        $surveys->add_child($survey);

        $survey->add_child($questions);
        $questions->add_child($question);

        $question->add_child($questchoices);
        $questchoices->add_child($questchoice);

        $feedbackbox->add_child($responses);
        $responses->add_child($response);

        $response->add_child($responsemultiples);
        $responsemultiples->add_child($responsemultiple);

        $response->add_child($responsesingles);
        $responsesingles->add_child($responsesingle);

        $response->add_child($responsetexts);
        $responsetexts->add_child($responsetext);

        $feedbackbox->add_child($userrelations);
        $userrelations->add_child($userrelation);

        // Define sources.
        $feedbackbox->set_source_table('feedbackbox', ['id' => backup::VAR_ACTIVITYID]);

        $survey->set_source_table('feedbackbox_survey', ['id' => '../../sid']);
        $question->set_source_table('feedbackbox_question', ['surveyid' => backup::VAR_PARENTID]);
        $questchoice->set_source_table('feedbackbox_quest_choice',
            ['question_id' => backup::VAR_PARENTID],
            'id ASC');

        // All the rest of elements only happen if we are including user info.
        if ($userinfo) {
            $fbox = $DB->get_record('feedbackbox', ['id' => $this->task->get_activityid()]);
            $users = [];
            $course = get_course($this->task->get_courseid());
            $cm = get_coursemodule_from_instance('feedbackbox', $this->task->get_activityid());
            $feedbackboxobj = new feedbackbox($this->task->get_activityid(),
                $fbox,
                $course,
                $cm);
            $zones = $feedbackboxobj->get_turnus_zones();
            $responsesdata = $DB->get_records('feedbackbox_response',
                ['feedbackboxid' => $this->task->get_activityid()]);
            foreach ($responsesdata as $entry) {
                $users[] = (object) ['id' => $entry->userid, 'userid' => $entry->userid];
                $entry->userid = feedbackbox::encode_id($entry->userid, $entry->id);
                $entry->submitted = feedbackbox::encode_id($entry->submitted, $entry->id);
            }

            shuffle($users); // Used to avoid sort solution to map user response to ids
            $userrelation->set_source_array($users);
            $response->set_source_array($responsesdata);
            // $response->set_source_table('feedbackbox_response', ['feedbackboxid' => backup::VAR_PARENTID]);
            $responsemultiple->set_source_table('feedbackbox_resp_multiple',
                ['response_id' => backup::VAR_PARENTID]);
            $responsesingle->set_source_table('feedbackbox_resp_single', ['response_id' => backup::VAR_PARENTID]);
            $responsetext->set_source_table('feedbackbox_response_text', ['response_id' => backup::VAR_PARENTID]);
            // Define id annotations.
            $userrelation->annotate_ids('user', 'userid');
        }
        // Define file annotations.
        $feedbackbox->annotate_files('mod_feedbackbox', 'intro', null); // This file area hasn't itemid.
        $question->annotate_files('mod_feedbackbox', 'question', 'id'); // By question->id.
        // Return the root element, wrapped into standard activity structure.
        return $this->prepare_activity_structure($feedbackbox);
    }

}