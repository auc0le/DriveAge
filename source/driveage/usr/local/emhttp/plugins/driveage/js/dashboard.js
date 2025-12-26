/**
 * DriveAge Plugin - Dashboard JavaScript
 */

let driveData = null;
let sortColumn = 'power_on_hours';
let sortDirection = 'desc';
let chartInstances = null;

/**
 * Escape HTML to prevent XSS
 *
 * @param {string} str String to escape
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
    initializeDashboard();
});

/**
 * Initialize the dashboard
 */
function initializeDashboard() {
    console.log('DriveAge: initializeDashboard() started');

    // Check if settings were just changed
    const settingsChanged = localStorage.getItem('driveage_settings_changed');

    if (settingsChanged) {
        console.log('DriveAge: Settings were changed, will reload data');
        // Clear the flag
        localStorage.removeItem('driveage_settings_changed');
    }

    // Load drive data
    // No force refresh needed - we always read from Unraid's current cache
    console.log('DriveAge: About to call loadDriveData()');
    loadDriveData();
    console.log('DriveAge: loadDriveData() called');

    // Set up event listeners
    console.log('DriveAge: About to setup event listeners');
    setupEventListeners();
    console.log('DriveAge: Event listeners setup complete');
}

/**
 * Set up event listeners
 */
function setupEventListeners() {
    // Refresh button
    const refreshBtn = document.getElementById('refresh-btn');
    if (refreshBtn) {
        refreshBtn.addEventListener('click', function() {
            loadDriveData();
        });
    }
}

/**
 * Load drive data from server
 *
 * Fetches drive data from Unraid's SMART cache (updated every 30s by emhttpd)
 */
function loadDriveData() {
    console.log('DriveAge: loadDriveData called');
    showLoading();

    const url = '/plugins/driveage/scripts/get_drive_data.php';
    console.log('DriveAge: Fetching from:', url);

    fetch(url)
        .then(response => {
            console.log('DriveAge: Fetch response received, status:', response.status, 'ok:', response.ok);
            if (!response.ok) {
                throw new Error('Failed to fetch drive data (status: ' + response.status + ')');
            }
            return response.json();
        })
        .then(data => {
            console.log('DriveAge: Data parsed, success:', data.success, 'drive_count:', data.drive_count);
            if (data.success) {
                driveData = data;
                console.log('DriveAge: Calling renderDashboard()');
                renderDashboard();
                updateLastUpdated(data.timestamp);
            } else {
                console.log('DriveAge: Data success=false, message:', data.message);
                showError(data.message || 'Failed to load drive data');
            }
        })
        .catch(error => {
            console.error('DriveAge: Error loading drive data:', error);
            showError('Failed to load drive data: ' + error.message);
        });
}

/**
 * Apply dynamic colors from JSON response to all elements
 */
function applyDynamicColors() {
    if (!driveData || !driveData.colors || !driveData.text_colors) {
        console.warn('DriveAge: No color data available');
        return;
    }

    const categories = ['minimal_risk', 'low_risk', 'moderate_risk', 'elevated_risk', 'high_risk'];

    categories.forEach(category => {
        const bgColor = driveData.colors[category];
        const textColor = driveData.text_colors[category];

        if (!bgColor || !textColor) {
            console.warn(`DriveAge: Missing colors for category ${category}`);
            return;
        }

        // Apply to all elements with this category class
        // Handle both underscore and hyphen versions
        const selectors = [
            `.age-${category}`,
            `.age-${category.replace('_', '-')}`
        ];

        selectors.forEach(selector => {
            document.querySelectorAll(selector).forEach(element => {
                element.style.backgroundColor = bgColor;
                element.style.color = textColor;
            });
        });
    });

    console.log('DriveAge: Dynamic colors applied');
}

/**
 * Render the dashboard in table view
 */
