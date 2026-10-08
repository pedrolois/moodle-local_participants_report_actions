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
 * Adds an "Actions" column to the course Participants report: email,
 * message, log in as this user, course progress, and course badges —
 * without modifying any core template. The "log in as" icon reuses
 * core's own course/loginas.php for the actual capability/sesskey/
 * admin-protection checks; this module only substitutes the userid
 * placeholder per row and reuses core/notification's confirm dialog, it
 * does not construct URLs or a custom confirmation UI.
 *
 * IMPORTANT LIMITATION: the Participants table has no server-side
 * extension point for adding a real table_sql column (verified against
 * Moodle 4.5 core — no hook, no callback). The Actions column is injected
 * purely client-side, which means it will NOT appear in the table's own
 * CSV/Excel/PDF export, and it does not participate in column sorting or
 * the built-in show/hide-columns feature. It is a visual shortcut only.
 *
 * Course progress IS downloadable, though: this module adds a separate
 * "Download progress data as" group to the existing "With selected users..."
 * dropdown (core's own download options are left as they are), which submits the selected users' ids to a dedicated endpoint
 * (export_progress.php) that reuses \core\dataformat::download_data() —
 * the same approach core itself uses for its own "download participants"
 * bulk action, rather than reinventing CSV/xlsx writing.
 *
 * The table implements core_table/dynamic and re-renders entirely via
 * AJAX on search/filter/sort/page changes, replacing the whole table DOM
 * node. We listen for core_table/dynamic's "tableContentRefreshed" event
 * to re-inject the column after every such refresh.
 *
 * @module     local_participants_report_actions/rowactions
 * @copyright  2026 Pedro Lois {@link https://github.com/pedrolois}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {call as ajaxCall} from 'core/ajax';
import {getStrings} from 'core/str';
import {confirm as confirmDialog, exception as notifyException} from 'core/notification';
import Modal from 'core/modal';
import * as DynamicTable from 'core_table/dynamic';

const Selectors = {
    table: 'table#participants',
    userCheckbox: '.usercheckbox',
    headerRow: 'thead tr',
    bodyRow: 'tbody tr',
};

const COLUMN_CLASS = 'local-participants-report-actions-col';

/**
 * Read the theme's actual link hover colour from its compiled stylesheet,
 * so the progress bar's hover highlight always matches the current theme
 * instead of a hardcoded colour. Themes only expose their base link colour
 * as a CSS custom property (e.g. --primary); the hover colour itself is a
 * compile-time Sass darken() with no corresponding variable, so the only
 * reliable way to read it at runtime is to find the literal "a:hover" rule
 * in an already-loaded, same-origin stylesheet.
 *
 * @return {String|null} A CSS colour value, or null if none could be found.
 */
let cachedLinkHoverColor;
const getLinkHoverColor = () => {
    if (cachedLinkHoverColor !== undefined) {
        return cachedLinkHoverColor;
    }

    cachedLinkHoverColor = null;
    try {
        for (const sheet of document.styleSheets) {
            let rules;
            try {
                rules = sheet.cssRules;
            } catch (e) {
                // Cross-origin stylesheet; cssRules access throws. Skip it.
                continue;
            }
            if (!rules) {
                continue;
            }
            for (const rule of rules) {
                if (rule.selectorText === 'a:hover' && rule.style && rule.style.color) {
                    cachedLinkHoverColor = rule.style.color;
                    return cachedLinkHoverColor;
                }
            }
        }
    } catch (e) {
        // Fall through to the null default below.
        cachedLinkHoverColor = null;
    }

    return cachedLinkHoverColor;
};

/**
 * Extract the userid from a row's select checkbox (id="user123").
 *
 * @param {HTMLElement} row
 * @return {Number|null}
 */
const getUserIdFromRow = (row) => {
    const checkbox = row.querySelector(Selectors.userCheckbox);
    if (!checkbox || !checkbox.id) {
        return null;
    }
    const match = checkbox.id.match(/^user(\d+)$/);
    return match ? parseInt(match[1], 10) : null;
};

/**
 * Build the cell content for one participant's extras.
 *
 * @param {Object} extras Data returned by the web service for this user.
 * @param {Object} strings Preloaded language strings.
 * @param {String|null} loginasurltemplate The server-built loginas.php URL
 *                                          template, or null if the current
 *                                          user cannot log in as others here.
 * @param {Object} statecolours Map of activity completion state to CSS colour.
 * @return {HTMLElement}
 */
const buildCell = (extras, strings, loginasurltemplate, statecolours) => {
    const wrap = document.createElement('div');
    wrap.className = 'local-participants-report-actions-cell d-flex flex-column gap-1';

    const iconsRow = document.createElement('div');
    iconsRow.className = 'd-flex gap-2';

    if (extras.email) {
        const mailLink = document.createElement('a');
        mailLink.href = `mailto:${extras.email}`;
        mailLink.title = strings.sendemail;
        mailLink.setAttribute('aria-label', strings.sendemail);
        mailLink.innerHTML = '<i class="icon fa fa-envelope fa-fw" aria-hidden="true"></i>';
        iconsRow.appendChild(mailLink);
    }

    if (extras.canmessage) {
        const msgLink = document.createElement('a');
        msgLink.href = `${M.cfg.wwwroot}/message/index.php?id=${extras.userid}`;
        msgLink.title = strings.sendmessage;
        msgLink.setAttribute('aria-label', strings.sendmessage);
        msgLink.innerHTML = '<i class="icon fa fa-comment fa-fw" aria-hidden="true"></i>';
        iconsRow.appendChild(msgLink);
    }

    if (loginasurltemplate) {
        const loginasUrl = loginasurltemplate.replace('__USERID__', extras.userid);

        const loginasLink = document.createElement('a');
        loginasLink.href = '#';
        loginasLink.title = strings.loginas;
        loginasLink.setAttribute('role', 'button');
        loginasLink.setAttribute('aria-label', strings.loginas);
        loginasLink.innerHTML = '<i class="icon fa fa-chalkboard-teacher fa-fw" aria-hidden="true"></i>';
        loginasLink.addEventListener('click', (e) => {
            e.preventDefault();

            getStrings([
                {key: 'confirmloginas', component: 'local_participants_report_actions'},
                {key: 'confirm', component: 'core'},
                {key: 'continue', component: 'core'},
                {key: 'cancel', component: 'core'},
            ]).then(([confirmloginas, confirmlabel, continuelabel, cancellabel]) => {
                confirmDialog(
                    confirmlabel,
                    confirmloginas,
                    continuelabel,
                    cancellabel,
                    () => {
                        window.location.href = loginasUrl;
                    }
                );
                return;
            }).catch(notifyException);
        });
        iconsRow.appendChild(loginasLink);
    }

    if (extras.badges && extras.badges.length) {
        // Only the most recent badge is linked - this is a shortcut icon,
        // not a full badge list.
        const badge = extras.badges[0];

        const badgeLink = document.createElement('a');
        badgeLink.href = `${M.cfg.wwwroot}/badges/badge.php?hash=${encodeURIComponent(badge.hash)}`;
        badgeLink.title = badge.name;
        badgeLink.setAttribute('aria-label', strings.badgesearned.replace('{$a}', badge.name));
        badgeLink.innerHTML = '<i class="icon fa fa-award fa-fw" aria-hidden="true"></i>';
        iconsRow.appendChild(badgeLink);
    }

    wrap.appendChild(iconsRow);

    if (extras.progress !== null && extras.progress !== undefined) {
        const roundedprogress = Math.round(extras.progress);

        // Inline styles throughout, deliberately not Bootstrap's
        // .progress/.progress-bar classes: those get restyled by themes
        // (colours, height, border-radius), which would make the embedded
        // percentage text illegible on some themes. This bar's look is
        // fixed regardless of the active theme.
        const progressWrap = document.createElement('div');
        progressWrap.style.position = 'relative';
        progressWrap.style.height = '1rem';
        progressWrap.style.minWidth = '80px';
        progressWrap.style.borderRadius = '0.2rem';
        progressWrap.style.backgroundColor = '#e0e0e0';
        progressWrap.style.overflow = 'hidden';
        progressWrap.style.cursor = extras.activities && extras.activities.length ? 'pointer' : 'default';
        progressWrap.title = strings.progressbar;
        progressWrap.setAttribute('role', 'button');
        progressWrap.setAttribute('tabindex', '0');
        progressWrap.setAttribute('aria-valuenow', roundedprogress);
        progressWrap.setAttribute('aria-valuemin', '0');
        progressWrap.setAttribute('aria-valuemax', '100');
        progressWrap.setAttribute('aria-label', `${strings.progressbar}: ${roundedprogress}%`);

        const hoverColor = getLinkHoverColor();
        if (hoverColor) {
            const applyHover = () => {
                progressWrap.style.boxShadow = `0 0 0 2px ${hoverColor}`;
            };
            const removeHover = () => {
                progressWrap.style.boxShadow = 'none';
            };
            progressWrap.addEventListener('mouseenter', applyHover);
            progressWrap.addEventListener('mouseleave', removeHover);
            progressWrap.addEventListener('focus', applyHover);
            progressWrap.addEventListener('blur', removeHover);
        }

        if (extras.activities && extras.activities.length) {
            const openModal = () => {
                // The email is empty when the current user may not see it,
                // so use the title variant without it rather than "()".
                getStrings([{
                    key: extras.email ? 'progressbartitle' : 'progressbartitlenoemail',
                    component: 'local_participants_report_actions',
                    param: {
                        fullname: extras.fullname,
                        email: extras.email,
                        percent: roundedprogress,
                    },
                }]).then(([modaltitle]) => {
                    showActivityGridModal(extras.activities, modaltitle, statecolours);
                    return;
                }).catch(notifyException);
            };
            progressWrap.addEventListener('click', openModal);
            progressWrap.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    openModal();
                }
            });
        }

        const bar = document.createElement('div');
        bar.style.position = 'absolute';
        bar.style.top = '0';
        bar.style.left = '0';
        bar.style.bottom = '0';
        bar.style.width = `${roundedprogress}%`;
        bar.style.backgroundColor = statecolours.completed || '#81c784';

        const label = document.createElement('span');
        label.style.position = 'relative';
        label.style.display = 'block';
        label.style.textAlign = 'center';
        label.style.fontFamily = 'Consolas, "Courier New", monospace';
        label.style.fontSize = '0.65rem';
        label.style.lineHeight = '1rem';
        label.style.color = '#fff';
        label.style.textShadow = '0 1px 2px rgba(0, 0, 0, 0.75)';
        label.style.whiteSpace = 'nowrap';
        label.textContent = `${roundedprogress}%`;

        progressWrap.appendChild(bar);
        progressWrap.appendChild(label);
        wrap.appendChild(progressWrap);
    }

    return wrap;
};

