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
 * Strings for component 'local_participants_report_actions'.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['actionscolumn'] = 'Actions';
$string['activitycompleted'] = 'Completed';
$string['activitynotcompleted'] = 'Not completed';
$string['badgesearned'] = 'Badges earned: {$a}';
$string['confirmloginas'] = 'Are you sure you want to login as this user?';
$string['downloadprogressas'] = 'Download progress data as';
$string['exportcolumnfullname'] = 'Full name';
$string['exportcolumnprogresspercent'] = 'Course progress (%)';
$string['exportcolumnuserid'] = 'User id';
$string['exportdisabled'] = 'The progress export is disabled on this site.';
$string['loginas'] = 'Log in as this user';
$string['nobadges'] = 'No badges earned yet';
$string['participants_report_actions:export'] = 'Export participant progress data';
$string['participants_report_actions:loginas'] = 'Log in as a participant from the report';
$string['participants_report_actions:sendemail'] = 'See the send-email shortcut on the report';
$string['participants_report_actions:sendmessage'] = 'See the send-message shortcut on the report';
$string['participants_report_actions:viewbadges'] = 'View participants\' earned badges on the report';
$string['participants_report_actions:viewprogress'] = 'View participants\' course progress on the report';
$string['pluginname'] = 'Participants report actions';
$string['privacy:metadata'] = 'The Participants report actions plugin does not store any personal data of its own. It only reads and displays existing user, completion, and badge data that is already stored by Moodle core, for teachers/managers who hold the matching capability in that course.';
$string['progressbar'] = 'Progress bar';
$string['progressbartitle'] = '{$a->fullname} ({$a->email}) — {$a->percent}% complete';
$string['progressbartitlenoemail'] = '{$a->fullname} — {$a->percent}% complete';
$string['progressnotavailable'] = 'Progress tracking not available';
$string['sendemail'] = 'Send email';
$string['sendmessage'] = 'Send message';
$string['settings_colourcompleted'] = 'Completed colour';
$string['settings_colourcompleted_desc'] = 'Colour for activities the participant has completed.';
$string['settings_colourfuturenotcompleted'] = 'Future not-completed colour';
$string['settings_colourfuturenotcompleted_desc'] = 'Colour for activities not yet completed and not yet due (or with no due date).';
$string['settings_colournotcompleted'] = 'Not-completed colour';
$string['settings_colournotcompleted_desc'] = 'Colour for activities not completed and already overdue, or explicitly failed.';
$string['settings_coloursubmitted'] = 'Submitted but not complete colour';
$string['settings_coloursubmitted_desc'] = 'Colour for assignments the participant submitted but that are not yet graded/complete.';
$string['settings_enablebadges'] = 'Enable badges';
$string['settings_enablebadges_desc'] = 'Show the earned-badges indicator in the Actions column, for users who also hold the viewbadges capability.';
$string['settings_enableexport'] = 'Enable progress export';
$string['settings_enableexport_desc'] = 'Add a separate "Download progress data as" group to the "With selected users..." menu, for users who also hold the export capability. Core\'s own "Download table data as" options are never changed.';
$string['settings_enableloginas'] = 'Enable log in as';
$string['settings_enableloginas_desc'] = 'Show the "Log in as this user" icon in the Actions column, for users who also hold the loginas capability.';
$string['settings_enableprogress'] = 'Enable progress bar';
$string['settings_enableprogress_desc'] = 'Show the course completion progress bar in the Actions column, for users who also hold the viewprogress capability.';
$string['settings_enablesendemail'] = 'Enable send email';
$string['settings_enablesendemail_desc'] = 'Show the send-email icon in the Actions column, for users who also hold the sendemail capability and may see participants\' email addresses (the "Show user identity" setting and moodle/site:viewuseridentity).';
$string['settings_enablesendmessage'] = 'Enable send message';
$string['settings_enablesendmessage_desc'] = 'Show the send-message icon in the Actions column, for users who also hold the sendmessage capability.';
