/**
 * DriveAge Plugin - Dashboard JavaScript
 */

let driveData = null;
let sortColumn = null;        // set from server config on first load
let sortDirection = null;
let userSorted = false;       // user clicks override the configured default
let autoRefreshTimer = null;

/**
 * Escape HTML to prevent XSS
 *
 * @param {*} str Value to escape
 * @return {string} Escaped string
 */
function escapeHtml(str) {
    if (str === null || str === undefined || str === '') {
        return '';
    }

    const div = document.createElement('div');
    div.textContent = String(str);
    return div.innerHTML;
}

// Initialize dashboard when DOM is ready
document.addEventListener('DOMContentLoaded', function() {
    loadDriveData();

    const refreshBtn = document.getElementById('refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            loadDriveData();
        });
    }
});

/**
 * Load drive data from server
 */
function loadDriveData() {
    showLoading();

    fetch('/plugins/driveage/scripts/get_drive_data.php')
        .then(response => {
            if (!response.ok) {
                throw new Error('Failed to fetch drive data (status: ' + response.status + ')');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                driveData = data;
                applyConfigDefaults();
                renderDashboard();
                updateLastUpdated(data.timestamp);
                updateDataAge(data);
                setupAutoRefresh();
            } else {
                showError(data.message || 'Failed to load drive data');
            }
        })
        .catch(error => {
            console.error('DriveAge: Error loading drive data:', error);
            showError('Failed to load drive data: ' + error.message);
        });
}

/**
 * Apply configured default sort (unless the user has already sorted).
 */
function applyConfigDefaults() {
    if (userSorted || !driveData || !driveData.config) {
        return;
    }
    sortColumn = driveData.config.default_sort || 'replacement_months';
    sortDirection = driveData.config.default_sort_dir || (sortColumn === 'replacement_months' ? 'asc' : 'desc');
}

/**
 * Start/refresh the auto-refresh timer from server config.
 */
function setupAutoRefresh() {
    if (autoRefreshTimer !== null) {
        clearInterval(autoRefreshTimer);
        autoRefreshTimer = null;
    }

    if (driveData && driveData.config && driveData.config.auto_refresh) {
        const seconds = Math.max(30, parseInt(driveData.config.refresh_interval, 10) || 300);
        autoRefreshTimer = setInterval(loadDriveData, seconds * 1000);
    }
}

/**
 * Apply dynamic colors from JSON response to all category-classed elements
 */
function applyDynamicColors() {
    if (!driveData || !driveData.colors || !driveData.text_colors) {
        return;
    }

    const categories = ['minimal_risk', 'low_risk', 'moderate_risk', 'elevated_risk', 'high_risk'];

    categories.forEach(category => {
        const bgColor = driveData.colors[category];
        const textColor = driveData.text_colors[category];

        if (!bgColor || !textColor) {
            return;
        }

        [`.age-${category}`, `.age-${category.replace('_', '-')}`].forEach(selector => {
            document.querySelectorAll(selector).forEach(element => {
                element.style.backgroundColor = bgColor;
                element.style.color = textColor;
            });
        });
    });
}

/**
 * Render the dashboard
 */
function renderDashboard() {
    if (!driveData) {
        return;
    }

    renderTableView();
    renderCharts(driveData);
    updateDriveCount(driveData.drive_count);
    applyDynamicColors();
}

/**
 * Render table view
 */
function renderTableView() {
    const container = document.getElementById('drive-container');
    if (!container) return;

    let html = '<div class="driveage-table-container">';
    html += '<table class="driveage-table">';
    html += '<thead><tr>';
    html += '<th class="sortable" data-column="device_name">Device</th>';
    html += '<th class="sortable" data-column="identification">Identification</th>';
    html += '<th class="sortable" data-column="size_bytes">Size</th>';
    html += '<th class="sortable" data-column="power_on_hours">Power On Hours</th>';
    html += '<th>Human Format</th>';

    if (driveData.config.show_temperature) {
        html += '<th class="sortable" data-column="temperature">Temp</th>';
    }

    if (driveData.config.show_smart_status) {
        html += '<th class="sortable" data-column="smart_status">SMART</th>';
    }

    if (driveData.config.show_spin_status) {
        html += '<th>Spin</th>';
    }

    html += '<th>Age</th>';
    html += '<th>Health Status</th>';
    html += '<th class="sortable" data-column="replacement_months" title="Estimated time until recommended replacement">Est. Replacement</th>';
    html += '</tr></thead>';
    html += '<tbody>';

    const grouped = driveData.grouped;

    for (const arrayName in grouped) {
        for (const driveType in grouped[arrayName]) {
            const drives = sortDrives([...grouped[arrayName][driveType]], sortColumn, sortDirection);

            html += `<tr class="group-header" onclick="toggleGroup(this)">`;
            html += `<td colspan="20">`;
            html += `<span class="group-toggle">▼</span>`;
            html += `${escapeHtml(arrayName)} - ${escapeHtml(formatDriveType(driveType))} (${drives.length})`;
            html += `</td></tr>`;

            drives.forEach(drive => {
                html += renderDriveRow(drive);
            });
        }
    }

    html += '</tbody></table></div>';

    container.innerHTML = html;

    // Sort listeners
    document.querySelectorAll('.driveage-table th.sortable').forEach(th => {
        th.addEventListener('click', function() {
            handleSort(this.dataset.column);
        });
    });

    updateSortIndicators();
}