/**
 * Escape a string for safe use as HTML text content.
 *
 * @param {String} value
 * @return {String}
 */
const escapeHtml = (value) => {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
};

// Icon per state, same visual language as block_completion_progress
// (tick for done, up-arrow for submitted-pending-grade, cross for overdue,
// nothing yet for a future/not-yet-due activity).
const STATE_ICONS = {
    completed: 'fa-check',
    submitted: 'fa-arrow-up',
    notcompleted: 'fa-times',
    futurenotcompleted: null,
};

/**
 * Build the per-activity completion grid HTML, styled like
 * block_completion_progress: one cell per activity, coloured by
 * completion state (using the site's own block_completion_progress colour
 * settings, or its defaults), with the activity name shown below and a
 * link to the activity itself when one exists.
 *
 * @param {Array} activities Array of {cmid, name, state, url}.
 * @param {Object} statecolours Map of state name to CSS colour.
 * @return {String} HTML string, safe to pass directly as a modal body.
 */
const buildActivityGridHtml = (activities, statecolours) => {
    const cells = activities.map((activity) => {
        const bgcolor = statecolours[activity.state] || '#e0e0e0';
        const iconclass = STATE_ICONS[activity.state];
        const icon = iconclass ? `<i class="icon fa ${iconclass} fa-fw" aria-hidden="true"></i>` : '';
        const tag = activity.url ? 'a' : 'div';
        const hrefAttr = activity.url ? ` href="${escapeHtml(activity.url)}"` : '';
        return `
            <${tag}${hrefAttr} style="display:flex;flex-direction:column;align-items:center;justify-content:center;` +
            `min-width:90px;min-height:70px;padding:0.5rem;border-radius:0.25rem;` +
            `background-color:${bgcolor};color:#fff;text-align:center;text-decoration:none;` +
            `text-shadow:0 1px 2px rgba(0,0,0,0.4);font-size:0.75rem;">` +
            `<div>${icon}</div>` +
            `<div style="margin-top:0.25rem;">${escapeHtml(activity.name)}</div>` +
            `</${tag}>`;
    }).join('');

    return `<div style="display:flex;flex-wrap:wrap;gap:0.5rem;">${cells}</div>`;
};

