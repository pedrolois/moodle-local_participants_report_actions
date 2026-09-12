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
 * Admin settings.
 *
 * Each feature has a site-wide on/off switch here, on top of its own
 * capability (see db/access.php): a feature only shows for a given user
 * when it is enabled here AND the user holds the matching capability in
 * that course context.
 *
 * @package    local_participants_report_actions
 * @author     Pedro Luis Garcia Leiva
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $settings = new admin_settingpage(
        'local_participants_report_actions',
        get_string('pluginname', 'local_participants_report_actions')
    );

    $settings->add(new admin_setting_configcheckbox(
        'local_participants_report_actions/enablesendemail',
        get_string('settings_enablesendemail', 'local_participants_report_actions'),
        get_string('settings_enablesendemail_desc', 'local_participants_report_actions'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_participants_report_actions/enablesendmessage',
        get_string('settings_enablesendmessage', 'local_participants_report_actions'),
        get_string('settings_enablesendmessage_desc', 'local_participants_report_actions'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_participants_report_actions/enableloginas',
        get_string('settings_enableloginas', 'local_participants_report_actions'),
        get_string('settings_enableloginas_desc', 'local_participants_report_actions'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_participants_report_actions/enableprogress',
        get_string('settings_enableprogress', 'local_participants_report_actions'),
        get_string('settings_enableprogress_desc', 'local_participants_report_actions'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_participants_report_actions/enablebadges',
        get_string('settings_enablebadges', 'local_participants_report_actions'),
        get_string('settings_enablebadges_desc', 'local_participants_report_actions'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_participants_report_actions/enableexport',
        get_string('settings_enableexport', 'local_participants_report_actions'),
        get_string('settings_enableexport_desc', 'local_participants_report_actions'),
        1
    ));

    // Progress bar / modal state colours. Defaults match
    // block_completion_progress's own defaults so the two look the same out
    // of the box, but these are this plugin's own settings - they work
    // independently of whether that block is even installed.
    $settings->add(new admin_setting_configcolourpicker(
        'local_participants_report_actions/colourcompleted',
        get_string('settings_colourcompleted', 'local_participants_report_actions'),
        get_string('settings_colourcompleted_desc', 'local_participants_report_actions'),
        '#6A9C35'
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_participants_report_actions/coloursubmitted',
        get_string('settings_coloursubmitted', 'local_participants_report_actions'),
        get_string('settings_coloursubmitted_desc', 'local_participants_report_actions'),
        '#FFCC00'
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_participants_report_actions/colournotcompleted',
        get_string('settings_colournotcompleted', 'local_participants_report_actions'),
        get_string('settings_colournotcompleted_desc', 'local_participants_report_actions'),
        '#C71C22'
    ));

    $settings->add(new admin_setting_configcolourpicker(
        'local_participants_report_actions/colourfuturenotcompleted',
        get_string('settings_colourfuturenotcompleted', 'local_participants_report_actions'),
        get_string('settings_colourfuturenotcompleted_desc', 'local_participants_report_actions'),
        '#025187'
    ));

    $ADMIN->add('localplugins', $settings);
}
