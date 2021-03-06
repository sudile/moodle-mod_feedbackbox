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
 * A Message class
 *
 * @package   mod_feedbackbox
 * @copyright 2015 The Open University
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_feedbackbox\message;


use coding_exception;
use core\message\message;
use core_user;
use mod_feedbackbox\feedbackbox;
use moodle_exception;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

class reportavailable extends message {
    private $users = [];

    /**
     * reportavailable constructor.
     *
     * @param $users           array
     * @param $feedbackbox     feedbackbox
     * @param $turnus          stdClass
     * @throws coding_exception
     * @throws moodle_exception
     */
    public function __construct($users, $feedbackbox, $turnus) {
        $this->component = 'mod_feedbackbox';
        $this->name = 'reportavailable';
        $this->userfrom = core_user::get_noreply_user();
        $this->users = $users;
        $url = (new moodle_url('/mod/feedbackbox/report.php',
            ['instance' => $feedbackbox->id, 'turnus' => $turnus->id, 'action' => 'single']))->out(false);
        $full = $feedbackbox->course->fullname;
        $short = $feedbackbox->course->shortname;
        $this->subject = get_string('reportavailable_subject', 'mod_feedbackbox', $short);
        $this->fullmessage = get_string('reportavailable_fullmessage',
            'mod_feedbackbox',
            (object) ['url' => $url, 'coursename' => $full]);
        $this->fullmessagehtml = get_string('reportavailable_fullmessagehtml',
            'mod_feedbackbox',
            (object) ['url' => $url, 'coursename' => $full]);
        $this->smallmessage = get_string('reportavailable_smallmessage', 'mod_feedbackbox', $short);
        $this->contexturlname = get_string('reportavailable_contexturlname', 'mod_feedbackbox');
        $this->fullmessageformat = FORMAT_MARKDOWN;
        $this->notification = 1;
        $this->contexturl = $url;
    }

    /**
     * @throws coding_exception
     */
    public function send() {
        foreach ($this->users as $user) {
            $this->userto = $user;
            if (message_send($this) === false) {
                return false;
            }
        }
        return true;
    }
}