/**
 * Open a modal showing the per-activity completion grid for one user.
 *
 * @param {Array} activities
 * @param {String} title
 * @param {Object} statecolours
 */
const showActivityGridModal = async(activities, title, statecolours) => {
    await Modal.create({
        title,
        body: buildActivityGridHtml(activities, statecolours),
        large: true,
        removeOnClose: true,
        show: true,
    });
};

/**
 * Insert the "Actions" header cell, if not already present.
 *
 * @param {HTMLElement} table
 * @param {String} label
 */
const ensureHeader = (table, label) => {
    const headerRow = table.querySelector(Selectors.headerRow);
    if (!headerRow || headerRow.querySelector(`.${COLUMN_CLASS}`)) {
        return;
    }
    const th = document.createElement('th');
    // Reuse core's own "header" class (same as every real column's <th>,
    // see lib/table/classes/flexible_table.php print_headers()) so this
    // column's header aligns vertically with the others - those headers
    // reserve space for a hide/show icon in a nested .commands div even
    // when a column can't be collapsed, which is why a plain <th> without
    // that class sits noticeably higher.
    th.className = `header ${COLUMN_CLASS}`;
    th.scope = 'col';
    th.textContent = label;
    headerRow.appendChild(th);
};

/**
 * Decorate every participant row with the extras cell, fetched in one
 * batched web service call for all rows currently on the page.
 *
 * @param {HTMLElement} table
 * @param {Number} courseid
 * @param {Object} strings
 * @param {String|null} loginasurltemplate
 * @param {Object} statecolours
 */
