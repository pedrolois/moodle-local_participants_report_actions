# local_participants_report_actions

Adds an **"Actions" column** to the course Participants report, with
one-click shortcuts for email, private messaging, logging in as that
user, course completion progress (with a per-activity breakdown), and
earned course badges — plus a separate progress export next to core's
own "Download table data as" options.

## Overview

The Participants report is where teachers and managers already look to
manage enrolments, roles, and groups. This plugin puts the actions they
reach for most — messaging a student, checking their progress, logging in
as them, seeing their badges — right there, instead of a detour through
messaging, reports, or a separate block.

## What it adds

- **Send email** — a `mailto:` shortcut using the participant's email.
- **Send message** — a link to Moodle's internal messaging for that user.
- **Log in as this user** — same confirm-then-redirect flow as core's
  own "Login as", reachable from the row instead of a menu.
- **Course progress** — a compact percentage bar; clicking it opens a
  modal with a cell per tracked activity, coloured by state (completed,
  submitted-but-not-graded, overdue, not yet due), each linking to that
  activity.
- **Course badges** — an icon linking to the participant's most recently
  earned course badge.
- **Progress export** — adds a separate "Download progress data as" group
  (CSV, xlsx, ODS, …) to the "With selected users..." menu, exporting
  progress percentage and per-activity state for the selected participants.
  Core's own "Download table data as" options are left unchanged.

## How it works

- Everything is injected into `/user/index.php` (the course Participants
  report) via Moodle's `core\hook\output\before_footer_html_generation`
  hook — no core files are modified.
- Per-user data (email, messaging permission, progress, activity states,
  badges) is fetched in **one batched web service call** for every row
  currently on the page, not one request per participant.
- The progress bar's per-activity states are computed the same way
  `block_completion_progress` computes them (including its "submitted but
  not graded" amber state for assignments), and default to that block's
  own colour scheme — but as this plugin's own settings, independent of
  whether that block is even installed.
- The progress export reuses `\core\dataformat::download_data()` — the
  same core API `user/action_redir.php` itself uses for the built-in
  "download selected participants" action — rather than writing
  CSV/xlsx output by hand.

## Permissions

Each feature is gated by **both** a site-wide on/off setting and its own
capability, so an admin can turn a feature off everywhere, or open it up
to specific roles, independently of the others:

| Feature | Setting | Capability |
|---|---|---|
| Send email | `enablesendemail` | `local/participants_report_actions:sendemail` |
| Send message | `enablesendmessage` | `local/participants_report_actions:sendmessage` |
| Log in as | `enableloginas` | `local/participants_report_actions:loginas` |
| Course progress | `enableprogress` | `local/participants_report_actions:viewprogress` |
| Course badges | `enablebadges` | `local/participants_report_actions:viewbadges` |
| Progress export | `enableexport` | `local/participants_report_actions:export` |

All six capabilities default to **Manager only** (`Site administration →
Users → Permissions → Define roles` to extend them to Teacher or other
roles). The "log in as" shortcut is additionally gated by core's own
`moodle/user:loginas` — granting only this plugin's capability is never
enough on its own, and the link it builds still goes through core's
`course/loginas.php`, which re-validates everything server-side
regardless of what this plugin does client-side.

## Where the data lives

This plugin creates no database tables of its own and stores no user
preferences. It only reads existing Moodle data (`user`,
`course_completions`, `badge_issued`, etc.) at render/export time — see
[`classes/privacy/provider.php`](classes/privacy/provider.php) for the
Privacy API declaration.

## Known limitation

The "Actions" column itself has no server-side extension point in Moodle
4.5's Participants table, so it's injected client-side. That means it
does **not** appear in core's own "Download table data as" export, and it
doesn't participate in the table's native column sorting or
show/hide-columns feature. The separate progress export above is the
workaround for the export case specifically.

## Installation

1. Copy this folder to `local/participants_report_actions` in your
   Moodle install.
2. Visit *Site administration → Notifications* to complete the install.
3. Optionally review the settings and capabilities above.

## Requirements

- Moodle 4.4 or later (uses the `core\hook\output\before_footer_html_generation` hook).

## Changelog

### 1.0.1

- The progress export and the web service only return data for
  participants of the course (respecting separate groups mode), and the
  export now requires the `export` capability and the `enableexport` setting.
- Emails (send-email icon, progress modal title, and the progress export's
  email column) are only shown when email is one of the identity fields the
  current user may see (`showuseridentity` + `moodle/site:viewuseridentity`),
  the same rule as core's Participants report.
- The progress export is now its own "Download progress data as" option
  group instead of replacing core's participant download.
- Requires Moodle 4.4, the first release with the hook the plugin uses.

### 1.0.0

- Initial release: Actions column (email, message, login-as, progress,
  badges) and progress-aware export.

## License

GNU GPL v3 or later. See [LICENSE](LICENSE).
