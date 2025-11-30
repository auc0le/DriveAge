/**
 * DriveAge Settings Page JavaScript
 */

function resetToDefaults() {
    if (confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
        // Set all values to defaults
        document.querySelector('[name="THRESHOLD_BRAND_NEW"]').value = '17520';
        document.querySelector('[name="THRESHOLD_NEWISH"]').value = '26280';
        document.querySelector('[name="THRESHOLD_NORMAL"]').value = '35040';
        document.querySelector('[name="THRESHOLD_AGED"]').value = '43800';
        document.querySelector('[name="THRESHOLD_OLD"]').value = '52560';

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

// Update year display when threshold values change
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.threshold-item input[type="number"]').forEach(input => {
        input.addEventListener('input', function() {
            const helpText = this.nextElementSibling;
            const years = (parseFloat(this.value) / 8766).toFixed(2);
            helpText.textContent = years + ' years';
        });
    });
});