/**
 * Render a single drive row
 */
function renderDriveRow(drive) {
    let rowClass = escapeHtml(drive.risk_class) + (drive.risk_category === 'high_risk' ? ' oldest-drive' : '');

    if (drive.is_standby) {
        rowClass += ' drive-standby';
        if (drive.is_stale) {
            rowClass += ' drive-stale';
        }
    }

    let html = `<tr class="${rowClass} group-row">`;
    html += `<td>${escapeHtml(drive.device_name)}</td>`;
    html += `<td title="${escapeHtml(drive.identification)}">${escapeHtml(truncate(drive.identification, 50))}</td>`;
    html += `<td class="text-right">${escapeHtml(drive.size_human)}</td>`;
    html += `<td class="text-right">${drive.power_on_hours !== null ? escapeHtml(drive.power_on_hours.toLocaleString()) : 'N/A'}</td>`;
    html += `<td>${escapeHtml(drive.power_on_human)}</td>`;

    if (driveData.config.show_temperature) {
        html += `<td class="${escapeHtml(drive.temperature_class || 'temp-unknown')}">${escapeHtml(drive.temperature_formatted)}</td>`;
    }

    if (driveData.config.show_smart_status) {
        html += `<td><span class="${escapeHtml(drive.smart_status_class || 'smart-unknown')}">${escapeHtml(drive.smart_status)}</span></td>`;
    }

    if (driveData.config.show_spin_status) {
        html += `<td><span class="${escapeHtml(drive.spin_status_class || 'spin-unknown')}">${escapeHtml(formatSpinStatus(drive.spin_status))}</span></td>`;
    }

    html += `<td>${escapeHtml(drive.risk_label)}</td>`;

    // Health Status column
    html += '<td class="health-status-cell">';
    if (drive.has_warnings) {
        const helpAnchors = {
            'smart_overall_failed': 'other-smart-warnings',
            'reallocated_sectors': 'reallocated-sectors',
            'pending_sectors': 'other-smart-warnings',
            'uncorrectable_sectors': 'other-smart-warnings',
            'reported_uncorrectable': 'other-smart-warnings',
            'command_timeout': 'other-smart-warnings',
            'media_errors': 'nvme-warnings',
            'critical_warning': 'nvme-warnings'
        };

        drive.health_warnings.forEach(warning => {
            const iconClass = warning.level === 'critical' ? 'warning-critical' : 'warning-caution';
            const icon = warning.level === 'critical' ? '⚠️' : '⚡';
            const anchor = helpAnchors[warning.attribute] || 'other-smart-warnings';

            html += `<a href="/Settings/DriveAgeHelp#${anchor}" class="${iconClass}" title="${escapeHtml(warning.tooltip)}" style="text-decoration: none; color: inherit;">`;
            html += `${icon} ${escapeHtml(warning.message)}`;
            html += '</a><br>';
        });
    } else if (drive.health_status === 'unknown') {
        html += '<span class="health-unknown" title="No SMART data available">N/A</span>';
    } else {
        html += '<span class="health-ok" title="No warnings detected">✓ Healthy</span>';
    }
    html += '</td>';

    // Est. Replacement column
    const prediction = drive.replacement_prediction || {};
    const detail = prediction.notes || prediction.reason || '';
    const tooltipText = `Confidence: ${prediction.confidence || 'none'}. Method: ${prediction.method || 'unknown'}. ${detail}`;
    html += `<td class="${escapeHtml(prediction.timeline_class || 'timeline-unknown')}" title="${escapeHtml(tooltipText)}">`;
    html += escapeHtml(prediction.timeline_text || 'Unknown');
    html += '</td>';

    html += '</tr>';
    return html;
}

/**
 * Handle column sorting
 */
function handleSort(column) {
    userSorted = true;

    if (sortColumn === column) {
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        sortColumn = column;
        // Ascending for replacement (soonest first), descending otherwise
        sortDirection = (column === 'replacement_months') ? 'asc' : 'desc';
    }

    renderDashboard();
}

