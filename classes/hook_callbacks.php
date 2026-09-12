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

namespace local_participants_report_actions;

use core\hook\output\before_footer_html_generation;

/**
 * Hook callbacks.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class hook_callbacks {
    /**
     * Loads the AMD module on the course participants page.
     *
     * Injects an "Actions" column into the Participants report (email,
     * message, log in as this user, course progress, course badges)
     * without modifying core files. The actual per-user data is fetched
     * by the JS via the
     * local_participants_report_actions_get_participant_extras web
     * service, batched for all rows on the current page/refresh.
     *
     * The "log in as" icon reuses core's own course/loginas.php for the
     * actual capability/sesskey/admin-protection checks - no new
     * capability, no duplicated privilege logic.
     *
     * @param before_footer_html_generation $hook
     */
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        global $PAGE, $COURSE;

        if ($PAGE->pagetype !== 'course-view-participants') {
            return;
        }

        if (empty($COURSE->id) || $COURSE->id == SITEID) {
            return;
        }

        $coursecontext = \context_course::instance($COURSE->id);

        if (!has_capability('moodle/course:viewparticipants', $coursecontext)) {
            return;
        }

        // Gated by BOTH moodle/user:loginas (core's real authority - it
        // re-checks this itself in course/loginas.php regardless of what we
        // do here) AND our own capability + site setting, so an admin can
        // hide this shortcut for some roles without touching login-as
        // permissions site-wide.
        $loginasurltemplate = null;
        $showloginas = get_config('local_participants_report_actions', 'enableloginas')
            && has_capability('local/participants_report_actions:loginas', $coursecontext)
            && has_capability('moodle/user:loginas', $coursecontext)
            && !\core\session\manager::is_loggedinas();
        if ($showloginas) {
            // Build the exact same URL core uses for the row-level login-as link
            // (see \course\loginas.php), with a placeholder for the userid that
            // the JS substitutes per row. The JS never constructs URLs itself.
            $urltemplate = new \moodle_url('/course/loginas.php', [
                'id' => $COURSE->id,
                'user' => '__USERID__',
                'sesskey' => sesskey(),
            ]);
            $loginasurltemplate = $urltemplate->out(false);
        }

        // This plugin's own colour settings - independent of whether
        // block_completion_progress is even installed. Defaults match that
        // block's own defaults purely so the two look the same out of the
        // box for sites that happen to have both.
        $statecolours = [
            'completed' => get_config('local_participants_report_actions', 'colourcompleted') ?: '#6A9C35',
            'submitted' => get_config('local_participants_report_actions', 'coloursubmitted') ?: '#FFCC00',
            'notcompleted' => get_config('local_participants_report_actions', 'colournotcompleted') ?: '#C71C22',
            'futurenotcompleted' => get_config('local_participants_report_actions', 'colourfuturenotcompleted')
                ?: '#025187',
        ];

        // Base URL for our own progress export endpoint. The JS repoints
        // core's existing "Download table data as" options at this URL
        // (appending "&dataformat=<name>" per option) instead of adding a
        // second, duplicate menu group. Null leaves those options alone,
        // downloading core's normal participants data as usual.
        $exporturl = null;
        if (get_config('local_participants_report_actions', 'enableexport')
                && has_capability('local/participants_report_actions:export', $coursecontext)) {
            $url = new \moodle_url('/local/participants_report_actions/export_progress.php', [
                'id' => $COURSE->id,
                'sesskey' => sesskey(),
            ]);
            $exporturl = $url->out(false);
        }

        $PAGE->requires->js_call_amd('local_participants_report_actions/rowactions', 'init', [
            $COURSE->id,
            $loginasurltemplate,
            $statecolours,
            $exporturl,
        ]);
    }
}