const decorateRows = (table, courseid, strings, loginasurltemplate, statecolours) => {
    const rows = Array.prototype.filter.call(
        table.querySelectorAll(Selectors.bodyRow),
        (row) => !row.querySelector(`.${COLUMN_CLASS}`)
    );

    const userids = [];
    const rowsByUserId = {};
    rows.forEach((row) => {
        const userid = getUserIdFromRow(row);
        if (!userid) {
            return;
        }
        userids.push(userid);
        rowsByUserId[userid] = row;
    });

    if (!userids.length) {
        return;
    }

    const request = {
        methodname: 'local_participants_report_actions_get_participant_extras',
        args: {
            courseid,
            userids,
        },
    };

    ajaxCall([request])[0].then((results) => {
        results.forEach((extras) => {
            const row = rowsByUserId[extras.userid];
            if (!row) {
                return;
            }
            const td = document.createElement('td');
            td.className = COLUMN_CLASS;
            td.appendChild(buildCell(extras, strings, loginasurltemplate, statecolours));
            row.appendChild(td);
        });
        return;
    }).catch(() => {
        // Silently skip decoration on failure; the rest of the table stays usable.
    });
};

/**
 * Run a full pass: ensure the header exists, then decorate rows.
 *
 * @param {Number} courseid
 * @param {String|null} loginasurltemplate Null if the current user is not
 *                                          allowed to log in as others here.
 * @param {Object} strings
 * @param {Object} statecolours
 */
const run = (courseid, loginasurltemplate, strings, statecolours) => {
    const table = document.querySelector(Selectors.table);
    if (!table) {
        return;
    }
    ensureHeader(table, strings.actionscolumn);
    decorateRows(table, courseid, strings, loginasurltemplate, statecolours);
};

