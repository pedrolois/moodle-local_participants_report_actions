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

namespace local_participants_report_actions\external;

use context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_participants_report_actions\local\participants;

/**
 * Batched lookup of per-participant extras (email, messaging, progress, badges)
 * for the row action column added to the course Participants report.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_participant_extras extends external_api {
    /**
     * Parameters structure for web service.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'userids' => new external_multiple_structure(
                new external_value(PARAM_INT, 'User id'),
                'User ids currently visible in the participants table page'
            ),
        ]);
    }

    /**
     * Batched lookup of extras for the given users in the given course.
     *
     * @param int $courseid
     * @param int[] $userids
     * @return array
     */
    public static function execute(int $courseid, array $userids): array {
        global $CFG, $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'userids' => $userids,
        ]);
        $courseid = $params['courseid'];
        $userids = $params['userids'];

        $course = get_course($courseid);
        $context = context_course::instance($courseid);
        self::validate_context($context);

        require_capability('moodle/course:viewparticipants', $context);

        // Only ever return data for users who are participants of this
        // course (and visible to the caller under the course's group mode).
        $userids = participants::filter_visible_userids($course, $context, $userids);

        if (empty($userids)) {
            return [];
        }

        // Each feature only runs when it is both enabled site-wide (admin
        // setting) and the current user holds the matching capability in
        // this course context - checked once here, rather than per row.
        // Email additionally follows core's own identity rule (site
        // "showuseridentity" setting plus moodle/site:viewuseridentity), so
        // this plugin never shows an email the Participants report itself
        // would hide.
        $showemail = get_config('local_participants_report_actions', 'enablesendemail')
            && has_capability('local/participants_report_actions:sendemail', $context)
            && in_array('email', \core_user\fields::get_identity_fields($context), true);
        $showmessage = get_config('local_participants_report_actions', 'enablesendmessage')
            && has_capability('local/participants_report_actions:sendmessage', $context);
        $showprogress = get_config('local_participants_report_actions', 'enableprogress')
            && has_capability('local/participants_report_actions:viewprogress', $context);
        $showbadges = get_config('local_participants_report_actions', 'enablebadges')
            && has_capability('local/participants_report_actions:viewbadges', $context);

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Batch-fetch emails and name fields in one query, same spirit as
        // core's get_users_roles().
        $namefields = implode(', ', \core_user\fields::get_name_fields());
        $users = $DB->get_records_select(
            'user',
            "id $insql AND deleted = 0",
            $inparams,
            '',
            "id, email, $namefields"
        );

        // Batch-fetch course badges for these users in one query (no bulk core API exists).
        // Only the first (most recent) badge per user is used - the icon
        // links to that one badge's page, it doesn't list every badge.
        $badgesbyuser = [];
        if ($showbadges && !empty($CFG->enablebadges) && !empty($CFG->badges_allowcoursebadges)) {
            $badgesql = "SELECT bi.id AS issuedid, bi.userid, b.name, bi.uniquehash
                           FROM {badge_issued} bi
                           JOIN {badge} b ON b.id = bi.badgeid
                          WHERE b.courseid = :courseid AND bi.userid $insql
                       ORDER BY bi.dateissued DESC";
            $badgeparams = array_merge(['courseid' => $courseid], $inparams);
            foreach ($DB->get_records_sql($badgesql, $badgeparams) as $row) {
                $badgesbyuser[$row->userid][] = [
                    'name' => $row->name,
                    'hash' => $row->uniquehash,
                ];
            }
        }

        $messagingenabled = $showmessage && !empty($CFG->messaging);

        // Batch-fetch per-activity completion state for all tracked users in
        // the course in one query (completion_info has no per-userid-list
        // filter, so we fetch for all tracked users and pick out the ones we
        // need - still a single query rather than one per row).
        $activities = [];
        $progressbyuser = [];
        $submissionsbyusercm = [];
        if ($showprogress) {
            require_once($CFG->libdir . '/completionlib.php');
            $completioninfo = new \completion_info($course);
            $activities = $completioninfo->get_activities();
            if (!empty($activities)) {
                foreach ($completioninfo->get_progress_all() as $userid => $userprogress) {
                    $progressbyuser[$userid] = $userprogress->progress ?? [];
                }

                // Batch-fetch assign submissions (individual submissions only,
                // same spirit as block_completion_progress's "submitted but
                // not yet graded" state), so the modal can show an
                // intermediate amber state instead of just complete/incomplete.
                $submissionsql = "SELECT " . $DB->sql_concat('s.userid', "'-'", 'c.id') . " AS uniqueid,
                                          s.userid, c.id AS cmid,
                                          MAX(CASE WHEN ag.grade IS NULL OR ag.grade = -1 THEN 0 ELSE 1 END) AS graded
                                     FROM {assign_submission} s
                                     JOIN {assign} a ON s.assignment = a.id
                                     JOIN {course_modules} c ON c.instance = a.id
                                     JOIN {modules} m ON m.name = 'assign' AND m.id = c.module
                                LEFT JOIN {assign_grades} ag ON ag.assignment = s.assignment
                                          AND ag.attemptnumber = s.attemptnumber AND ag.userid = s.userid
                                    WHERE s.latest = 1 AND s.status = 'submitted' AND a.teamsubmission = 0
                                          AND a.course = :courseid AND s.userid $insql
                                 GROUP BY s.userid, c.id";
                $submissionparams = array_merge(['courseid' => $courseid], $inparams);
                foreach ($DB->get_records_sql($submissionsql, $submissionparams) as $row) {
                    $submissionsbyusercm[$row->userid][$row->cmid] = (bool) $row->graded;
                }
            }
        }

        $result = [];
        foreach ($userids as $userid) {
            if (!isset($users[$userid])) {
                continue;
            }

            // Get_course_progress_percentage() itself returns null when completion
            // tracking is disabled or unsupported for this course, no need to
            // pre-check is_enabled() ourselves.
            $progress = null;
            if ($showprogress) {
                $progress = \core_completion\progress::get_course_progress_percentage($course, $userid);
            }

            $canmessagethisuser = false;
            if ($messagingenabled && $userid != $USER->id) {
                // Can_send_message() takes (recipientid, senderid).
                $canmessagethisuser = \core_message\api::can_send_message($userid, $USER->id);
            }

            $useractivities = [];
            foreach ($activities as $cmid => $cm) {
                $completiondata = $progressbyuser[$userid][$cmid] ?? null;
                $state = $completiondata->completionstate ?? COMPLETION_INCOMPLETE;
                $submitted = $submissionsbyusercm[$userid][$cmid] ?? null;

                // Same state precedence as block_completion_progress: a
                // submission not yet reflected in a final completion state
                // shows as "submitted" (amber) rather than complete/incomplete.
                if ($state == COMPLETION_INCOMPLETE && $submitted !== null) {
                    $displaystate = 'submitted';
                } else if ($state == COMPLETION_COMPLETE_FAIL && $submitted === false) {
                    $displaystate = 'submitted';
                } else if ($state == COMPLETION_COMPLETE || $state == COMPLETION_COMPLETE_PASS) {
                    $displaystate = 'completed';
                } else if (
                    $state == COMPLETION_COMPLETE_FAIL ||
                    (!empty($cm->completionexpected) && $cm->completionexpected < time())
                ) {
                    $displaystate = 'notcompleted';
                } else {
                    $displaystate = 'futurenotcompleted';
                }

                $cmurl = $cm->get_url();
                $useractivities[] = [
                    'cmid' => $cmid,
                    'name' => $cm->get_formatted_name(),
                    'state' => $displaystate,
                    'url' => $cmurl ? $cmurl->out(false) : null,
                ];
            }

            $result[] = [
                'userid' => $userid,
                'fullname' => fullname($users[$userid]),
                'email' => $showemail ? $users[$userid]->email : '',
                'canmessage' => $canmessagethisuser,
                'progress' => $progress === null ? null : (float) $progress,
                'activities' => $useractivities,
                'badges' => $badgesbyuser[$userid] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Parameters structure for web service.
     *
     * @return external_multiple_structure
     */
    public static function execute_returns(): external_multiple_structure {
        return new external_multiple_structure(
            new external_single_structure([
                'userid' => new external_value(PARAM_INT, 'User id'),
                'fullname' => new external_value(PARAM_NOTAGS, 'User full name'),
                'email' => new external_value(PARAM_RAW, 'User email'),
                'canmessage' => new external_value(PARAM_BOOL, 'Whether the current user can message this user'),
                'progress' => new external_value(
                    PARAM_FLOAT,
                    'Course completion percentage (0-100), or null',
                    VALUE_OPTIONAL,
                    null,
                    NULL_ALLOWED
                ),
                'activities' => new external_multiple_structure(
                    new external_single_structure([
                        'cmid' => new external_value(PARAM_INT, 'Course module id'),
                        'name' => new external_value(PARAM_RAW, 'Activity name'),
                        'state' => new external_value(
                            PARAM_ALPHA,
                            'One of: completed, submitted, notcompleted, futurenotcompleted'
                        ),
                        'url' => new external_value(
                            PARAM_URL,
                            'URL to view the activity, or null if it has none',
                            VALUE_OPTIONAL,
                            null,
                            NULL_ALLOWED
                        ),
                    ]),
                    'Per-activity completion state, for activities with completion tracking enabled'
                ),
                'badges' => new external_multiple_structure(
                    new external_single_structure([
                        'name' => new external_value(PARAM_TEXT, 'Badge name'),
                        'hash' => new external_value(PARAM_ALPHANUM, 'Unique hash, for /badges/badge.php?hash='),
                    ]),
                    'Course badges earned by this user'
                ),
            ])
        );
    }
}
