/**
 * DriveAge Plugin - Chart Rendering Module
 * Handles Chart.js visualization of drive data
 */

// Chart instances (global for cleanup)
let riskChart = null;
let temperatureChart = null;
let sizeChart = null;

/**
 * Main entry point - renders all charts
 * Called from dashboard.js after data loads
 *
 * @param {Object} driveData - Full data object from API
 */
function renderCharts(driveData) {
    if (!driveData || !driveData.drives || driveData.drives.length === 0) {
        hideCharts();
        return;
    }

    // Show charts container
    const container = document.getElementById('charts-container');
    if (container) {
        container.style.display = 'block';
    }

    // Destroy existing charts before re-rendering
    destroyCharts();

    // Render each chart
    renderRiskChart(driveData);
    renderTemperatureChart(driveData);
    renderSizeChart(driveData);
}

/**
 * Get age label for risk category
 *
 * @param {string} category - Risk category
 * @param {Object} driveData - Full data object from API
 * @return {string} Human-readable label
 */
function getAgeLabel(category, driveData) {
    // Try to get label from first drive with this category
    if (driveData && driveData.drives) {
        const drive = driveData.drives.find(d => d.age_category === category);
        if (drive && drive.age_label) {
            return drive.age_label;
        }
    }

    // Fallback labels
    const labels = {
        'minimal_risk': 'Minimal Risk (AFR <1%)',
        'low_risk': 'Low Risk (AFR 1-2%)',
        'moderate_risk': 'Moderate Risk (AFR 2-5%)',
        'elevated_risk': 'Elevated Risk (AFR 5-10%)',
        'high_risk': 'High Risk (AFR >10%)'
    };

    return labels[category] || 'Unknown';
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
        'high_risk': 0
    };

    drives.forEach(drive => {
        const category = drive.age_category;
        if (categories.hasOwnProperty(category)) {
            categories[category]++;
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
    const labels = [
        escapeHtml(getAgeLabel('minimal_risk', driveData)),
        escapeHtml(getAgeLabel('low_risk', driveData)),
        escapeHtml(getAgeLabel('moderate_risk', driveData)),
        escapeHtml(getAgeLabel('elevated_risk', driveData)),
        escapeHtml(getAgeLabel('high_risk', driveData))
    ];

    const data = [
        riskData.minimal_risk,
        riskData.low_risk,
        riskData.moderate_risk,
        riskData.elevated_risk,
        riskData.high_risk
    ];

    const colors = [
        driveData.colors.minimal_risk,
        driveData.colors.low_risk,
        driveData.colors.moderate_risk,
        driveData.colors.elevated_risk,
        driveData.colors.high_risk
    ];

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
 * Aggregate drives by temperature range
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Temperature bin counts
 */
function aggregateTemperatureData(drives) {
    const bins = {
        'under_40': 0,    // < 40°C
        '40_to_49': 0,    // 40-49°C
        '50_to_59': 0,    // 50-59°C
        '60_plus': 0,     // ≥ 60°C
        'unknown': 0      // null/unavailable
    };

    drives.forEach(drive => {
        const temp = drive.temperature;

        if (temp === null || temp === undefined || temp === '') {
            bins.unknown++;
        } else if (temp < 40) {
            bins.under_40++;
        } else if (temp < 50) {
            bins['40_to_49']++;
        } else if (temp < 60) {
            bins['50_to_59']++;
        } else {
            bins['60_plus']++;
        }
    });

    return bins;
}

/**
 * Render temperature distribution chart (bar)
 *
 * @param {Object} driveData - Full data object from API
 */
function renderTemperatureChart(driveData) {
    const ctx = document.getElementById('temperature-chart');
    if (!ctx) return;

    const tempData = aggregateTemperatureData(driveData.drives);

    const labels = ['< 40°C', '40-49°C', '50-59°C', '≥ 60°C', 'Unknown'];
    const data = [
        tempData.under_40,
        tempData['40_to_49'],
        tempData['50_to_59'],
        tempData['60_plus'],
        tempData.unknown
    ];

    // Color gradient: cool to hot
    const colors = [
        '#4CAF50',  // Green - cool
        '#8BC34A',  // Light green
        '#FFC107',  // Amber
        '#F44336',  // Red - hot
        '#9E9E9E'   // Grey - unknown
    ];

    temperatureChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Drive Count',
                data: data,
                backgroundColor: colors,
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
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' drives';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Aggregate drives by size range
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Size bin counts
 */
function aggregateSizeData(drives) {
    const bins = {
        'under_1tb': 0,      // < 1TB
        '1_to_4tb': 0,       // 1-4TB
        '4_to_8tb': 0,       // 4-8TB
        '8_to_12tb': 0,      // 8-12TB
        '12_to_16tb': 0,     // 12-16TB
        '16_plus_tb': 0      // ≥ 16TB
    };

    const TB = 1024 * 1024 * 1024 * 1024; // 1TB in bytes

    drives.forEach(drive => {
        const sizeBytes = drive.size_bytes || 0;
        const sizeTB = sizeBytes / TB;

        if (sizeTB < 1) {
            bins.under_1tb++;
        } else if (sizeTB < 4) {
            bins['1_to_4tb']++;
        } else if (sizeTB < 8) {
            bins['4_to_8tb']++;
        } else if (sizeTB < 12) {
            bins['8_to_12tb']++;
        } else if (sizeTB < 16) {
            bins['12_to_16tb']++;
        } else {
            bins['16_plus_tb']++;
        }
    });

    return bins;
}

/**
 * Render drive size distribution chart (bar)
 *
 * @param {Object} driveData - Full data object from API
 */
function renderSizeChart(driveData) {
    const ctx = document.getElementById('size-chart');
    if (!ctx) return;

    const sizeData = aggregateSizeData(driveData.drives);

    const labels = ['< 1TB', '1-4TB', '4-8TB', '8-12TB', '12-16TB', '≥ 16TB'];
    const data = [
        sizeData.under_1tb,
        sizeData['1_to_4tb'],
        sizeData['4_to_8tb'],
        sizeData['8_to_12tb'],
        sizeData['12_to_16tb'],
        sizeData['16_plus_tb']
    ];

    // Consistent color scheme
    const color = '#2196F3';  // Blue

    sizeChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Drive Count',
                data: data,
                backgroundColor: color,
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
                    display: false
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return context.parsed.y + ' drives';
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