/**
 * Sort drives by column
 */
function sortDrives(drives, column, direction) {
    return drives.sort((a, b) => {
        let aVal, bVal;

        if (column === 'replacement_months') {
            aVal = a.replacement_prediction?.months_remaining;
            bVal = b.replacement_prediction?.months_remaining;
        } else {
            aVal = a[column];
            bVal = b[column];
        }

        // Nulls (unknown) always sink to the end
        if (aVal === null || aVal === undefined) return 1;
        if (bVal === null || bVal === undefined) return -1;

        if (typeof aVal === 'number' && typeof bVal === 'number') {
            return direction === 'asc' ? aVal - bVal : bVal - aVal;
        }

        aVal = String(aVal).toLowerCase();
        bVal = String(bVal).toLowerCase();

        if (direction === 'asc') {
            return aVal < bVal ? -1 : aVal > bVal ? 1 : 0;
        } else {
            return aVal > bVal ? -1 : aVal < bVal ? 1 : 0;
        }
    });
}

/**
 * Update sort indicators on table headers
 */
function updateSortIndicators() {
    document.querySelectorAll('.driveage-table th.sortable').forEach(th => {
        th.classList.remove('sort-asc', 'sort-desc');

        if (th.dataset.column === sortColumn) {
            th.classList.add(sortDirection === 'asc' ? 'sort-asc' : 'sort-desc');
        }
    });
}

/**
 * Toggle group visibility
 */
function toggleGroup(headerRow) {
    const groupRows = [];
    let nextRow = headerRow.nextElementSibling;

    while (nextRow && nextRow.classList.contains('group-row')) {
        groupRows.push(nextRow);
        nextRow = nextRow.nextElementSibling;
    }

    const isCollapsed = groupRows[0]?.classList.contains('group-collapsed');
    const toggle = headerRow.querySelector('.group-toggle');

    groupRows.forEach(row => {
        row.classList.toggle('group-collapsed', !isCollapsed);
    });

    if (toggle) {
        toggle.textContent = isCollapsed ? '▼' : '▶';
    }
}

/**
 * Display text for a spin status value
 */
function formatSpinStatus(status) {
    const map = {
        'active': 'Active',
        'standby': 'Standby',
        'n/a': 'N/A'
    };
    return map[String(status).toLowerCase()] || 'Unknown';
}

/**
 * Format drive type for display
 */
function formatDriveType(type) {
    const types = {
        'parity': 'Parity',
        'array': 'Array Disks',
        'cache': 'Cache',
        'pool': 'Pool',
        'flash': 'USB Boot',
        'unassigned': 'Unassigned'
    };

    return types[type] || type;
}

/**
 * Truncate string with ellipsis
 */
function truncate(str, maxLength) {
    if (!str) return '';
    if (str.length <= maxLength) return str;
    return str.substring(0, maxLength - 3) + '...';
}

/**
 * Update last updated timestamp
 */
function updateLastUpdated(timestamp) {
    const element = document.getElementById('last-updated');
    if (element) {
        const date = new Date(timestamp * 1000);
        element.textContent = 'Last updated: ' + date.toLocaleString();
    }
}

/**
 * Show how old the underlying SMART cache data is.
 * Unraid refreshes its SMART cache on the Disk Settings poll interval
 * (default 30 minutes) - the dashboard cannot be fresher than that.
 */
function updateDataAge(data) {
    const element = document.getElementById('data-age');
    if (!element) return;

    const pollMin = Math.round((data.poll_interval || 1800) / 60);

    if (data.data_age_seconds === null || data.data_age_seconds === undefined) {
        element.textContent = `SMART data refreshed by Unraid every ${pollMin} min`;
        return;
    }

    const ageMin = Math.round(data.data_age_seconds / 60);
    const ageText = ageMin < 1 ? '<1 min' : `${ageMin} min`;
    element.textContent = `SMART data: up to ${ageText} old (Unraid refreshes every ${pollMin} min)`;
}

/**
 * Update drive count display
 */
function updateDriveCount(count) {
    const element = document.getElementById('drive-count');
    if (element) {
        element.textContent = `Total Drives: ${count}`;
    }
}

/**
 * Show loading indicator
 */
function showLoading() {
    const container = document.getElementById('drive-container');
    if (container) {
        container.innerHTML = '<div class="driveage-loading"><div class="spinner"></div> Loading drive data...</div>';
    }

    if (window.destroyCharts) {
        destroyCharts();
    }
}

/**
 * Show error message
 */
function showError(message) {
    const container = document.getElementById('drive-container');
    if (container) {
        container.innerHTML = `<div class="driveage-error">Error: ${escapeHtml(message)}</div>`;
    }

    if (window.destroyCharts) {
        destroyCharts();
    }
}
