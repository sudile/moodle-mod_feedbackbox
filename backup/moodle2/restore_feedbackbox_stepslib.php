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
 * Define all the restore steps that will be used by the restore_feedbackbox_activity_task
 */

/**
 * Structure step to restore one feedbackbox activity
 */
class restore_feedbackbox_activity_structure_step extends restore_activity_structure_step {

    /**
     * @var array $olddependquestions Contains any question id's with dependencies.
     */
    protected $olddependquestions = [];

    /**
     * @var array $olddependchoices Contains any choice id's for questions with dependencies.
     */
    protected $olddependchoices = [];

    /**
     * @var array $olddependencies Contains the old id's from the dependencies array.
     */
    protected $olddependencies = [];

    /**
     * @return array
     * @throws base_step_exception
     */
    protected function define_structure() {

        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('feedbackbox', '/activity/feedbackbox');
        $paths[] = new restore_path_element('feedbackbox_survey', '/activity/feedbackbox/surveys/survey');
        $paths[] = new restore_path_element('feedbackbox_fb_sections',
            '/activity/feedbackbox/surveys/survey/fb_sections/fb_section');
        $paths[] = new restore_path_element('feedbackbox_feedback',
            '/activity/feedbackbox/surveys/survey/fb_sections/fb_section/feedbacks/feedback');
        $paths[] = new restore_path_element('feedbackbox_question',
            '/activity/feedbackbox/surveys/survey/questions/question');
        $paths[] = new restore_path_element('feedbackbox_quest_choice',
            '/activity/feedbackbox/surveys/survey/questions/question/quest_choices/quest_choice');
        $paths[] = new restore_path_element('feedbackbox_dependency',
            '/activity/feedbackbox/surveys/survey/questions/question/quest_dependencies/quest_dependency');

        if ($userinfo && is_siteadmin()) {
            if ($this->task->get_old_moduleversion() < 2018050102) {
                // Old system.
                $paths[] = new restore_path_element('feedbackbox_attempt', '/activity/feedbackbox/attempts/attempt');
                $paths[] = new restore_path_element('feedbackbox_response',
                    '/activity/feedbackbox/attempts/attempt/responses/response');
                $paths[] = new restore_path_element('feedbackbox_response_bool',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_bools/response_bool');
                $paths[] = new restore_path_element('feedbackbox_response_date',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_dates/response_date');
                $paths[] = new restore_path_element('feedbackbox_response_multiple',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_multiples/response_multiple');
                $paths[] = new restore_path_element('feedbackbox_response_other',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_others/response_other');
                $paths[] = new restore_path_element('feedbackbox_response_rank',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_ranks/response_rank');
                $paths[] = new restore_path_element('feedbackbox_response_single',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_singles/response_single');
                $paths[] = new restore_path_element('feedbackbox_response_text',
                    '/activity/feedbackbox/attempts/attempt/responses/response/response_texts/response_text');

            } else {
                // New system.
                $paths[] = new restore_path_element('feedbackbox_response', '/activity/feedbackbox/responses/response');
                $paths[] = new restore_path_element('feedbackbox_response_bool',
                    '/activity/feedbackbox/responses/response/response_bools/response_bool');
                $paths[] = new restore_path_element('feedbackbox_response_date',
                    '/activity/feedbackbox/responses/response/response_dates/response_date');
                $paths[] = new restore_path_element('feedbackbox_response_multiple',
                    '/activity/feedbackbox/responses/response/response_multiples/response_multiple');
                $paths[] = new restore_path_element('feedbackbox_response_other',
                    '/activity/feedbackbox/responses/response/response_others/response_other');
                $paths[] = new restore_path_element('feedbackbox_response_rank',
                    '/activity/feedbackbox/responses/response/response_ranks/response_rank');
                $paths[] = new restore_path_element('feedbackbox_response_single',
                    '/activity/feedbackbox/responses/response/response_singles/response_single');
                $paths[] = new restore_path_element('feedbackbox_response_text',
                    '/activity/feedbackbox/responses/response/response_texts/response_text');
            }
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

        // Check for a 'feedbacksections' value larger than 2, and limit it to 2. As of 3.5.1 this has a different meaning.
        if ($data->feedbacksections > 2) {
            $data->feedbacksections = 2;
        }

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

        if (isset($data->dependquestion) && ($data->dependquestion > 0)) {
            // Questions using the old dependency system will need to be processed and restored using the new system.
            // See CONTRIB-6787.
            $this->olddependquestions[$newitemid] = $data->dependquestion;
            $this->olddependchoices[$newitemid] = $data->dependchoice;
        }
    }

    /**
     * @param $data
     * @throws dml_exception
     * @throws restore_step_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_fb_sections($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->surveyid = $this->get_new_parentid('feedbackbox_survey');

        // If this feedbackbox has separate sections feedbacks.
        if (isset($data->scorecalculation)) {
            $scorecalculation = unserialize($data->scorecalculation);
            $newscorecalculation = [];
            foreach ($scorecalculation as $qid => $val) {
                $newqid = $this->get_mappingid('feedbackbox_question', $qid);
                $newscorecalculation[$newqid] = $val;
            }
            $data->scorecalculation = serialize($newscorecalculation);
        }

        // Insert the feedbackbox_fb_sections record.
        $newitemid = $DB->insert_record('feedbackbox_fb_sections', $data);
        $this->set_mapping('feedbackbox_fb_sections', $oldid, $newitemid, true);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @throws restore_step_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_feedback($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->sectionid = $this->get_new_parentid('feedbackbox_fb_sections');

        // Insert the feedbackbox_feedback record.
        $newitemid = $DB->insert_record('feedbackbox_feedback', $data);
        $this->set_mapping('feedbackbox_feedback', $oldid, $newitemid, true);
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

        // Some old systems had '' instead of NULL. Change it to NULL.
        if ($data->value === '') {
            $data->value = null;
        }

        // Replace the = separator with :: separator in quest_choice content.
        // This fixes radio button options using old "value"="display" formats.
        if (($data->value == null || $data->value == 'NULL') && !preg_match("/^([0-9]{1,3}=.*|!other=.*)$/",
                $data->content)) {
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
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_dependency($data) {
        $data = (object) $data;

        $data->questionid = $this->get_new_parentid('feedbackbox_question');
        $data->surveyid = $this->get_new_parentid('feedbackbox_survey');

        if (isset($data)) {
            $this->olddependencies[] = $data;
        }
    }

    /**
     * @param $data
     * @return bool
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_attempt($data) {
        // New structure will be completed in process_feedbackbox_response. Nothing to do here any more.
        return true;
    }

    /**
     * @param $data
     * @throws dml_exception
     * @throws restore_step_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response($data) {
        global $DB;

        $data = (object) $data;

        // Older versions of feedbackbox used 'username' instead of 'userid'. If 'username' exists, change it to 'userid'.
        if (isset($data->username) && !isset($data->userid)) {
            $data->userid = $data->username;
        }

        $oldid = $data->id;
        $data->feedbackboxid = $this->get_new_parentid('feedbackbox');
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
    protected function process_feedbackbox_response_bool($data) {
        global $DB;

        $data = (object) $data;
        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);

        // Insert the feedbackbox_response_bool record.
        $DB->insert_record('feedbackbox_response_bool', $data);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response_date($data) {
        global $DB;

        $data = (object) $data;
        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);

        // Insert the feedbackbox_response_date record.
        $DB->insert_record('feedbackbox_response_date', $data);
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
    protected function process_feedbackbox_response_other($data) {
        global $DB;

        $data = (object) $data;
        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);
        $data->choice_id = $this->get_mappingid('feedbackbox_quest_choice', $data->choice_id);

        // Insert the feedbackbox_response_other record.
        $DB->insert_record('feedbackbox_response_other', $data);
    }

    /**
     * @param $data
     * @throws dml_exception
     * @noinspection PhpUnused
     */
    protected function process_feedbackbox_response_rank($data) {
        global $DB;

        $data = (object) $data;

        // Older versions of feedbackbox used 'rank' instead of 'rankvalue'. If 'rank' exists, change it to 'rankvalue'.
        if (isset($data->rank) && !isset($data->rankvalue)) {
            $data->rankvalue = $data->rank;
        }

        $data->response_id = $this->get_new_parentid('feedbackbox_response');
        $data->question_id = $this->get_mappingid('feedbackbox_question', $data->question_id);
        $data->choice_id = $this->get_mappingid('feedbackbox_quest_choice', $data->choice_id);

        // Insert the feedbackbox_response_rank record.
        $DB->insert_record('feedbackbox_response_rank', $data);
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

    /**
     * @throws dml_exception
     */
    protected function after_execute() {
        global $DB;

        // Process any question dependencies after all questions and choices have already been processed to ensure we have all of
        // the new id's.

        // First, process any old system question dependencies into the new system.
        foreach ($this->olddependquestions as $newid => $olddependid) {
            $newrec = new stdClass();
            $newrec->questionid = $newid;
            $newrec->surveyid = $this->get_new_parentid('feedbackbox_survey');
            $newrec->dependquestionid = $this->get_mappingid('feedbackbox_question', $olddependid);
            // Only change mapping for RADIO and DROP question types, not for YESNO question.
            $dependqtype = $DB->get_field('feedbackbox_question', 'type_id', ['id' => $newrec->dependquestionid]);
            if (($dependqtype !== false) && ($dependqtype != 1)) {
                $newrec->dependchoiceid = $this->get_mappingid('feedbackbox_quest_choice',
                    $this->olddependchoices[$newid]);
            } else {
                $newrec->dependchoiceid = $this->olddependchoices[$newid];
            }
            $newrec->dependlogic = 1; // Set to "answer given", previously the only option.
            $newrec->dependandor = 'and'; // Not used previously.
            $DB->insert_record('feedbackbox_dependency', $newrec);
        }

        // Next process all new system dependencies.
        foreach ($this->olddependencies as $data) {
            $data->dependquestionid = $this->get_mappingid('feedbackbox_question', $data->dependquestionid);

            // Only change mapping for RADIO and DROP question types, not for YESNO question.
            $dependqtype = $DB->get_field('feedbackbox_question', 'type_id', ['id' => $data->dependquestionid]);
            if (($dependqtype !== false) && ($dependqtype != 1)) {
                $data->dependchoiceid = $this->get_mappingid('feedbackbox_quest_choice', $data->dependchoiceid);
            }
            $DB->insert_record('feedbackbox_dependency', $data);
        }

        // Add feedbackbox related files, no need to match by itemname (just internally handled context).
        $this->add_related_files('mod_feedbackbox', 'intro', null);
        $this->add_related_files('mod_feedbackbox', 'info', 'feedbackbox_survey');
        $this->add_related_files('mod_feedbackbox', 'thankbody', 'feedbackbox_survey');
        $this->add_related_files('mod_feedbackbox', 'feedbacknotes', 'feedbackbox_survey');
        $this->add_related_files('mod_feedbackbox', 'question', 'feedbackbox_question');
        $this->add_related_files('mod_feedbackbox', 'sectionheading', 'feedbackbox_fb_sections');
        $this->add_related_files('mod_feedbackbox', 'feedback', 'feedbackbox_feedback');
    }
}