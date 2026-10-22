/**
 * DriveAge Plugin - Chart Rendering Module
 * Handles Chart.js visualization of drive data
 */

// Chart instances (global for cleanup)
let riskChart = null;
let temperatureChart = null;
let sizeChart = null;

// Neutral color for drives with no SMART data
const UNKNOWN_COLOR = '#9E9E9E';

// Temperature bin thresholds (°C) - single source for binning AND tooltips.
// Kept in sync with getTemperatureClass() on the server.
const TEMP_THRESHOLDS = {
    hdd:  { elevated: 40, high: 50, critical: 60 },
    nvme: { elevated: 50, high: 65, critical: 75 }
};

/**
 * Main entry point - renders all charts
 *
 * @param {Object} driveData - Full data object from API
 */
function renderCharts(driveData) {
    if (!driveData || !driveData.drives || driveData.drives.length === 0) {
        hideCharts();
        return;
    }

    const container = document.getElementById('charts-container');
    if (container) {
        container.style.display = 'block';
    }

    // Follow the active Unraid theme: chart text inherits the page color
    if (typeof Chart !== 'undefined' && Chart.defaults) {
        Chart.defaults.color = getComputedStyle(document.body).color;
    }

    destroyCharts();

    renderRiskChart(driveData);
    renderTemperatureChart(driveData);
    renderSizeChart(driveData);
}

/**
 * Category label - always from the server (single source of truth).
 *
 * @param {string} category - Risk category key
 * @param {Object} driveData - Full data object from API
 * @return {string} Human-readable label
 */
function getCategoryLabel(category, driveData) {
    if (driveData && driveData.labels && driveData.labels[category]) {
        return driveData.labels[category];
    }
    return category === 'unknown' ? 'N/A (no SMART data)' : category;
}

/**
 * Aggregate drives by risk category
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Category counts
 */
function aggregateRiskData(drives) {
    const categories = {
        'minimal_risk': 0,
        'low_risk': 0,
        'moderate_risk': 0,
        'elevated_risk': 0,
        'high_risk': 0,
        'unknown': 0
    };

    drives.forEach(drive => {
        const category = drive.risk_category;
        if (Object.prototype.hasOwnProperty.call(categories, category)) {
            categories[category]++;
        } else {
            categories.unknown++;
        }
    });

    return categories;
}

/**
 * Render risk category distribution chart (donut)
 *
 * @param {Object} driveData - Full data object from API
 */
