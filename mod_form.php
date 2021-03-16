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
 * print the form to add or edit a feedbackbox-instance
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package feedbackbox
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');
require_once($CFG->dirroot . '/mod/feedbackbox/locallib.php');

class mod_feedbackbox_mod_form extends moodleform_mod {

    public function data_preprocessing(&$defaultvalues) {
        return;
    }

    /**
     * Enforce validation rules here
     *
     * @param array $data  array of ("fieldname"=>value) of submitted data
     * @param array $files array of uploaded files "element_name"=>tmp_file_path
     * @return array
     * @throws coding_exception
     * @throws dml_exception
     */
    public function validation($data, $files) {
        global $DB;
        $errors = parent::validation($data, $files);
        if ($this->get_instance() != 0 && $this->get_instance() != null && $this->get_instance() != '') {
            $entry = $DB->get_record('feedbackbox', ['id' => $this->get_instance()], '*', MUST_EXIST);
            $data['opendate'] = $entry->opendate;
        }
        // Check open and close times are consistent.
        if ($data['opendate'] && $data['closedate'] &&
            $data['closedate'] < $data['opendate']) {
            $errors['closedate'] = get_string('closebeforeopen', 'mod_feedbackbox');
        }
        return $errors;
    }

    /**
     * @return array
     * @throws coding_exception
     */
    public function add_completion_rules() {
        $mform =& $this->_form;
        $mform->addElement('checkbox', 'completionsubmit', '', get_string('completionsubmit', 'feedbackbox'));
        return ['completionsubmit'];
    }

    /**
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionsubmit']);
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function definition() {
        global $DB;
        $mform =& $this->_form;
        $mform->addElement('header', 'general', get_string('general', 'form'));
        $this->standard_intro_elements();
        $mform->addElement('select',
            'turnus',
            get_string('turnus', 'mod_feedbackbox'),
            [
                1 => get_string('turnusonce', 'mod_feedbackbox'),
                2 => get_string('turnusweek', 'mod_feedbackbox'),
                3 => get_string('turnusweek2', 'mod_feedbackbox'),
                4 => get_string('turnusweek3', 'mod_feedbackbox')
            ]);
        $mform->setDefault('turnus', 2);
        if ($this->get_instance() != 0 && $this->get_instance() != null && $this->get_instance() != '') {
            $entry = $DB->get_record('feedbackbox', ['id' => $this->get_instance()], '*', MUST_EXIST);
            $mform->addElement('static',
                'opendate1',
                get_string('opendate', 'mod_feedbackbox'),
                date('d.m.Y H:i:s', $entry->opendate));
        } else {
            $mform->addElement('date_time_selector',
                'opendate',
                get_string('opendate', 'mod_feedbackbox'),
                ['optional' => false]);
        }

        $mform->addElement('date_time_selector',
            'closedate',
            get_string('closedate', 'mod_feedbackbox'),
            ['optional' => false]);

        $mform->setDefault('closedate', strtotime("+3 months", time()));

        $mform->addElement('checkbox',
            'notifystudents',
            get_string('notifystudents', 'mod_feedbackbox'),
            get_string('notifystudents_help', 'mod_feedbackbox'));
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

}