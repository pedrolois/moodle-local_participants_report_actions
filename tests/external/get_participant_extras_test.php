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
 * Tests for the get_participant_extras external function: the batched
 * lookup that feeds the Actions column (email, message, login-as data,
 * course progress, per-activity state, badges).
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_participants_report_actions\external;

/**
 * Tests for the get_participant_extras external function.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_participants_report_actions\external\get_participant_extras
 */
final class get_participant_extras_test extends \advanced_testcase {

    /**
     * A course with completion enabled and one manually-completable page.
     *
     * @return array{course: \stdClass, cm: \cm_info, teacher: \stdClass, student: \stdClass}
     */
    private function create_course_with_completion(): array {
        $this->setAdminUser();
        set_config('enablecompletion', 1);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['enablecompletion' => 1]);

        $page = $generator->create_module('page', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $student = $generator->create_user();
        $generator->enrol_user($student->id, $course->id, 'student');

        $modinfo = get_fast_modinfo($course);
        $cm = $modinfo->get_cm($page->cmid);

        return [
            'course' => $course,
            'cm' => $cm,
            'teacher' => $teacher,
            'student' => $student,
        ];
    }

    /**
     * Grant this plugin's capabilities (and enable the matching settings,
     * which default to on) to a role in a course context.
     *
     * @param string $capability
     * @param int $roleid
     * @param \context $context
     */
    private function grant(string $capability, int $roleid, \context $context): void {
        assign_capability($capability, CAP_ALLOW, $roleid, $context->id);
        $context->mark_dirty();
    }

    /**
     * A user without moodle/course:viewparticipants cannot call this at all.
     */
    public function test_requires_viewparticipants_capability(): void {
        $this->resetAfterTest(true);
        ['course' => $course, 'student' => $student] = $this->create_course_with_completion();

        $this->setUser($student);
        $context = \context_course::instance($course->id);
        // Explicitly deny it, in case the Student archetype ever changes.
        assign_capability(
            'moodle/course:viewparticipants',
            CAP_PROHIBIT,
            $this->get_role_id('student'),
            $context->id
        );
        $context->mark_dirty();

        $this->expectException(\required_capability_exception::class);
        get_participant_extras::execute($course->id, [$student->id]);
    }

    /**
     * Small helper: fetch a role id by shortname.
     *
     * @param string $shortname
     * @return int
     */
    private function get_role_id(string $shortname): int {
        global $DB;
        return (int) $DB->get_field('role', 'id', ['shortname' => $shortname]);
    }

    /**
     * Email is only included when both the setting and capability allow it;
     * otherwise the field is present but empty, never the real address.
     */
    public function test_email_respects_setting_and_capability(): void {
        $this->resetAfterTest(true);
        ['course' => $course, 'teacher' => $teacher, 'student' => $student] = $this->create_course_with_completion();
        $context = \context_course::instance($course->id);
        $this->grant('local/participants_report_actions:sendemail', $this->get_role_id('editingteacher'), $context);

        $this->setUser($teacher);

        // Capability granted, setting on by default => email visible.
        $result = get_participant_extras::execute($course->id, [$student->id]);
        $this->assertSame($student->email, $result[0]['email']);

        // Turn the site-wide setting off => email hidden even with the capability.
        set_config('enablesendemail', 0, 'local_participants_report_actions');
        $result = get_participant_extras::execute($course->id, [$student->id]);
        $this->assertSame('', $result[0]['email']);
    }

    /**
     * A user is never offered the ability to message themselves.
     */
    public function test_cannot_message_self(): void {
        $this->resetAfterTest(true);
        ['course' => $course, 'teacher' => $teacher] = $this->create_course_with_completion();
        $context = \context_course::instance($course->id);
        $this->grant('local/participants_report_actions:sendmessage', $this->get_role_id('editingteacher'), $context);

        $this->setUser($teacher);
        $result = get_participant_extras::execute($course->id, [$teacher->id]);

        $this->assertFalse($result[0]['canmessage']);
    }

