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

    // Reset all fields programmatically from injected defaults
    Object.keys(DRIVEAGE_DEFAULTS).forEach(function(key) {
        const value = DRIVEAGE_DEFAULTS[key];

        // Handle threshold fields - convert hours to years for display
        if (key.startsWith('THRESHOLD_')) {
            const yearsInput = document.querySelector('[data-field="' + key + '"]');
            if (yearsInput) {
                const years = parseFloat(value) / 8760;
                yearsInput.value = years.toFixed(1);
            }
            return;
        }

        // Find the form field by name attribute
        const field = document.querySelector('[name="' + key + '"]');

        if (!field) {
            return; // Skip if field doesn't exist
        }

        // Handle different input types
        if (field.type === 'checkbox') {
            // Convert string 'true'/'false' to boolean
            field.checked = (value === 'true' || value === true);
        } else if (field.type === 'color') {
            // Color inputs need uppercase hex format
            field.value = value.toUpperCase();
        } else if (field.tagName === 'SELECT') {
            // Dropdown/select fields
            field.value = value;
        } else {
            // Text, number, and other input types
            field.value = value;
        }
    });

    alert('Settings reset to defaults. Click "Apply Settings" to save.');
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
