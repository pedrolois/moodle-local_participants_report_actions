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
 * Exports course completion progress (and per-activity state) for the
 * selected participants, as a real CSV/xlsx/ods file.
 *
 * This is a separate export endpoint rather than an attempt to inject a
 * column into core's own Participants report download: table_sql's
 * download pipeline (query_db -> format_row -> exportclass->add_data) has
 * no hook or callback a plugin can use to add data to that file, so the
 * only way to get this data into a real, columned, downloadable file is
 * to build a dedicated endpoint - the same pattern core itself uses for
 * its own "download selected participants" action in user/action_redir.php,
 * reusing \core\dataformat::download_data() rather than writing CSV/xlsx
 * output by hand.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/completionlib.php');

$courseid = required_param('id', PARAM_INT);
$dataformat = required_param('dataformat', PARAM_ALPHA);

require_sesskey();

// Participants report checkboxes are posted as individual "user123" fields
// (see \core_user\table\participants::col_select()), not as an array -
// same convention core's own user/action_redir.php reads them with.
$userids = [];
if ($post = data_submitted()) {
    foreach ($post as $key => $value) {
        if (preg_match('/^user(\d+)$/', $key, $matches)) {
            $userids[] = (int) $matches[1];
        }
    }
}

$course = get_course($courseid);
$context = context_course::instance($courseid);
require_login($course);
require_capability('moodle/course:viewparticipants', $context);
require_capability('local/participants_report_actions:export', $context);

if (!get_config('local_participants_report_actions', 'enableexport')) {
    throw new moodle_exception('exportdisabled', 'local_participants_report_actions');
}

// The posted ids come from the browser: only export users who are
// participants of this course and visible to the current user.
$userids = \local_participants_report_actions\local\participants::filter_visible_userids($course, $context, $userids);

if (empty($userids)) {
    redirect(new moodle_url('/user/index.php', ['id' => $courseid]), get_string('noselectedusers', 'bulkusers'));
}

list($insql, $inparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
$namefields = implode(', ', \core_user\fields::get_name_fields());
$users = $DB->get_records_select(
    'user',
    "id $insql AND deleted = 0",
    $inparams,
    '',
    "id, email, $namefields"
);

$completioninfo = new completion_info($course);
$activities = $completioninfo->get_activities();
$progressbyuser = [];
if (!empty($activities)) {
    foreach ($completioninfo->get_progress_all() as $userid => $userprogress) {
        $progressbyuser[$userid] = $userprogress->progress ?? [];
    }
}

// Only export the email when it is one of the identity fields the current
// user may see here (site "showuseridentity" setting plus
// moodle/site:viewuseridentity), same rule core's own participant
// download follows.
$includeemail = in_array('email', \core_user\fields::get_identity_fields($context), true);

$columnnames = [
    'userid' => get_string('exportcolumnuserid', 'local_participants_report_actions'),
    'fullname' => get_string('exportcolumnfullname', 'local_participants_report_actions'),
];
if ($includeemail) {
    $columnnames['email'] = get_string('email');
}
$columnnames['progresspercent'] = get_string('exportcolumnprogresspercent', 'local_participants_report_actions');
foreach ($activities as $cmid => $cm) {
    $columnnames['activity' . $cmid] = $cm->get_formatted_name();
}

$rows = [];
foreach ($userids as $userid) {
    if (!isset($users[$userid])) {
        continue;
    }

    $progress = \core_completion\progress::get_course_progress_percentage($course, $userid);

    $record = new stdClass();
    $record->userid = $userid;
    $record->fullname = fullname($users[$userid]);
    if ($includeemail) {
        $record->email = $users[$userid]->email;
    }
    $record->progresspercent = $progress === null ? '' : round($progress) . '%';

    foreach ($activities as $cmid => $cm) {
        $completiondata = $progressbyuser[$userid][$cmid] ?? null;
        $state = $completiondata->completionstate ?? COMPLETION_INCOMPLETE;
        $record->{'activity' . $cmid} = in_array($state, [COMPLETION_COMPLETE, COMPLETION_COMPLETE_PASS])
            ? get_string('activitycompleted', 'local_participants_report_actions')
            : get_string('activitynotcompleted', 'local_participants_report_actions');
    }

    $rows[] = $record;
}

\core\dataformat::download_data(
    'course_' . $courseid . '_progress',
    $dataformat,
    $columnnames,
    $rows
);
