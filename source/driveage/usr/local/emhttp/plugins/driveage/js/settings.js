/**
 * DriveAge Settings Page JavaScript
 */

function resetToDefaults() {
    if (!confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
        return;
    }

    // Verify defaults are available
    if (typeof DRIVEAGE_DEFAULTS === 'undefined') {
        alert('Error: Default configuration not loaded. Please refresh the page.');
        return;
    }

    console.log('DriveAge: Resetting to defaults...');
    let resetCount = 0;

    // Reset all fields programmatically from injected defaults
    Object.keys(DRIVEAGE_DEFAULTS).forEach(function(key) {
        const value = DRIVEAGE_DEFAULTS[key];

        // Handle threshold fields - convert hours to years for display
        if (key.startsWith('THRESHOLD_')) {
            const yearsInput = document.querySelector('[data-field="' + key + '"]');
            const hiddenInput = document.querySelector('[name="' + key + '"]');
            if (yearsInput) {
                const years = parseFloat(value) / 8760;
                yearsInput.value = years.toFixed(1);
                resetCount++;
                console.log('Reset threshold: ' + key + ' = ' + years.toFixed(1) + ' years');
            }
            // Also update hidden field directly
            if (hiddenInput) {
                hiddenInput.value = value;
            }
            return;
        }

        // Find the form field by name attribute
        const field = document.querySelector('[name="' + key + '"]');

        if (!field) {
            console.log('Field not found: ' + key);
            return; // Skip if field doesn't exist
        }

        // Handle different input types
        if (field.type === 'checkbox') {
            // Convert string 'true'/'false' to boolean
            field.checked = (value === 'true' || value === true);
            resetCount++;
            console.log('Reset checkbox: ' + key + ' = ' + field.checked);
        } else if (field.type === 'color') {
            // Color inputs accept hex format (case insensitive)
            field.value = value.toLowerCase();
            resetCount++;
            console.log('Reset color: ' + key + ' = ' + value);
        } else if (field.tagName === 'SELECT') {
            // Dropdown/select fields
            field.value = value;
            resetCount++;
            console.log('Reset select: ' + key + ' = ' + value);
        } else {
            // Text, number, and other input types
            field.value = value;
            resetCount++;
            console.log('Reset ' + field.type + ': ' + key + ' = ' + value);
        }
    });

    console.log('DriveAge: Reset ' + resetCount + ' fields');
    alert('Settings reset to defaults (' + resetCount + ' fields updated). Click "Apply Settings" to save.');
}

// Convert years to hours before form submission
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('form');

    if (form) {
        form.addEventListener('submit', function(e) {
            // Convert all threshold inputs from years to hours
            document.querySelectorAll('.threshold-years').forEach(input => {
                const years = parseFloat(input.value) || 0;
                const hours = Math.round(years * 8760);
                const fieldName = input.getAttribute('data-field');
                const hiddenField = document.querySelector('input[name="' + fieldName + '"]');
                if (hiddenField) {
                    hiddenField.value = hours;
                }
            });
        });
    }
});
