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
 * Tests for the plugin's own capabilities: default role assignment, and
 * that has_capability() responds correctly when a capability is granted
 * to or revoked from a role.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_participants_report_actions;

/**
 * Tests for the plugin's own capabilities.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_participants_report_actions\hook_callbacks
 */
final class capabilities_test extends \advanced_testcase {

    /**
     * All capabilities this plugin defines.
     *
     * @return string[]
     */
    private function all_capabilities(): array {
        return [
            'local/participants_report_actions:sendemail',
            'local/participants_report_actions:sendmessage',
            'local/participants_report_actions:loginas',
            'local/participants_report_actions:viewprogress',
            'local/participants_report_actions:viewbadges',
            'local/participants_report_actions:export',
        ];
    }

    /**
     * Every capability this plugin defines is registered in the database.
     */
    public function test_capabilities_are_registered(): void {
        global $DB;

        foreach ($this->all_capabilities() as $capability) {
            $this->assertTrue(
                $DB->record_exists('capabilities', ['name' => $capability]),
                "$capability should be registered in the capabilities table"
            );
        }
    }

    /**
     * By default, only the Manager archetype is granted these capabilities;
     * Teacher and Student are not, until an admin extends them.
     */
    public function test_manager_has_capabilities_by_default(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $manager = $generator->create_user();
        $generator->role_assign('manager', $manager->id, $context->id);

        foreach ($this->all_capabilities() as $capability) {
            $this->assertTrue(
                has_capability($capability, $context, $manager->id),
                "Manager should have $capability by default"
            );
        }
    }

    /**
     * A Teacher does NOT have these capabilities out of the box.
     */
    public function test_teacher_lacks_capabilities_by_default(): void {
        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        foreach ($this->all_capabilities() as $capability) {
            $this->assertFalse(
                has_capability($capability, $context, $teacher->id),
                "Teacher should NOT have $capability by default"
            );
        }
    }

    /**
     * A capability that appears for a Teacher once granted to that role,
     * and disappears again once revoked - proving the plugin's visibility
     * checks genuinely react to role permission changes, not just to a
     * cached/static assumption.
     */
    public function test_capability_appears_and_disappears_when_role_changes(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');

        $capability = 'local/participants_report_actions:loginas';
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        $this->assertFalse(
            has_capability($capability, $context, $teacher->id),
            'Capability should be hidden before granting it'
        );

        assign_capability($capability, CAP_ALLOW, $teacherroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(
            has_capability($capability, $context, $teacher->id),
            'Capability should appear immediately after granting it to the role'
        );

        unassign_capability($capability, $teacherroleid, \context_system::instance()->id);
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertFalse(
            has_capability($capability, $context, $teacher->id),
            'Capability should disappear again after revoking it'
        );
    }

    /**
     * The row-level login-as URL is only offered when both the plugin's own
     * capability AND core's moodle/user:loginas are held - granting only
     * one of the two is not enough.
     */
    public function test_loginas_requires_both_capabilities(): void {
        global $DB;

        $this->resetAfterTest(true);

        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $context = \context_course::instance($course->id);

        $teacher = $generator->create_user();
        $generator->enrol_user($teacher->id, $course->id, 'editingteacher');
        $teacherroleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher']);

        // Grant only our own capability, not core's moodle/user:loginas.
        assign_capability(
            'local/participants_report_actions:loginas',
            CAP_ALLOW,
            $teacherroleid,
            \context_system::instance()->id
        );
        accesslib_clear_all_caches_for_unit_testing();

        $this->assertTrue(has_capability('local/participants_report_actions:loginas', $context, $teacher->id));
        $this->assertFalse(
            has_capability('moodle/user:loginas', $context, $teacher->id),
            'moodle/user:loginas should still be denied - granting only our capability must not be enough on its own'
        );
    }
}
