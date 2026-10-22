/**
 * DriveAge Plugin - Dashboard
 *
 * Renders the summary, charts and drive table from get_drive_data.php.
 * The JSON is plain data: HTML is built here and every value goes through
 * escapeHtml(); CSS classes come from the server (*_class fields).
 *
 * The request itself is started by an inline script at the top of the
 * page (window.driveagePending) so it runs while the page still parses.
 *
 * Everything lives in this closure - Unraid pages share one global scope.
 */
window.DriveAge = window.DriveAge || {};

(function (DA) {
    'use strict';

    const ENDPOINT = '/plugins/driveage/scripts/get_drive_data.php';
    const PREFS_KEY = 'driveage.dashboard.v1';

    const RISK_RANK = { minimal_risk: 0, low_risk: 1, moderate_risk: 2, elevated_risk: 3, high_risk: 4 };
    const HEALTH_RANK = { ok: 0, watch: 1, failing: 2 };
    const TYPE_LABELS = { hdd: 'HDD', ssd: 'SSD', nvme: 'NVMe', usb: 'USB' };
    const HEALTH_ICONS = { ok: 'fa-check-circle', watch: 'fa-exclamation-circle', failing: 'fa-exclamation-triangle', unknown: 'fa-question-circle' };

    const METHOD_LABELS = {
        smart_failed: 'SMART overall-health FAILED',
        critical_smart: 'Critical SMART attributes',
        smart_warnings: 'SMART warnings (new)',
        smart_warnings_stable: 'SMART warnings (stable)',
        age_linear: 'Countdown to target age',
        no_data: 'No power-on hours',
        critical_error: 'Critical drive condition',
        spare_below_threshold: 'Spare below threshold',
        endurance_exceeded: 'Rated endurance consumed',
        percentage_used_measured: 'Wear indicator at measured rate',
        percentage_used_linear: 'Wear indicator, assumed 5-year life',
        wear_below_resolution: 'Wear indicator under 1%',
        tbw_measured_rate: 'Estimated TBW rating, measured writes',
        tbw_assumed_rate: 'Estimated TBW rating, assumed writes',
        spare_depletion: 'Spare depletion',
        insufficient_data: 'Insufficient data'
    };

    // Health warning attribute -> Help page anchor
    const HELP_ANCHORS = {
        smart_overall_failed: 'other-smart-warnings',
        reallocated_sectors: 'reallocated-sectors',
        pending_sectors: 'other-smart-warnings',
        uncorrectable_sectors: 'other-smart-warnings',
        reported_uncorrectable: 'other-smart-warnings',
        command_timeout: 'other-smart-warnings',
        grown_defects: 'other-smart-warnings',
        scsi_uncorrected: 'other-smart-warnings',
        media_errors: 'nvme-warnings',
        critical_warning: 'nvme-warnings',
        nvme_spare_low: 'nvme-warnings',
        nvme_temperature: 'nvme-warnings',
        nvme_reliability: 'nvme-warnings',
        nvme_read_only: 'nvme-warnings',
        nvme_backup_failed: 'nvme-warnings',
        nvme_pmr_read_only: 'nvme-warnings',
        ssd_wear: 'nvme-warnings'
    };

    // SMART values shown in the detail panel: [field, label, formatter]
    const DETAIL_ATTRS = [
        ['hdd_reallocated_sectors', 'Reallocated sectors (5)'],
        ['hdd_reported_uncorrectable', 'Reported uncorrectable (187)'],
        ['hdd_command_timeout', 'Command timeouts (188)'],
        ['hdd_pending_sectors', 'Pending sectors (197)'],
        ['hdd_uncorrectable_sectors', 'Offline uncorrectable (198)'],
        ['scsi_grown_defects', 'Grown defect list'],
        ['scsi_uncorrected_errors', 'Uncorrected errors (read/write/verify)'],
        ['nvme_percentage_used', 'Percentage used', v => v + '%'],
        ['nvme_available_spare', 'Available spare', (v, d) => v + '% (threshold ' + (d.nvme_available_spare_threshold ?? '?') + '%)'],
        ['nvme_tbw_calculated', 'Data written', v => Number(v).toLocaleString() + ' TB'],
        ['nvme_media_errors', 'Media errors'],
        ['nvme_critical_warning', 'Critical warning', v => '0x' + Number(v).toString(16).toUpperCase().padStart(2, '0')],
        ['ssd_percentage_used', 'Wear (percentage used)', (v, d) => v + '%' + (d.ssd_wear_attribute ? ' (from ' + d.ssd_wear_attribute + ')' : '')],
        ['ssd_tbw_calculated', 'Data written', v => Number(v).toLocaleString() + ' TB']
    ];

    const state = {
        data: null,
        columns: [],
        sortColumn: null,
        sortDirection: null,
        userSorted: false,
        collapsed: new Set(),   // group keys
        expanded: new Set(),    // device ids with the detail panel open
        loading: false,
        refreshTimer: null,
        refreshDue: false
    };

    // ------------------------------------------------------------------
    // Utilities

    const ESCAPES = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };

    /**
     * Escape a value for HTML text or attribute context.
     */
    function escapeHtml(value) {
        if (value === null || value === undefined) {
            return '';
        }
        return String(value).replace(/[&<>"']/g, ch => ESCAPES[ch]);
    }
    DA.escapeHtml = escapeHtml;

    function truncate(str, maxLength) {
        if (!str) return '';
        return str.length <= maxLength ? str : str.substring(0, maxLength - 1) + '…';
    }

    function monthsOf(drive) {
        const p = drive.replacement_prediction;
        return (p && p.months_remaining !== null && p.months_remaining !== undefined) ? p.months_remaining : null;
    }

    function riskRank(drive) {
        return RISK_RANK[drive.risk_category] ?? -1;
    }

    /** Most urgent first: fewest months, then worst risk. */
    function byUrgency(a, b) {
        return (monthsOf(a) - monthsOf(b)) || (riskRank(b) - riskRank(a));
    }

    function naturalCompare(a, b) {
        return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
    }

    function formatAge(seconds) {
        if (seconds === null || seconds === undefined) return null;
        const min = Math.round(seconds / 60);
        if (min < 1) return 'under a minute';
        if (min < 120) return min + ' min';
        const hours = Math.round(min / 60);
        return hours < 48 ? hours + ' hours' : Math.round(hours / 24) + ' days';
    }

    function loadPrefs() {
        try {
            const prefs = JSON.parse(window.localStorage.getItem(PREFS_KEY) || '{}');
            (prefs.collapsed || []).forEach(k => state.collapsed.add(k));
            if (prefs.sortColumn) {
                state.sortColumn = prefs.sortColumn;
                state.sortDirection = prefs.sortDirection === 'desc' ? 'desc' : 'asc';
                state.userSorted = true;
            }
        } catch (e) {
            // Storage unavailable (private window, blocked) - defaults are fine
        }
    }

    function savePrefs() {
        try {
            window.localStorage.setItem(PREFS_KEY, JSON.stringify({
                collapsed: Array.from(state.collapsed),
                sortColumn: state.userSorted ? state.sortColumn : null,
                sortDirection: state.sortDirection
            }));
        } catch (e) {
            // ignore
        }
    }

    // ------------------------------------------------------------------
    // Loading

    function fetchData() {
        return fetch(ENDPOINT, { credentials: 'same-origin' }).then(response => {
            if (!response.ok) {
                throw new Error('status ' + response.status);
            }
            return response.json();
        });
    }

    function load(promise) {
        if (state.loading) return;
        state.loading = true;
        setBusy(true);

        (promise || fetchData())
            .then(data => {
                if (!data || !data.success) {
                    throw new Error((data && data.message) || 'unexpected response');
                }
                state.data = data;
                if (!state.userSorted) {
                    state.sortColumn = data.config.default_sort || 'replacement_months';
                    state.sortDirection = data.config.default_sort_dir || 'asc';
                }
                showError(null);
                render();
            })
            .catch(error => {
                console.error('DriveAge: error loading drive data:', error);
                showError('Failed to load drive data: ' + error.message);
            })
            .finally(() => {
                state.loading = false;
                setBusy(false);
                scheduleRefresh();
            });
    }

    function setBusy(busy) {
        const btn = document.getElementById('refresh-btn');
        if (btn) {
            btn.disabled = busy;
            btn.classList.toggle('is-loading', busy);
        }
    }

    /**
     * Show (or clear, with null) the error banner. Existing content stays on
     * screen so a failed refresh does not wipe the table.
     */
    function showError(message) {
        const banner = document.getElementById('driveage-error');
        if (banner) {
            banner.textContent = message || '';
            banner.hidden = !message;
        }
        if (message && !state.data) {
            const container = document.getElementById('drive-container');
            if (container) container.innerHTML = '';
        }
    }

    /** Auto-refresh: one timer at a time, paused while the tab is hidden. */
    function scheduleRefresh() {
        clearTimeout(state.refreshTimer);
        state.refreshDue = false;
        const cfg = state.data && state.data.config;
        if (!cfg || !cfg.auto_refresh) return;

        const seconds = Math.max(30, parseInt(cfg.refresh_interval, 10) || 300);
        state.refreshTimer = setTimeout(() => {
            if (document.hidden) {
                state.refreshDue = true; // catch up when the tab is shown again
            } else {
                load();
            }
        }, seconds * 1000);
    }

    // ------------------------------------------------------------------
    // Rendering

    function render() {
        const data = state.data;
        renderSummary(data);
        renderFreshness(data);
        if (DA.charts) {
            DA.charts.risk(document.getElementById('risk-chart'), data);
            DA.charts.forecast(document.getElementById('forecast-chart'), data);
        }
        renderTable();
        const count = document.getElementById('drive-count');
        if (count) count.textContent = data.drive_count + ' drive' + (data.drive_count === 1 ? '' : 's') + ' ·';
    }

    function renderSummary(data) {
        const el = document.getElementById('driveage-summary');
        if (!el) return;

        const drives = data.drives;
        const failing = drives.filter(d => d.health_status === 'failing');
        const watch = drives.filter(d => d.health_status === 'watch');
        const known = drives.filter(d => monthsOf(d) !== null).sort(byUrgency);
        const dueSoon = known.filter(d => monthsOf(d) <= 12);
        const next = known[0];

        const types = {};
        drives.forEach(d => { types[d.physical_type] = (types[d.physical_type] || 0) + 1; });
        const typeText = Object.keys(types).map(t => types[t] + ' ' + (TYPE_LABELS[t] || t)).join(' · ');

        const names = list => list.slice(0, 3).map(d => d.device_name).join(', ') + (list.length > 3 ? ' +' + (list.length - 3) : '');
        const attention = failing.length + watch.length;

        const cards = [
            { title: 'Drives', value: drives.length, sub: typeText },
            {
                title: 'Need attention', value: attention,
                sub: attention ? failing.length + ' failing · ' + watch.length + ' warning' : 'No SMART warnings',
                cls: failing.length ? 'card-critical' : (watch.length ? 'card-warning' : 'card-ok')
            },
            {
                title: 'Due within 12 months', value: dueSoon.length,
                sub: dueSoon.length ? names(dueSoon) : 'None',
                cls: dueSoon.length ? 'card-warning' : ''
            },
            {
                title: 'Next replacement',
                value: next ? next.replacement_prediction.timeline_text : '—',
                sub: next ? next.device_name : 'No estimate available',
                cls: next ? 'card-' + (next.replacement_prediction.timeline_class || '') : ''
            }
        ];

        el.innerHTML = cards.map(c =>
            `<div class="summary-card ${escapeHtml(c.cls || '')}">` +
            `<span class="summary-title">${escapeHtml(c.title)}</span>` +
            `<span class="summary-value">${escapeHtml(c.value)}</span>` +
            `<span class="summary-sub">${escapeHtml(c.sub)}</span></div>`
        ).join('');
    }

    /**
     * How fresh the data is. Unraid refreshes its SMART cache on the Disk
     * Settings poll interval (default 30 minutes) - never fresher than that.
     */
    function renderFreshness(data) {
        const el = document.getElementById('data-age');
        if (!el) return;
        const pollMin = Math.round((data.poll_interval || 1800) / 60);
        const age = formatAge(data.data_age_seconds);
        const updated = new Date(data.timestamp * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        el.textContent = (age ? 'SMART data up to ' + age + ' old' : 'SMART data') +
            ' · Unraid polls every ' + pollMin + ' min · loaded ' + updated;
        el.title = 'Last loaded ' + new Date(data.timestamp * 1000).toLocaleString();
    }

    function buildColumns(cfg) {
        const cols = [
            { key: 'device', label: 'Device', sort: 'device_name' },
            { key: 'identification', label: 'Identification', sort: 'identification', cls: 'col-id' },
            { key: 'size', label: 'Size', sort: 'size_bytes', cls: 'text-right' },
            { key: 'poh', label: 'Power On', sort: 'power_on_hours', cls: 'text-right' }
        ];
        if (cfg.show_temperature) cols.push({ key: 'temp', label: 'Temp', sort: 'temperature', cls: 'text-right' });
        if (cfg.show_smart_status) cols.push({ key: 'smart', label: 'SMART', sort: 'smart_status' });
        if (cfg.show_spin_status) cols.push({ key: 'spin', label: 'Spin' });
        cols.push(
            { key: 'risk', label: 'Risk', sort: 'risk_category' },
            { key: 'health', label: 'Health', sort: 'health_status' },
            { key: 'replacement', label: 'Est. Replacement', sort: 'replacement_months', title: 'Estimated time until recommended replacement' }
        );
        return cols;
    }

    function renderTable() {
        const container = document.getElementById('drive-container');
        if (!container) return;
        const data = state.data;
        state.columns = buildColumns(data.config);

        if (!data.drives.length) {
            container.innerHTML = '<div class="driveage-info">No drives to display. Check the drive-type filters in Settings.</div>';
            return;
        }

        let head = '<tr>';
        state.columns.forEach(col => {
            if (!col.sort) {
                head += `<th scope="col" class="${col.cls || ''}">${escapeHtml(col.label)}</th>`;
                return;
            }
            const active = state.sortColumn === col.sort;
            const ariaSort = active ? (state.sortDirection === 'asc' ? 'ascending' : 'descending') : 'none';
            const title = col.title ? ` title="${escapeHtml(col.title)}"` : '';
            head += `<th scope="col" class="${col.cls || ''}" aria-sort="${ariaSort}">` +
                `<button type="button" class="sort-btn${active ? ' sort-' + state.sortDirection : ''}" data-sort="${col.sort}"${title}>` +
                `${escapeHtml(col.label)}<span class="sort-indicator" aria-hidden="true"></span></button></th>`;
        });
        head += '</tr>';

        const rowStyle = data.config.row_style === 'full' ? 'rows-full' : 'rows-accent';
        container.innerHTML =
            `<div class="driveage-table-container"><table class="driveage-table ${rowStyle}">` +
            `<thead>${head}</thead><tbody></tbody></table></div>`;
        renderBody();
    }

    /** Table body only - sorting, collapsing and refreshes reuse the head. */
    function renderBody() {
        const tbody = document.querySelector('#drive-container tbody');
        if (!tbody) return;
        const data = state.data;
        const byId = {};
        data.drives.forEach(d => { byId[d.device_id] = d; });

        let html = '';
        data.groups.forEach(group => {
            const key = group.type + ':' + group.name;
            const collapsed = state.collapsed.has(key);
            const drives = group.device_ids.map(id => byId[id]).filter(Boolean).sort(compareDrives);
            const n = state.columns.length;

            html += `<tr class="group-header"><th scope="colgroup" colspan="${n}">` +
                `<button type="button" class="group-toggle" data-group="${escapeHtml(key)}" aria-expanded="${!collapsed}">` +
                `<i class="fa fa-chevron-${collapsed ? 'right' : 'down'}" aria-hidden="true"></i> ` +
                `${escapeHtml(groupTitle(group))} <span class="group-count">(${drives.length})</span></button></th></tr>`;

            drives.forEach(drive => { html += rowHtml(drive, collapsed); });
        });
        tbody.innerHTML = html;
    }

    function groupTitle(group) {
        switch (group.type) {
            case 'parity': return 'Array — Parity';
            case 'array': return 'Array — Data Disks';
            case 'cache':
            case 'pool': return 'Pool — ' + group.name;
            case 'unassigned': return 'Unassigned Devices';
            case 'flash': return 'Boot Flash';
            default: return group.name;
        }
    }

    function sortValue(drive, column) {
        switch (column) {
            case 'replacement_months': return monthsOf(drive);
            case 'risk_category': return RISK_RANK[drive.risk_category] ?? null;
            case 'health_status': return HEALTH_RANK[drive.health_status] ?? null;
            default: return drive[column];
        }
    }

    function compareDrives(a, b) {
        const av = sortValue(a, state.sortColumn);
        const bv = sortValue(b, state.sortColumn);

        // Unknown values always sink to the bottom, whatever the direction
        const aNull = av === null || av === undefined;
        const bNull = bv === null || bv === undefined;
        if (aNull !== bNull) return aNull ? 1 : -1;

        let result = 0;
        if (!aNull) {
            result = (typeof av === 'number' && typeof bv === 'number') ? av - bv : naturalCompare(av, bv);
            if (state.sortDirection === 'desc') result = -result;
        }
        // Ties: worse risk first, then natural device order (Disk 2 before Disk 10)
        return result || (riskRank(b) - riskRank(a)) || naturalCompare(a.device_name, b.device_name);
    }

    function rowHtml(drive, collapsed) {
        const id = drive.device_id;
        const expanded = state.expanded.has(id);
        const hidden = collapsed ? ' hidden' : '';
        const classes = 'drive-row' + (drive.is_standby ? ' drive-standby' : '');

        let html = `<tr class="${classes}" data-risk="${escapeHtml(drive.risk_category)}" data-id="${escapeHtml(id)}"${hidden}>`;
        state.columns.forEach(col => { html += cellHtml(col, drive, expanded); });
        html += '</tr>';
        html += `<tr class="detail-row" data-detail-for="${escapeHtml(id)}"${collapsed || !expanded ? ' hidden' : ''}>` +
            `<td colspan="${state.columns.length}">${expanded ? detailHtml(drive) : ''}</td></tr>`;
        return html;
    }

    function cellHtml(col, drive, expanded) {
        const p = drive.replacement_prediction || {};
        switch (col.key) {
            case 'device':
                return `<td class="cell-device"><button type="button" class="detail-toggle" data-toggle-detail="${escapeHtml(drive.device_id)}" ` +
                    `aria-expanded="${expanded}" title="Show details">` +
                    `<i class="fa fa-chevron-${expanded ? 'down' : 'right'}" aria-hidden="true"></i>` +
                    `<span class="device-name">${escapeHtml(drive.device_name)}</span></button>` +
                    `<span class="type-badge">${escapeHtml(TYPE_LABELS[drive.physical_type] || drive.physical_type)}</span></td>`;

            case 'identification':
                return `<td class="col-id" title="${escapeHtml(drive.identification)}">${escapeHtml(truncate(drive.identification, 44))}</td>`;

            case 'size':
                return `<td class="text-right">${escapeHtml(drive.size_human)}</td>`;

            case 'poh': {
                if (drive.power_on_hours === null) {
                    return '<td class="text-right">N/A</td>';
                }
                let cached = '';
                if (drive.poh_cached_at) {
                    const when = new Date(drive.poh_cached_at * 1000).toLocaleString();
                    cached = ` <i class="fa fa-clock-o cached-icon${drive.is_stale ? ' is-stale' : ''}" ` +
                        `title="Last known value from ${escapeHtml(when)} (drive spun down)"></i>`;
                }
                return `<td class="text-right">${escapeHtml(drive.power_on_hours.toLocaleString())} h${cached}` +
                    `<small class="sub">${escapeHtml(drive.power_on_human)}</small></td>`;
            }

            case 'temp':
                return `<td class="text-right ${escapeHtml(drive.temperature_class || 'temp-unknown')}" title="${escapeHtml(drive.temperature_limits)}">${escapeHtml(drive.temperature_formatted)}</td>`;

            case 'smart':
                return `<td><span class="${escapeHtml(drive.smart_status_class || 'smart-unknown')}">${escapeHtml(drive.smart_status)}</span></td>`;

            case 'spin': {
                const icons = { active: 'fa-circle', standby: 'fa-moon-o' };
                const labels = { active: 'Active', standby: 'Standby', 'n/a': 'N/A' };
                const status = String(drive.spin_status).toLowerCase();
                const icon = icons[status] ? `<i class="fa ${icons[status]}" aria-hidden="true"></i> ` : '';
                return `<td><span class="${escapeHtml(drive.spin_status_class || 'spin-unknown')}">${icon}${escapeHtml(labels[status] || 'Unknown')}</span></td>`;
            }

            case 'risk':
                return `<td><span class="risk-pill ${escapeHtml(drive.risk_class)}" ` +
                    `title="${escapeHtml('Age/wear band: ' + drive.age_label + ' · Health: ' + drive.health_label)}">` +
                    `${escapeHtml(drive.risk_label)}</span></td>`;

            case 'health':
                return `<td class="cell-health">${healthBadge(drive)}${warningList(drive.health_warnings)}</td>`;

            case 'replacement': {
                const detail = p.notes || p.reason || '';
                const tip = 'Method: ' + (METHOD_LABELS[p.method] || p.method || 'unknown') + '. ' + detail;
                const conf = p.confidence && p.confidence !== 'none' ? p.confidence + ' confidence' : '';
                return `<td class="cell-replacement" title="${escapeHtml(tip)}">` +
                    `<span class="${escapeHtml(p.timeline_class || 'timeline-unknown')}">${escapeHtml(p.timeline_text || 'Unknown')}</span>` +
                    (conf ? `<small class="sub">${escapeHtml(conf)}</small>` : '') + '</td>';
            }
        }
        return '<td></td>';
    }

    function healthBadge(drive) {
        const icon = HEALTH_ICONS[drive.health_status] || HEALTH_ICONS.unknown;
        return `<span class="health-badge ${escapeHtml(drive.health_class || 'health-unknown')}">` +
            `<i class="fa ${icon}" aria-hidden="true"></i> ${escapeHtml(drive.health_label)}</span>`;
    }

    function warningList(warnings) {
        if (!warnings || !warnings.length) return '';
        return '<ul class="warning-list">' + warnings.map(w => {
            const anchor = HELP_ANCHORS[w.attribute] || 'other-smart-warnings';
            return `<li class="warning-${w.level === 'critical' ? 'critical' : 'caution'}">` +
                `<a href="/Settings/DriveAgeHelp#${anchor}" title="${escapeHtml(w.tooltip)}">${escapeHtml(w.message)}</a></li>`;
        }).join('') + '</ul>';
    }

    function detailHtml(drive) {
        const p = drive.replacement_prediction || {};
        const dl = rows => '<dl>' + rows.filter(r => r[1] !== null && r[1] !== undefined && r[1] !== '')
            .map(r => `<dt>${escapeHtml(r[0])}</dt><dd>${r[2] ? r[1] : escapeHtml(r[1])}</dd>`).join('') + '</dl>';

        const estimate = dl([
            ['Estimate', p.timeline_text],
            ['Method', (METHOD_LABELS[p.method] || p.method) + (p.method ? ' (' + p.method + ')' : '')],
            ['Confidence', p.confidence],
            ['Why', p.notes || p.reason],
            ['Suggested action', p.action]
        ]);

        const risk = dl([
            ['Overall', `<span class="risk-pill ${escapeHtml(drive.risk_class)}">${escapeHtml(drive.risk_label)}</span>`, true],
            ['Age / wear band', drive.age_label],
            ['SMART health', healthBadge(drive), true],
            ['SMART overall', drive.smart_status]
        ]);

        const attrs = DETAIL_ATTRS
            .filter(([field]) => drive[field] !== null && drive[field] !== undefined)
            .map(([field, label, fmt]) => [label, fmt ? fmt(drive[field], drive) : String(drive[field])]);
        const smart = attrs.length ? dl(attrs) : '<p class="muted">No SMART attributes available.</p>';

        let source;
        if (drive.poh_cached_at) {
            source = 'Last known values from ' + new Date(drive.poh_cached_at * 1000).toLocaleString() + ' (drive spun down)';
        } else if (drive.data_age_seconds !== null && drive.data_age_seconds !== undefined) {
            source = drive.data_age_seconds > 0 ? 'Unraid SMART cache, ' + formatAge(drive.data_age_seconds) + ' old' : 'Queried directly';
        } else if (drive.is_standby) {
            source = 'Drive spun down - no data read yet';
        }
        const device = dl([
            ['Device', drive.device_path],
            ['Identification', drive.identification],
            ['Model', drive.model !== 'Unknown' ? drive.model : null],
            ['Serial', drive.serial !== 'Unknown' ? drive.serial : null],
            ['Size', drive.size_human],
            ['Power-on time', drive.power_on_hours !== null ? drive.power_on_human : null],
            ['Temperature', drive.temperature !== null ? drive.temperature_formatted + ' \u2014 ' + drive.temperature_limits : null],
            ['Data source', source]
        ]);

        let warnings = '';
        if (drive.health_warnings && drive.health_warnings.length) {
            warnings = '<table class="detail-warnings"><thead><tr><th>Warning</th><th>Level</th><th>Action</th><th>Details</th></tr></thead><tbody>' +
                drive.health_warnings.map(w =>
                    `<tr><td>${escapeHtml(w.message)}</td><td class="warning-${w.level === 'critical' ? 'critical' : 'caution'}">${escapeHtml(w.level)}</td>` +
                    `<td>${escapeHtml(w.action)}</td><td>${escapeHtml(w.tooltip)}</td></tr>`
                ).join('') + '</tbody></table>';
        }

        return '<div class="detail-grid">' +
            `<section><h4>Replacement estimate</h4>${estimate}</section>` +
            `<section><h4>Risk</h4>${risk}</section>` +
            `<section><h4>SMART values</h4>${smart}</section>` +
            `<section><h4>Device</h4>${device}</section>` +
            '</div>' + warnings;
    }

    // ------------------------------------------------------------------
    // Interaction

    function handleSort(column) {
        if (state.sortColumn === column) {
            state.sortDirection = state.sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            state.sortColumn = column;
            // Soonest replacement / worst first; everything else descending
            state.sortDirection = (column === 'replacement_months' || column === 'device_name' || column === 'identification') ? 'asc' : 'desc';
        }
        state.userSorted = true;
        savePrefs();

        document.querySelectorAll('#drive-container th[aria-sort]').forEach(th => {
            const btn = th.querySelector('[data-sort]');
            const active = btn && btn.dataset.sort === state.sortColumn;
            th.setAttribute('aria-sort', active ? (state.sortDirection === 'asc' ? 'ascending' : 'descending') : 'none');
            if (btn) {
                btn.classList.remove('sort-asc', 'sort-desc');
                if (active) btn.classList.add('sort-' + state.sortDirection);
            }
        });
        renderBody();
    }

    function toggleGroup(key) {
        if (state.collapsed.has(key)) {
            state.collapsed.delete(key);
        } else {
            state.collapsed.add(key);
        }
        savePrefs();
        renderBody();
    }

    function toggleDetail(id) {
        const drive = state.data && state.data.drives.find(d => d.device_id === id);
        const detailRow = Array.from(document.querySelectorAll('#drive-container tr.detail-row'))
            .find(tr => tr.dataset.detailFor === id);
        if (!drive || !detailRow) return;

        const open = !state.expanded.has(id);
        if (open) {
            state.expanded.add(id);
            detailRow.firstElementChild.innerHTML = detailHtml(drive);
        } else {
            state.expanded.delete(id);
        }
        detailRow.hidden = !open;

        const btn = detailRow.previousElementSibling && detailRow.previousElementSibling.querySelector('.detail-toggle');
        if (btn) {
            btn.setAttribute('aria-expanded', String(open));
            const icon = btn.querySelector('.fa');
            if (icon) icon.className = 'fa fa-chevron-' + (open ? 'down' : 'right');
        }
    }

    function onClick(event) {
        const target = event.target;
        const sortBtn = target.closest('[data-sort]');
        if (sortBtn) {
            handleSort(sortBtn.dataset.sort);
            return;
        }
        const groupBtn = target.closest('[data-group]');
        if (groupBtn) {
            toggleGroup(groupBtn.dataset.group);
            return;
        }
        const detailBtn = target.closest('[data-toggle-detail]');
        if (detailBtn) {
            toggleDetail(detailBtn.dataset.toggleDetail);
            return;
        }
        // Clicking anywhere else on a drive row (not a link) opens its details
        const row = target.closest('tr.drive-row');
        if (row && !target.closest('a, button')) {
            toggleDetail(row.dataset.id);
        }
    }

    /**
     * The sticky table header needs an opaque background; themes differ, so
     * use the first non-transparent background behind the dashboard.
     */
    function setPageBackground(root) {
        for (let el = root; el; el = el.parentElement) {
            const bg = getComputedStyle(el).backgroundColor;
            if (bg && bg !== 'transparent' && !/^rgba\(.*,\s*0\)$/.test(bg)) {
                root.style.setProperty('--da-page-bg', bg);
                return;
            }
        }
    }

    function init() {
        const root = document.getElementById('driveage-dashboard');
        if (!root) return;

        setPageBackground(root);
        loadPrefs();
        root.addEventListener('click', onClick);

        const refreshBtn = document.getElementById('refresh-btn');
        if (refreshBtn) refreshBtn.addEventListener('click', () => load());

        document.addEventListener('visibilitychange', () => {
            if (!document.hidden && state.refreshDue) load();
        });

        // Use the request the page started early, if there is one
        const pending = window.driveagePending;
        window.driveagePending = null;
        load(pending || null);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window.DriveAge);