/**
 * Add a separate "Download progress data as" group to the
 * "With selected users..." dropdown (#formactionid), next to core's own
 * "Download table data as" group, which is left completely untouched.
 * Core renders its download options with a value like
 * "bulkchange.php?operation=download_participants&dataformat=csv" (see
 * user/index.php); we read the available dataformats from those options
 * and add one matching option per format pointing at our own export
 * endpoint, so both downloads stay available side by side.
 *
 * @param {String|null} exporturl Base export URL (id + sesskey already set);
 *                                 the dataformat param is appended per
 *                                 option here. Null adds nothing.
 * @param {String} grouplabel Label for the new option group.
 */
const setupProgressDownloadOption = (exporturl, grouplabel) => {
    const select = document.getElementById('formactionid');
    if (!select || !exporturl) {
        return;
    }

    const coreoptions = Array.prototype.filter.call(
        select.querySelectorAll('option'),
        (option) => /[?&]dataformat=[^&]+/.test(option.value)
    );
    if (!coreoptions.length) {
        return;
    }

    const group = document.createElement('optgroup');
    group.label = grouplabel;
    const exportvalues = new Set();
    coreoptions.forEach((coreoption) => {
        const dataformat = coreoption.value.match(/[?&]dataformat=([^&]+)/)[1];
        const option = document.createElement('option');
        option.value = `${exporturl}&dataformat=${dataformat}`;
        option.textContent = coreoption.textContent;
        group.appendChild(option);
        exportvalues.add(option.value);
    });

    // Place our group right after core's download group (or at the end
    // when core's options are not grouped).
    const coregroup = coreoptions[0].closest('optgroup');
    if (coregroup) {
        coregroup.after(group);
    } else {
        select.appendChild(group);
    }

    const doExport = (action) => {
        const checkedInputs = select.form.querySelectorAll('input[type="checkbox"][name^="user"]:checked');

        const exportform = document.createElement('form');
        exportform.method = 'post';
        exportform.action = action;
        exportform.style.display = 'none';

        checkedInputs.forEach((checkbox) => {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = checkbox.name;
            hidden.value = '1';
            exportform.appendChild(hidden);
        });

        document.body.appendChild(exportform);
        exportform.submit();
    };

    // The core_user/participants module calls bulkActionSelect.form.submit() directly
    // (user/amd/src/participants.js) rather than dispatching a click on a
    // submit button. Per the DOM spec, HTMLFormElement.submit() does NOT
    // fire the form's "submit" event - only a real user-initiated
    // submission (Enter key, submit button click) does. That means a
    // regular addEventListener('submit', ...) here would simply never run
    // for this particular code path. The only reliable interception point
    // is the submit() method itself.
    const nativesubmit = select.form.submit.bind(select.form);
    select.form.submit = () => {
        const action = select.value;
        if (!exportvalues.has(action)) {
            nativesubmit();
            return;
        }

        select.value = '';
        doExport(action);
    };
};

/**
 * Initialise the module.
 *
 * @param {Number} courseid
 * @param {String|null} loginasurltemplate
 * @param {Object} statecolours Map of activity completion state to CSS colour.
 * @param {String} exporturl Base URL for the progress export endpoint.
 */
export const init = async(courseid, loginasurltemplate, statecolours, exporturl) => {
    courseid = parseInt(courseid, 10);

    const [actionscolumn, sendemail, sendmessage, badgesearned, loginas, progressbar, downloadprogressas] =
        await getStrings([
            {key: 'actionscolumn', component: 'local_participants_report_actions'},
            {key: 'sendemail', component: 'local_participants_report_actions'},
            {key: 'sendmessage', component: 'local_participants_report_actions'},
            {key: 'badgesearned', component: 'local_participants_report_actions', param: '%'},
            {key: 'loginas', component: 'local_participants_report_actions'},
            {key: 'progressbar', component: 'local_participants_report_actions'},
            {key: 'downloadprogressas', component: 'local_participants_report_actions'},
        ]);

    const strings = {actionscolumn, sendemail, sendmessage, badgesearned, loginas, progressbar};

    run(courseid, loginasurltemplate, strings, statecolours);
    setupProgressDownloadOption(exporturl, downloadprogressas);

    // Re-run after every dynamic refresh (search/filter/sort/page change),
    // since the table DOM node is fully replaced each time.
    document.addEventListener(DynamicTable.Events.tableContentRefreshed, () => {
        run(courseid, loginasurltemplate, strings, statecolours);
    });
};
