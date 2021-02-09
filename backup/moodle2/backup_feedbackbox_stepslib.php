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
     * @throws dml_exception
     */
    protected function define_structure() {
        global $DB;
        // To know if we are including userinfo.
        $userinfo = $this->get_setting_value('userinfo');

        // Define each element separated.
        $feedbackbox = new backup_nested_element('feedbackbox', ['id'], [
            'course', 'name', 'intro', 'introformat', 'qtype',
            'respondenttype', 'resp_eligible', 'resp_view', 'notifications', 'opendate',
            'closedate', 'resume', 'navigate', 'grade', 'sid', 'timemodified', 'completionsubmit',
            'autonum', 'notifystudents']);

        $surveys = new backup_nested_element('surveys');

        $survey = new backup_nested_element('survey', ['id'], [
            'name', 'courseid', 'realm', 'status', 'title', 'email', 'subtitle',
            'info', 'theme', 'thanks_page', 'thank_head', 'thank_body', 'feedbacksections',
            'feedbacknotes', 'feedbackscores', 'chart_type']);

        $questions = new backup_nested_element('questions');

        $question = new backup_nested_element('question', ['id'], ['surveyid', 'name', 'type_id', 'result_id',
            'length', 'precise', 'position', 'content', 'required', 'deleted', 'extradata']);

        $questchoices = new backup_nested_element('quest_choices');

        $questchoice = new backup_nested_element('quest_choice', ['id'], ['question_id', 'content', 'value']);

        $questdependencies = new backup_nested_element('quest_dependencies');

        $questdependency = new backup_nested_element('quest_dependency', ['id'], [
            'dependquestionid', 'dependchoiceid', 'dependlogic', 'questionid', 'surveyid', 'dependandor']);

        $fbsections = new backup_nested_element('fb_sections');

        $fbsection = new backup_nested_element('fb_section', ['id'], [
            'surveyid', 'section', 'scorecalculation', 'sectionlabel', 'sectionheading', 'sectionheadingformat']);

        $feedbacks = new backup_nested_element('feedbacks');

        $feedback = new backup_nested_element('feedback', ['id'], [
            'sectionid', 'feedbacklabel', 'feedbacktext', 'feedbacktextformat', 'minscore', 'maxscore']);

        $responses = new backup_nested_element('responses');

        $response = new backup_nested_element('response', ['id'], [
            'feedbackboxid', 'submitted', 'complete', 'grade', 'userid']);

        $responsebools = new backup_nested_element('response_bools');

        $responsebool = new backup_nested_element('response_bool', ['id'], ['response_id', 'question_id', 'choice_id']);

        $responsedates = new backup_nested_element('response_dates');

        $responsedate = new backup_nested_element('response_date', ['id'], ['response_id', 'question_id', 'response']);

        $responsemultiples = new backup_nested_element('response_multiples');

        $responsemultiple = new backup_nested_element('response_multiple', ['id'], [
            'response_id', 'question_id', 'choice_id']);

        $responseothers = new backup_nested_element('response_others');

        $responseother = new backup_nested_element('response_other', ['id'], [
            'response_id', 'question_id', 'choice_id', 'response']);

        $responseranks = new backup_nested_element('response_ranks');

        $responserank = new backup_nested_element('response_rank', ['id'], [
            'response_id', 'question_id', 'choice_id', 'rankvalue']);

        $responsesingles = new backup_nested_element('response_singles');

        $responsesingle = new backup_nested_element('response_single', ['id'], [
            'response_id', 'question_id', 'choice_id']);

        $responsetexts = new backup_nested_element('response_texts');

        $responsetext = new backup_nested_element('response_text', ['id'], ['response_id', 'question_id', 'response']);

        // Build the tree.
        $feedbackbox->add_child($surveys);
        $surveys->add_child($survey);

        $survey->add_child($questions);
        $questions->add_child($question);

        $question->add_child($questchoices);
        $questchoices->add_child($questchoice);

        $question->add_child($questdependencies);
        $questdependencies->add_child($questdependency);

        $survey->add_child($fbsections);
        $fbsections->add_child($fbsection);

        $fbsection->add_child($feedbacks);
        $feedbacks->add_child($feedback);

        $feedbackbox->add_child($responses);
        $responses->add_child($response);

        $response->add_child($responsebools);
        $responsebools->add_child($responsebool);

        $response->add_child($responsedates);
        $responsedates->add_child($responsedate);

        $response->add_child($responsemultiples);
        $responsemultiples->add_child($responsemultiple);

        $response->add_child($responseothers);
        $responseothers->add_child($responseother);

        $response->add_child($responseranks);
        $responseranks->add_child($responserank);

        $response->add_child($responsesingles);
        $responsesingles->add_child($responsesingle);

        $response->add_child($responsetexts);
        $responsetexts->add_child($responsetext);

        // Define sources.
        $feedbackbox->set_source_table('feedbackbox', ['id' => backup::VAR_ACTIVITYID]);

        // Is current feedbackbox based on a public feedbackbox?
        $qid = $this->task->get_activityid();
        $currentfeedbackbox = $DB->get_record("feedbackbox", ["id" => $qid]);
        $currentsurvey = $DB->get_record("feedbackbox_survey", ["id" => $currentfeedbackbox->sid]);
        $haspublic = false;
        if ($currentsurvey->realm == 'public' && $currentsurvey->courseid != $currentfeedbackbox->course) {
            $haspublic = true;
        }

        // If current feedbackbox is based on a public one, do not include survey nor questions in backup.
        if (!$haspublic) {
            $survey->set_source_table('feedbackbox_survey', ['id' => '../../sid']);
            $question->set_source_table('feedbackbox_question', ['surveyid' => backup::VAR_PARENTID]);
            $fbsection->set_source_table('feedbackbox_fb_sections', ['surveyid' => backup::VAR_PARENTID]);
            $feedback->set_source_table('feedbackbox_feedback', ['sectionid' => backup::VAR_PARENTID]);
            $questchoice->set_source_table('feedbackbox_quest_choice',
                ['question_id' => backup::VAR_PARENTID],
                'id ASC');
            $questdependency->set_source_table('feedbackbox_dependency', ['questionid' => backup::VAR_PARENTID]);

            // All the rest of elements only happen if we are including user info.
            if ($userinfo && is_siteadmin()) {
                $response->set_source_table('feedbackbox_response', ['feedbackboxid' => backup::VAR_PARENTID]);
                $responsebool->set_source_table('feedbackbox_response_bool', ['response_id' => backup::VAR_PARENTID]);
                $responsedate->set_source_table('feedbackbox_response_date', ['response_id' => backup::VAR_PARENTID]);
                $responsemultiple->set_source_table('feedbackbox_resp_multiple',
                    ['response_id' => backup::VAR_PARENTID]);
                $responseother->set_source_table('feedbackbox_response_other', ['response_id' => backup::VAR_PARENTID]);
                $responserank->set_source_table('feedbackbox_response_rank', ['response_id' => backup::VAR_PARENTID]);
                $responsesingle->set_source_table('feedbackbox_resp_single', ['response_id' => backup::VAR_PARENTID]);
                $responsetext->set_source_table('feedbackbox_response_text', ['response_id' => backup::VAR_PARENTID]);
            }

            // Define id annotations.
            $response->annotate_ids('user', 'userid');
        }
        // Define file annotations.
        $feedbackbox->annotate_files('mod_feedbackbox', 'intro', null); // This file area hasn't itemid.

        $survey->annotate_files('mod_feedbackbox', 'info', 'id'); // By survey->id.
        $survey->annotate_files('mod_feedbackbox', 'thankbody', 'id'); // By survey->id.

        $question->annotate_files('mod_feedbackbox', 'question', 'id'); // By question->id.

        $fbsection->annotate_files('mod_feedbackbox', 'sectionheading', 'id'); // By feedback->id.
        $feedback->annotate_files('mod_feedbackbox', 'feedback', 'id'); // By feedback->id.

        // Return the root element, wrapped into standard activity structure.
        return $this->prepare_activity_structure($feedbackbox);
    }
}