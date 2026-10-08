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

namespace local_participants_report_actions\local;

use context_course;
use stdClass;

/**
 * Helpers shared by the web service and the progress export.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class participants {
    /**
     * Reduce a list of requested user ids to the ones the current user can
     * actually see on this course's Participants report: users enrolled in
     * the course (any enrolment status, as the report itself lists them)
     * and, in separate groups mode without moodle/site:accessallgroups,
     * only members of the current user's own groups.
     *
     * The ids come from the client, so they must never be trusted as-is:
     * without this, any user id on the site could be passed in to read
     * that user's email, progress, or badges.
     *
     * @param stdClass $course
     * @param context_course $context
     * @param int[] $userids
     * @return int[] The subset of $userids that are visible participants, in the original order.
     */
    public static function filter_visible_userids(stdClass $course, context_course $context, array $userids): array {
        global $DB, $USER;

        $userids = array_values(array_unique(array_map('intval', $userids)));
        if (empty($userids)) {
            return [];
        }

        $groupids = 0;
        if (groups_get_course_groupmode($course) == SEPARATEGROUPS
                && !has_capability('moodle/site:accessallgroups', $context)) {
            $groupids = array_keys(groups_get_all_groups($course->id, $USER->id, $course->defaultgroupingid, 'g.id'));
            if (empty($groupids)) {
                return [];
            }
        }

        [$enrolledsql, $enrolledparams] = get_enrolled_sql($context, '', $groupids, false);
        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'prauid');

        $visible = $DB->get_fieldset_sql(
            "SELECT u.id
               FROM {user} u
               JOIN ($enrolledsql) je ON je.id = u.id
              WHERE u.id $insql AND u.deleted = 0",
            array_merge($enrolledparams, $inparams)
        );
        $visible = array_flip(array_map('intval', $visible));

        return array_values(array_filter($userids, fn(int $userid): bool => isset($visible[$userid])));
    }
}
