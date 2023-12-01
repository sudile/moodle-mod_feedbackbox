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
 * This file contains the parent class for text question types.
 *
 * @author  Mike Churchward
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 * @package questiontypes
 */

namespace mod_feedbackbox;


defined('MOODLE_INTERNAL') || die();

class translate {

    static $translationmap = [];

    public static function patch($string) {
        $mapradio = [
            'Hy&shy;per&shy;ga&shy;lak&shy;tisch gut!' => 'radio_good',
            'Läuft wie ge&shy;schmiert.' => 'radio_runs',
            'Es ist okay.' => 'radio_okay',
            'Hilfe - Ich komme gar nicht klar!' => 'radio_help'
        ];
        $mapheading = [
            '<p><h4>Schritt 1/3:<br/><b>Hey, wie kommst du im Kurs zurecht?</b></h4></p>' => 'heading_stepone',
            '<h4>Schritt 2/3:<br/><b>Was läuft gut?</b></h4>' => 'heading_steptwo',
            '<h4>Schritt 3/3:<br/><b>Was könnte besser laufen?</b></h4>' => 'heading_stepthree'
        ];
        $mapcheck = [
            'Ablaufplan' => 'check_schedule',
            'Anforderungsniveau' => 'check_requirementlevel',
            'Aufgabenumfang' => 'check_scopeoftasks',
            'Prüfungsleistung' => 'check_examinationperformance',
            'Zeitumfang' => 'check_timeframe',
            'Pünktlichkeit' => 'check_punctuality',
            'Pausen' => 'check_breaks',
            'Teilnehmendenanzahl' => 'check_numberofparticipants',
            'Technischer Zugang' => 'check_technicalaccess',
            'Medienformate' => 'check_mediaformats',
            'Materialien' => 'check_materials',
            'Betreuung' => 'check_supervision',
            'Feedbackkultur' => 'check_feedbackculture',
            'Themenschwerpunkte' => 'check_maintopics',
            'Fachliche Tiefe' => 'check_technicaldepth',
            'Roter Faden' => 'check_redthread',
            'Verständlichkeit' => 'check_comprehensibility',
            'Anwesenheit' => 'check_attendance',
            'Beteiligung' => 'check_participation',
            'Motivation' => 'check_motivation',
            'Zwischenmenschliches' => 'check_interpersonal',
            'Sonstiges' => 'check_other',
        ];
        $mapsubheadings = [
            '<p>STRUKTUR &amp; WORKLOAD<br></p>' => 'subheading_structureworkload',
            '<p>ORGANISATORISCHES<br></p>' => 'subheading_organizational',
            '<p>MEDIEN &amp; KOMMUNIKATION<br></p>' => 'subheading_mediacommunication',
            '<p>INHALTE<br></p>' => 'subheading_content',
            '<p>GRUPPENDYNAMIK &amp; WEITERES<br></p>' => 'subheading_groupdynamicother',
            '<p><h4><b>Kannst du das genauer erklären?</b></h4></p>' => 'subheading_explaindetails'
        ];

        $list = $mapradio + $mapheading + $mapcheck + $mapsubheadings;
        foreach ($list as $search => $newvalue) {
            if ($search === $string) {
                return get_string($newvalue, 'mod_feedbackbox');
            }
        }
        // Mappings here.
        return $string; // "THIS IS A TEST TEXT";
    }
}