function renderRiskChart(driveData) {
    const ctx = document.getElementById('risk-chart');
    if (!ctx) return;

    const riskData = aggregateRiskData(driveData.drives);

    const keys = ['minimal_risk', 'low_risk', 'moderate_risk', 'elevated_risk', 'high_risk'];
    const labels = keys.map(k => getCategoryLabel(k, driveData));
    const data = keys.map(k => riskData[k]);
    const colors = keys.map(k => driveData.colors[k]);

    // Only show the Unknown slice when it exists
    if (riskData.unknown > 0) {
        labels.push(getCategoryLabel('unknown', driveData));
        data.push(riskData.unknown);
        colors.push(UNKNOWN_COLOR);
    }

    riskChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        boxWidth: 15,
                        padding: 10,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed || 0;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                            return label + ': ' + value + ' drives (' + percentage + '%)';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Bin a temperature into a severity bucket for a drive class.
 *
 * @param {number|null} temp - Temperature in °C
 * @param {string} kind - 'hdd' or 'nvme'
 * @return {string} Bucket name
 */
function binTemperature(temp, kind) {
    if (temp === null || temp === undefined || temp === '') {
        return 'unknown';
    }
    const t = TEMP_THRESHOLDS[kind];
    if (temp >= t.critical) return 'critical';
    if (temp >= t.high) return 'high';
    if (temp >= t.elevated) return 'elevated';
    return 'normal';
}

/**
 * Human-readable range text for a temperature bucket.
 *
 * @param {string} bucket - Bucket name
 * @param {string} kind - 'hdd' or 'nvme'
 * @param {string} unit - 'C' or 'F'
 * @return {string} Range text like "(40-49°C)"
 */
function tempRangeText(bucket, kind, unit) {
    const t = TEMP_THRESHOLDS[kind];
    const conv = c => unit === 'F' ? Math.round(c * 9 / 5) + 32 : c;
    const u = '°' + unit;

    switch (bucket) {
        case 'Normal':   return `(<${conv(t.elevated)}${u})`;
        case 'Elevated': return `(${conv(t.elevated)}-${conv(t.high) - 1}${u})`;
        case 'High':     return `(${conv(t.high)}-${conv(t.critical) - 1}${u})`;
        case 'Critical': return `(≥${conv(t.critical)}${u})`;
        default:         return '';
    }
}

/**
 * Aggregate drives by temperature bucket, separated by drive type
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Bucket counts for HDD and NVMe
 */
function aggregateTemperatureData(drives) {
    const empty = () => ({ normal: 0, elevated: 0, high: 0, critical: 0, unknown: 0 });
    const bins = { hdd: empty(), nvme: empty() };

    drives.forEach(drive => {
        const kind = drive.physical_type === 'nvme' ? 'nvme' : 'hdd';
        bins[kind][binTemperature(drive.temperature, kind)]++;
    });

    return bins;
}

/**
 * Render temperature distribution chart (grouped bar)
 *
 * @param {Object} driveData - Full data object from API
 */
function renderTemperatureChart(driveData) {
    const ctx = document.getElementById('temperature-chart');
    if (!ctx) return;

    const tempData = aggregateTemperatureData(driveData.drives);
    const tempUnit = driveData.config && driveData.config.temperature_unit ? driveData.config.temperature_unit : 'C';

    const labels = ['Normal', 'Elevated', 'High', 'Critical', 'Unknown'];
    const order = ['normal', 'elevated', 'high', 'critical', 'unknown'];

    temperatureChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'HDD',
                data: order.map(k => tempData.hdd[k]),
                backgroundColor: '#2196F3',
                borderWidth: 1,
                borderColor: '#fff'
            }, {
                label: 'NVMe',
                data: order.map(k => tempData.nvme[k]),
                backgroundColor: '#FF9800',
                borderWidth: 1,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: { size: 11 }
                    }
                },
                x: {
                    ticks: {
                        font: { size: 11 }
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        boxWidth: 15,
                        padding: 10,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        title: function(context) {
                            return context[0].label;
                        },
                        label: function(context) {
                            const kind = context.datasetIndex === 0 ? 'hdd' : 'nvme';
                            const range = tempRangeText(context.label, kind, tempUnit);
                            return context.dataset.label + ' ' + range + ': ' + context.parsed.y + ' drives';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Aggregate drives by actual unique sizes, separated by drive type
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Size labels and counts for HDD, NVMe, and USB
 */
function aggregateSizeData(drives) {
    const sizeMap = new Map();

    drives.forEach(drive => {
        const sizeHuman = drive.size_human || 'Unknown';
        const sizeBytes = drive.size_bytes || 0;
        const driveType = drive.physical_type || 'hdd';

        if (!sizeMap.has(sizeHuman)) {
            sizeMap.set(sizeHuman, {
                bytes: sizeBytes,
                hdd: 0,
                nvme: 0,
                usb: 0
            });
        }

        const sizeEntry = sizeMap.get(sizeHuman);
        if (driveType === 'nvme') {
            sizeEntry.nvme++;
        } else if (driveType === 'usb') {
            sizeEntry.usb++;
        } else {
            sizeEntry.hdd++;
        }
    });

    const sortedSizes = Array.from(sizeMap.entries())
        .sort((a, b) => a[1].bytes - b[1].bytes);

    return {
        labels: sortedSizes.map(entry => entry[0]),
        hdd: sortedSizes.map(entry => entry[1].hdd),
        nvme: sortedSizes.map(entry => entry[1].nvme),
        usb: sortedSizes.map(entry => entry[1].usb)
    };
}

/**
 * Render drive size distribution chart (grouped bar)
 *
 * @param {Object} driveData - Full data object from API
 */
function renderSizeChart(driveData) {
    const ctx = document.getElementById('size-chart');
    if (!ctx) return;

    const sizeData = aggregateSizeData(driveData.drives);

    sizeChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: sizeData.labels,
            datasets: [{
                label: 'HDD',
                data: sizeData.hdd,
                backgroundColor: '#2196F3',
                borderWidth: 1,
                borderColor: '#fff'
            }, {
                label: 'NVMe',
                data: sizeData.nvme,
                backgroundColor: '#FF9800',
                borderWidth: 1,
                borderColor: '#fff'
            }, {
                label: 'USB',
                data: sizeData.usb,
                backgroundColor: '#4CAF50',
                borderWidth: 1,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        stepSize: 1,
                        font: { size: 11 }
                    }
                },
                x: {
                    ticks: {
                        font: { size: 11 }
                    }
                }
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    labels: {
                        boxWidth: 15,
                        padding: 10,
                        font: { size: 11 }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return (context.dataset.label || '') + ': ' + context.parsed.y + ' drives';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Destroy all chart instances for cleanup
 */
function destroyCharts() {
    if (riskChart) {
        riskChart.destroy();
        riskChart = null;
    }

    if (temperatureChart) {
        temperatureChart.destroy();
        temperatureChart = null;
    }

    if (sizeChart) {
        sizeChart.destroy();
        sizeChart = null;
    }
}

/**
 * Hide charts container when no data
 */
function hideCharts() {
    const container = document.getElementById('charts-container');
    if (container) {
        container.style.display = 'none';
    }
}

/**
 * Expose destroyCharts to global scope for dashboard.js
 */
window.destroyCharts = destroyCharts;