    /**
     * Course progress is null when the viewprogress capability/setting is
     * off, and a real percentage when a tracked activity is completed.
     */
    public function test_progress_percentage_and_activity_state(): void {
        $this->resetAfterTest(true);
        ['course' => $course, 'cm' => $cm, 'teacher' => $teacher, 'student' => $student] =
            $this->create_course_with_completion();
        $context = \context_course::instance($course->id);
        $this->grant('local/participants_report_actions:viewprogress', $this->get_role_id('editingteacher'), $context);

        $this->setUser($teacher);

        // Nobody has completed anything yet: progress is 0, activity is
        // "futurenotcompleted" (no due date, not yet done).
        $result = get_participant_extras::execute($course->id, [$student->id]);
        $this->assertSame(0.0, $result[0]['progress']);
        $this->assertCount(1, $result[0]['activities']);
        $this->assertEquals($cm->id, $result[0]['activities'][0]['cmid']);
        $this->assertSame('futurenotcompleted', $result[0]['activities'][0]['state']);

        // Mark the activity complete for the student.
        $completion = new \completion_info($course);
        $completion->update_state($cm, COMPLETION_COMPLETE, $student->id);

        $result = get_participant_extras::execute($course->id, [$student->id]);
        $this->assertSame(100.0, $result[0]['progress']);
        $this->assertSame('completed', $result[0]['activities'][0]['state']);
    }

    /**
     * An activity overdue (completionexpected in the past) and still
     * incomplete is reported as "notcompleted", not "futurenotcompleted".
     */
    public function test_overdue_activity_is_notcompleted(): void {
        global $DB;

        $this->resetAfterTest(true);
        ['course' => $course, 'cm' => $cm, 'teacher' => $teacher, 'student' => $student] =
            $this->create_course_with_completion();
        $context = \context_course::instance($course->id);
        $this->grant('local/participants_report_actions:viewprogress', $this->get_role_id('editingteacher'), $context);

        $DB->set_field('course_modules', 'completionexpected', time() - DAYSECS, ['id' => $cm->id]);
        rebuild_course_cache($course->id, true);

        $this->setUser($teacher);
        $result = get_participant_extras::execute($course->id, [$student->id]);

        $this->assertSame('notcompleted', $result[0]['activities'][0]['state']);
    }

    /**
     * A course badge earned by a user is returned with its name and the
     * exact uniquehash used to build /badges/badge.php?hash=...
     */
    public function test_earned_badge_is_returned_with_hash(): void {
        global $CFG, $DB;

        require_once($CFG->libdir . '/badgeslib.php');

        $this->resetAfterTest(true);
        ['course' => $course, 'teacher' => $teacher, 'student' => $student] = $this->create_course_with_completion();
        $context = \context_course::instance($course->id);
        $this->grant('local/participants_report_actions:viewbadges', $this->get_role_id('editingteacher'), $context);

        set_config('enablebadges', 1);
        set_config('badges_allowcoursebadges', 1);

        $badgeid = $DB->insert_record('badge', [
            'name' => 'Course champion',
            'description' => '',
            'timecreated' => time(),
            'timemodified' => time(),
            'usercreated' => $teacher->id,
            'usermodified' => $teacher->id,
            'issuername' => 'Test',
            'issuerurl' => '',
            'issuercontact' => '',
            'expiredate' => null,
            'expireperiod' => null,
            'type' => BADGE_TYPE_COURSE,
            'courseid' => $course->id,
            'message' => '',
            'messagesubject' => '',
            'attachment' => 1,
            'notification' => 1,
            'status' => BADGE_STATUS_ACTIVE,
            'nextcron' => null,
            'version' => '2',
            'language' => 'en',
        ]);

        $hash = sha1($badgeid . $student->id . time());
        $DB->insert_record('badge_issued', [
            'badgeid' => $badgeid,
            'userid' => $student->id,
            'uniquehash' => $hash,
            'dateissued' => time(),
            'dateexpire' => null,
            'visible' => 1,
        ]);

        $this->setUser($teacher);
        $result = get_participant_extras::execute($course->id, [$student->id]);

        $this->assertCount(1, $result[0]['badges']);
        $this->assertSame('Course champion', $result[0]['badges'][0]['name']);
        $this->assertSame($hash, $result[0]['badges'][0]['hash']);
    }

    /**
     * A user with no badges gets an empty array, not null or an error.
     */
    public function test_no_badges_returns_empty_array(): void {
        $this->resetAfterTest(true);
        ['course' => $course, 'teacher' => $teacher, 'student' => $student] = $this->create_course_with_completion();
        $context = \context_course::instance($course->id);
        $this->grant('local/participants_report_actions:viewbadges', $this->get_role_id('editingteacher'), $context);

        $this->setUser($teacher);
        $result = get_participant_extras::execute($course->id, [$student->id]);

        $this->assertSame([], $result[0]['badges']);
    }

    /**
     * Passing an empty userids list returns an empty array without error.
     */
    public function test_empty_userids_returns_empty_array(): void {
        $this->resetAfterTest(true);
        ['course' => $course, 'teacher' => $teacher] = $this->create_course_with_completion();

        $this->setUser($teacher);
        $result = get_participant_extras::execute($course->id, []);

        $this->assertSame([], $result);
    }
}
