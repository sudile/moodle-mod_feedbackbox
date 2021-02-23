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
 * A scheduled task for feedbackbox.
 *
 * @package   mod_feedbackbox
 * @copyright 2015 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_feedbackbox\task;

use coding_exception;
use context_module;
use core\task\scheduled_task;
use dml_exception;
use mod_feedbackbox\feedbackbox;
use mod_feedbackbox\message\reportavailable;
use mod_feedbackbox\message\turnusavailable;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

class notification extends scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     * @throws coding_exception
     */
    public function get_name() {
        return get_string('notification', 'mod_feedbackbox');
    }

    /**
     * @throws dml_exception
     * @throws moodle_exception
     * @throws coding_exception
     */
    public function execute() {
        global $DB;
        $last = $this->get_last_run_time();
        $spanbetween = (time() - $last); // Can be a day or more (dynamic is always better).
        $boxes = $DB->get_records_sql('SELECT * FROM {feedbackbox} WHERE closedate > ?', [$last]);
        foreach ($boxes as $box) {
            $studentsnotified = $box->notifystudents == 0; // Will disable mail sending for users
            $teachersnotified = get_config('feedbackbox',
                    'allowemailreporting') != 1; // Will disable mail sending for teachers
            if ($studentsnotified && $teachersnotified) {
                continue;
            }
            $start = $box->opendate;
            $end = $box->closedate;
            if ($end < (time() - $spanbetween)) {
                continue;
            }
            $participants = [];
            $teachers = [];
            $course = $DB->get_record('course', ['id' => $box->course]);
            $cm = get_coursemodule_from_instance('feedbackbox', $box->id);
            // Get zones
            $instance = new feedbackbox($box->id, $box, $course, $cm);
            $courseusers = enrol_get_course_users($box->course, true);
            foreach ($courseusers as $user) {
                if (has_capability('mod/feedbackbox:manage', context_module::instance($cm->id), $user->id)) {
                    $teachers[] = $user;
                } else {
                    $participants[] = $user;
                }
            }
            $zones = $instance->get_turnus_zones();
            if ($last < $start && !$studentsnotified) {
                $message = new turnusavailable($participants, $instance);
                $message->send();
                // Send Message to students. (Feedbackbox got started after the last task run so it needs to fire).
                $studentsnotified = true;
            }
            if ($last > $end && $end > (time() - $spanbetween) && !$teachersnotified) {
                $message = new reportavailable($teachers, $instance, end($zones));
                $message->send();
                // Send Message to teachers. (Feedbackbox ended after the last task run so it needs to fire).
                $teachersnotified = true;
            }

            $lastzone = $instance->get_current_turnus($last);
            $currentzone = $instance->get_current_turnus();
            if ($currentzone === false && !$teachersnotified) {
                $message = new reportavailable($teachers, $instance, end($zones));
                $message->send();
                $teachersnotified = true;
            }
            if ($last < $currentzone->from && !$studentsnotified) {
                $message = new turnusavailable($participants, $instance);
                $message->send();
            }
            if ($lastzone !== false && !$teachersnotified) {
                // Possible if there is no last round.
                // 1. Multiple round but none processed.
                // 2. Single turnus.
                if ($last > $lastzone->to && $lastzone->to > (time() - $spanbetween)) {
                    $message = new reportavailable($teachers, $instance, $lastzone);
                    $message->send();
                }
            }
        }
    }
}