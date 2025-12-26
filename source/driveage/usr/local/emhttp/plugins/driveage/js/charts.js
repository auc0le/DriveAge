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
    // Note: No HTML escaping needed - Chart.js renders to canvas, not HTML
    const labels = [
        getAgeLabel('minimal_risk', driveData),
        getAgeLabel('low_risk', driveData),
        getAgeLabel('moderate_risk', driveData),
        getAgeLabel('elevated_risk', driveData),
        getAgeLabel('high_risk', driveData)
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
 * Aggregate drives by temperature range, separated by drive type (HDD vs NVMe)
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Temperature bin counts for HDD and NVMe
 */
function aggregateTemperatureData(drives) {
    const hddBins = {
        'normal': 0,      // < 40°C
        'elevated': 0,    // 40-49°C
        'high': 0,        // 50-59°C
        'critical': 0,    // ≥ 60°C
        'unknown': 0      // null/unavailable
    };

    const nvmeBins = {
        'normal': 0,      // < 50°C
        'elevated': 0,    // 50-64°C
        'high': 0,        // 65-74°C
        'critical': 0,    // ≥ 75°C
        'unknown': 0      // null/unavailable
    };

    drives.forEach(drive => {
        const temp = drive.temperature;
        const isNvme = drive.physical_type === 'nvme';

        if (temp === null || temp === undefined || temp === '') {
            if (isNvme) {
                nvmeBins.unknown++;
            } else {
                hddBins.unknown++;
            }
        } else if (isNvme) {
            // NVMe thresholds: < 50°C, 50-64°C, 65-74°C, ≥ 75°C
            if (temp < 50) {
                nvmeBins.normal++;
            } else if (temp < 65) {
                nvmeBins.elevated++;
            } else if (temp < 75) {
                nvmeBins.high++;
            } else {
                nvmeBins.critical++;
            }
        } else {
            // HDD thresholds: < 40°C, 40-49°C, 50-59°C, ≥ 60°C
            if (temp < 40) {
                hddBins.normal++;
            } else if (temp < 50) {
                hddBins.elevated++;
            } else if (temp < 60) {
                hddBins.high++;
            } else {
                hddBins.critical++;
            }
        }
    });

    return {
        hdd: hddBins,
        nvme: nvmeBins
    };
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

    // Get temperature unit from config (default to Celsius)
    const tempUnit = driveData.config && driveData.config.temperature_unit ? driveData.config.temperature_unit : 'C';

    // Labels represent temperature severity levels
    const labels = ['Normal', 'Elevated', 'High', 'Critical', 'Unknown'];

    // HDD data (binned at different thresholds than NVMe)
    const hddData = [
        tempData.hdd.normal,
        tempData.hdd.elevated,
        tempData.hdd.high,
        tempData.hdd.critical,
        tempData.hdd.unknown
    ];

    // NVMe data (binned at different thresholds than HDD)
    const nvmeData = [
        tempData.nvme.normal,
        tempData.nvme.elevated,
        tempData.nvme.high,
        tempData.nvme.critical,
        tempData.nvme.unknown
    ];

    temperatureChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'HDD',
                data: hddData,
                backgroundColor: '#2196F3',  // Blue
                borderWidth: 1,
                borderColor: '#fff'
            }, {
                label: 'NVMe',
                data: nvmeData,
                backgroundColor: '#FF9800',  // Orange
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
                            const label = context.dataset.label || '';
                            const value = context.parsed.y;

                            // Show temperature ranges in tooltip
                            let range = '';
                            const category = context.label;

                            if (context.datasetIndex === 0) {
                                // HDD ranges
                                if (category === 'Normal') range = tempUnit === 'F' ? '(<104°F)' : '(<40°C)';
                                else if (category === 'Elevated') range = tempUnit === 'F' ? '(104-120°F)' : '(40-49°C)';
                                else if (category === 'High') range = tempUnit === 'F' ? '(122-138°F)' : '(50-59°C)';
                                else if (category === 'Critical') range = tempUnit === 'F' ? '(≥140°F)' : '(≥60°C)';
                            } else {
                                // NVMe ranges
                                if (category === 'Normal') range = tempUnit === 'F' ? '(<122°F)' : '(<50°C)';
                                else if (category === 'Elevated') range = tempUnit === 'F' ? '(122-149°F)' : '(50-64°C)';
                                else if (category === 'High') range = tempUnit === 'F' ? '(149-167°F)' : '(65-74°C)';
                                else if (category === 'Critical') range = tempUnit === 'F' ? '(≥167°F)' : '(≥75°C)';
                            }

                            return label + ' ' + range + ': ' + value + ' drives';
                        }
                    }
                }
            }
        }
    });
}

/**
 * Aggregate drives by actual unique sizes, separated by drive type (HDD vs NVMe vs USB)
 *
 * @param {Array} drives - Array of drive objects
 * @return {Object} Size labels and counts for HDD, NVMe, and USB
 */
function aggregateSizeData(drives) {
    // Group drives by size_human, tracking each drive type separately
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

    // Convert to array and sort by size (bytes)
    const sortedSizes = Array.from(sizeMap.entries())
        .sort((a, b) => a[1].bytes - b[1].bytes);

    // Extract labels and counts for each drive type
    const labels = sortedSizes.map(entry => entry[0]);
    const hddCounts = sortedSizes.map(entry => entry[1].hdd);
    const nvmeCounts = sortedSizes.map(entry => entry[1].nvme);
    const usbCounts = sortedSizes.map(entry => entry[1].usb);

    return {
        labels: labels,
        hdd: hddCounts,
        nvme: nvmeCounts,
        usb: usbCounts
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

    // Use actual drive sizes from the data
    const labels = sizeData.labels;

    // HDD data
    const hddData = sizeData.hdd;

    // NVMe data
    const nvmeData = sizeData.nvme;

    // USB data
    const usbData = sizeData.usb;

    sizeChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'HDD',
                data: hddData,
                backgroundColor: '#2196F3',  // Blue
                borderWidth: 1,
                borderColor: '#fff'
            }, {
                label: 'NVMe',
                data: nvmeData,
                backgroundColor: '#FF9800',  // Orange
                borderWidth: 1,
                borderColor: '#fff'
            }, {
                label: 'USB',
                data: usbData,
                backgroundColor: '#4CAF50',  // Green
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
                            const label = context.dataset.label || '';
                            const value = context.parsed.y;
                            return label + ': ' + value + ' drives';
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
