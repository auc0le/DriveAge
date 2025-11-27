/**
 * DriveAge Plugin - Dashboard JavaScript
 */

let driveData = null;
let currentView = 'table';
let sortColumn = 'power_on_hours';
let sortDirection = 'desc';

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

    // Load drive data
    console.log('DriveAge: About to call loadDriveData()');
    loadDriveData(false);
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
            loadDriveData(true);
        });
    }

    // View toggle buttons
    const tableViewBtn = document.getElementById('view-table-btn');
    const cardViewBtn = document.getElementById('view-card-btn');

    if (tableViewBtn) {
        tableViewBtn.addEventListener('click', function() {
            switchView('table');
        });
    }

    if (cardViewBtn) {
        cardViewBtn.addEventListener('click', function() {
            switchView('card');
        });
    }
}

/**
 * Load drive data from server
 */
function loadDriveData(forceRefresh = false) {
    console.log('DriveAge: loadDriveData called, forceRefresh:', forceRefresh);
    showLoading();

    const url = '/plugins/driveage/scripts/get_drive_data.php' + (forceRefresh ? '?refresh=true' : '');
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
 * Render the dashboard based on current view mode
 */
function renderDashboard() {
    if (!driveData) {
        return;
    }

    hideLoading();

    if (currentView === 'table') {
        renderTableView();
    } else {
        renderCardView();
    }

    updateDriveCount(driveData.drive_count);
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
                const rowClass = escapeHtml(drive.color_class) + (drive.is_oldest ? ' oldest-drive' : '');
                html += `<tr class="${rowClass} group-row">`;
                html += `<td>${escapeHtml(drive.device_name)}</td>`;
                html += `<td title="${escapeHtml(drive.identification)}">${escapeHtml(truncate(drive.identification, 50))}</td>`;
                html += `<td class="text-right">${escapeHtml(drive.size_human)}</td>`;
                html += `<td class="text-right">${escapeHtml(drive.power_on_hours.toLocaleString())}</td>`;
                html += `<td>${escapeHtml(drive.power_on_human)}</td>`;

                if (driveData.config.show_temperature) {
                    html += `<td class="${escapeHtml(getTemperatureClass(drive.temperature))}">${escapeHtml(drive.temperature_formatted)}</td>`;
                }

                if (driveData.config.show_smart_status) {
                    html += `<td>${escapeHtml(drive.smart_status_formatted)}</td>`;
                }

                if (driveData.config.show_spin_status) {
                    html += `<td>${escapeHtml(drive.spin_status_formatted)}</td>`;
                }

                html += `<td>${escapeHtml(drive.age_label)}</td>`;
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
 * Render card view
 */
function renderCardView() {
    const container = document.getElementById('drive-container');
    if (!container) return;

    // Sort drives
    const sortedDrives = sortDrives([...driveData.drives], sortColumn, sortDirection);

    let html = '<div class="driveage-cards">';

    sortedDrives.forEach(drive => {
        const cardClass = escapeHtml(drive.color_class) + (drive.is_oldest ? ' oldest-drive' : '');

        html += `<div class="drive-card ${cardClass}">`;
        html += `<div class="drive-card-header">${escapeHtml(drive.device_name)}</div>`;
        html += `<div class="drive-card-body">`;

        html += `<div class="drive-card-row">`;
        html += `<span class="drive-card-label">Model:</span>`;
        html += `<span class="drive-card-value" title="${escapeHtml(drive.identification)}">${escapeHtml(truncate(drive.model, 30))}</span>`;
        html += `</div>`;

        html += `<div class="drive-card-row">`;
        html += `<span class="drive-card-label">Size:</span>`;
        html += `<span class="drive-card-value">${escapeHtml(drive.size_human)}</span>`;
        html += `</div>`;

        html += `<div class="drive-card-row">`;
        html += `<span class="drive-card-label">Power On Hours:</span>`;
        html += `<span class="drive-card-value">${escapeHtml(drive.power_on_hours.toLocaleString())}</span>`;
        html += `</div>`;

        html += `<div class="drive-card-row">`;
        html += `<span class="drive-card-label">Age:</span>`;
        html += `<span class="drive-card-value">${escapeHtml(drive.power_on_human)}</span>`;
        html += `</div>`;

        if (driveData.config.show_temperature) {
            html += `<div class="drive-card-row">`;
            html += `<span class="drive-card-label">Temperature:</span>`;
            html += `<span class="drive-card-value ${escapeHtml(getTemperatureClass(drive.temperature))}">${escapeHtml(drive.temperature_formatted)}</span>`;
            html += `</div>`;
        }

        if (driveData.config.show_smart_status) {
            html += `<div class="drive-card-row">`;
            html += `<span class="drive-card-label">SMART Status:</span>`;
            html += `<span class="drive-card-value">${escapeHtml(drive.smart_status_formatted)}</span>`;
            html += `</div>`;
        }

        if (driveData.config.show_spin_status) {
            html += `<div class="drive-card-row">`;
            html += `<span class="drive-card-label">Spin Status:</span>`;
            html += `<span class="drive-card-value">${escapeHtml(drive.spin_status_formatted)}</span>`;
            html += `</div>`;
        }

        html += `<div class="drive-card-row">`;
        html += `<span class="drive-card-label">Category:</span>`;
        html += `<span class="drive-card-value">${escapeHtml(drive.age_label)}</span>`;
        html += `</div>`;

        html += `<div class="drive-card-row">`;
        html += `<span class="drive-card-label">Location:</span>`;
        html += `<span class="drive-card-value">${escapeHtml(drive.array_name)}</span>`;
        html += `</div>`;

        html += `</div></div>`;
    });

    html += '</div>';

    container.innerHTML = html;
}

/**
 * Switch between table and card view
 */
function switchView(view) {
    currentView = view;

    // Update button states
    document.getElementById('view-table-btn')?.classList.toggle('active', view === 'table');
    document.getElementById('view-card-btn')?.classList.toggle('active', view === 'card');

    // Re-render
    renderDashboard();
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
 */
function getTemperatureClass(temp) {
    if (temp === null || temp === '') return 'temp-unknown';

    temp = parseInt(temp);

    if (temp >= 60) return 'temp-critical';
    if (temp >= 50) return 'temp-high';
    if (temp >= 40) return 'temp-elevated';
    return 'temp-normal';
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
}