function renderDashboard() {
    if (!driveData) {
        return;
    }

    hideLoading();
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

    // Sort drives
    const sortedDrives = sortDrives([...driveData.drives], sortColumn, sortDirection);

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
    html += '<th title="Estimated time until recommended replacement">Est. Replacement</th>';
    html += '</tr></thead>';
    html += '<tbody>';

    // Group drives
    const grouped = driveData.grouped;

    for (const arrayName in grouped) {
        for (const driveType in grouped[arrayName]) {
            const drives = grouped[arrayName][driveType];

            // Add group header
            html += `<tr class="group-header" onclick="toggleGroup(this)">`;
            html += `<td colspan="20">`;
            html += `<span class="group-toggle">▼</span>`;
            html += `${arrayName} - ${formatDriveType(driveType)} (${drives.length})`;
            html += `</td></tr>`;

            // Add drives in this group
            drives.forEach(drive => {
                let rowClass = escapeHtml(drive.color_class) + (drive.is_oldest ? ' oldest-drive' : '');

                // Add standby and stale classes for visual distinction
                if (drive.is_standby) {
                    rowClass += ' drive-standby';
                    if (drive.is_stale) {
                        rowClass += ' drive-stale';
                    }
                }

                html += `<tr class="${rowClass} group-row">`;
                html += `<td>${escapeHtml(drive.device_name)}</td>`;
                html += `<td title="${escapeHtml(drive.identification)}">${escapeHtml(truncate(drive.identification, 50))}</td>`;
                html += `<td class="text-right">${escapeHtml(drive.size_human)}</td>`;
                html += `<td class="text-right">${drive.power_on_hours !== null ? escapeHtml(drive.power_on_hours.toLocaleString()) : 'N/A'}</td>`;
                html += `<td>${escapeHtml(drive.power_on_human)}</td>`;

                if (driveData.config.show_temperature) {
                    // Use pre-calculated temperature_class from server (includes drive type awareness)
                    const tempClass = drive.temperature_class || getTemperatureClass(drive.temperature, drive.physical_type);
                    html += `<td class="${escapeHtml(tempClass)}">${escapeHtml(drive.temperature_formatted)}</td>`;
                }

                if (driveData.config.show_smart_status) {
                    html += `<td>${drive.smart_status_formatted}</td>`;
                }

                if (driveData.config.show_spin_status) {
                    html += `<td>${drive.spin_status_formatted}</td>`;
                }

                html += `<td>${escapeHtml(drive.age_label)}</td>`;

                // Health Status column
                html += '<td class="health-status-cell">';
                if (drive.has_warnings) {
                    drive.health_warnings.forEach(warning => {
                        const iconClass = warning.level === 'critical' ? 'warning-critical' : 'warning-caution';
                        const icon = warning.level === 'critical' ? '⚠️' : '⚡';
                        html += `<span class="${iconClass}" title="${escapeHtml(warning.tooltip)}">`;
                        html += `${icon} ${escapeHtml(warning.message)}`;
                        html += '</span><br>';
                    });
                } else if (drive.power_on_hours === null || drive.power_on_hours === 0 ||
                           drive.smart_status === 'UNKNOWN' || drive.smart_status === 'N/A') {
                    // No SMART data available
                    html += '<span class="health-unknown" title="No SMART data available">N/A</span>';
                } else {
                    html += '<span class="health-ok" title="No warnings detected">✓ Healthy</span>';
                }
                html += '</td>';

                // Est. Replacement column
                const prediction = drive.replacement_prediction || {};
                const tooltipText = `Confidence: ${prediction.confidence || 'none'}. Method: ${prediction.method || 'unknown'}. ${prediction.notes || ''}`;
                html += `<td class="${prediction.timeline_class || 'timeline-unknown'}" title="${escapeHtml(tooltipText)}">`;
                html += escapeHtml(prediction.timeline_text || 'Unknown');
                html += '</td>';

                html += '</tr>';
            });
        }
    }

    html += '</tbody></table></div>';

    container.innerHTML = html;

    // Add sort event listeners
    document.querySelectorAll('.driveage-table th.sortable').forEach(th => {
        th.addEventListener('click', function() {
            const column = this.dataset.column;
            handleSort(column);
        });
    });

    // Update sort indicators
    updateSortIndicators();
}

/**
 * Handle column sorting
 */
function handleSort(column) {
    if (sortColumn === column) {
        // Toggle direction
        sortDirection = sortDirection === 'asc' ? 'desc' : 'asc';
    } else {
        // New column
        sortColumn = column;
        sortDirection = 'desc';
    }

    renderDashboard();
}

/**
 * Sort drives by column
 */
function sortDrives(drives, column, direction) {
    return drives.sort((a, b) => {
        let aVal = a[column];
        let bVal = b[column];

        // Handle nulls
        if (aVal === null || aVal === undefined) return 1;
        if (bVal === null || bVal === undefined) return -1;

        // Numeric comparison
        if (typeof aVal === 'number' && typeof bVal === 'number') {
            return direction === 'asc' ? aVal - bVal : bVal - aVal;
        }

        // String comparison
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
 * Get temperature CSS class
 *
 * @param {number} temp Temperature in Celsius
 * @param {string} physicalType Physical drive type ('hdd' or 'nvme')
 * @return {string} CSS class name
 */
function getTemperatureClass(temp, physicalType = 'hdd') {
    if (temp === null || temp === '') return 'temp-unknown';

    temp = parseInt(temp);

    // NVMe drives run hotter - use different thresholds
    if (physicalType === 'nvme') {
        // NVMe thresholds: < 50°C, 50-64°C, 65-74°C, ≥ 75°C
        if (temp >= 75) return 'temp-critical';  // Red - Critical
        if (temp >= 65) return 'temp-high';      // Orange - High
        if (temp >= 50) return 'temp-elevated';  // Yellow - Elevated
        return 'temp-normal';                     // Green - Normal
    } else {
        // HDD thresholds: < 40°C, 40-49°C, 50-59°C, ≥ 60°C
        if (temp >= 60) return 'temp-critical';  // Red - Critical
        if (temp >= 50) return 'temp-high';      // Orange - High
        if (temp >= 40) return 'temp-elevated';  // Yellow - Elevated
        return 'temp-normal';                     // Green - Normal
    }
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

    // Destroy charts when refreshing
    if (window.destroyCharts) {
        destroyCharts();
    }
}

/**
 * Hide loading indicator
 */
function hideLoading() {
    // Loading is hidden when content is rendered
}

/**
 * Show error message
 */
function showError(message) {
    const container = document.getElementById('drive-container');
    if (container) {
        container.innerHTML = `<div class="driveage-error">Error: ${message}</div>`;
    }

    // Destroy charts on error
    if (window.destroyCharts) {
        destroyCharts();
    }
}
