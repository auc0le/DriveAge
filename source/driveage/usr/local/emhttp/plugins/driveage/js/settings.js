/**
 * DriveAge Settings Page JavaScript
 */

function resetToDefaults() {
    if (confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
        // Set all values to defaults (in years)
        document.querySelector('[data-field="THRESHOLD_BRAND_NEW"]').value = '2.0';
        document.querySelector('[data-field="THRESHOLD_NEWISH"]').value = '3.0';
        document.querySelector('[data-field="THRESHOLD_NORMAL"]').value = '4.0';
        document.querySelector('[data-field="THRESHOLD_AGED"]').value = '5.0';
        document.querySelector('[data-field="THRESHOLD_OLD"]').value = '6.0';

        document.querySelector('[name="DEFAULT_SORT"]').value = 'power_on_hours';
        document.querySelector('[name="DEFAULT_SORT_DIR"]').value = 'desc';

        document.querySelector('[name="AUTO_REFRESH"]').checked = false;
        document.querySelector('[name="REFRESH_INTERVAL"]').value = '300';

        document.querySelectorAll('input[type="checkbox"][name^="SHOW_"]').forEach(cb => {
            cb.checked = true;
        });

        document.querySelector('[name="API_ENABLED"]').checked = false;
        document.querySelector('[name="API_RATE_LIMIT"]').value = '100';

        alert('Settings reset to defaults. Click "Apply Settings" to save.');
    }
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
