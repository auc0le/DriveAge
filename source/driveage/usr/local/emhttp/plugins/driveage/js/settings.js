/**
 * DriveAge Settings Page JavaScript
 *
 * Note: live year->hour threshold syncing and color previews are handled by
 * the inline script in DriveAgeSettings.page - this file only provides the
 * reset-to-defaults action (defaults are injected as DRIVEAGE_DEFAULTS).
 */

function resetToDefaults() {
    if (!confirm('Are you sure you want to reset all settings to defaults? This cannot be undone.')) {
        return;
    }

    if (typeof DRIVEAGE_DEFAULTS === 'undefined') {
        alert('Error: Default configuration not loaded. Please refresh the page.');
        return;
    }

    let resetCount = 0;

    Object.keys(DRIVEAGE_DEFAULTS).forEach(function(key) {
        const value = DRIVEAGE_DEFAULTS[key];

        // Threshold fields - convert hours to years for the visible input
        if (key.startsWith('THRESHOLD_')) {
            const yearsInput = document.querySelector('[data-field="' + key + '"]');
            const hiddenInput = document.querySelector('[name="' + key + '"]');
            if (yearsInput) {
                yearsInput.value = (parseFloat(value) / 8760).toFixed(1);
                resetCount++;
            }
            if (hiddenInput) {
                hiddenInput.value = value;
            }
            return;
        }

        const field = document.querySelector('[name="' + key + '"]');
        if (!field) {
            return;
        }

        if (field.type === 'checkbox') {
            field.checked = (value === 'true' || value === true);
        } else if (field.type === 'color') {
            field.value = String(value).toLowerCase();
        } else {
            field.value = value;
        }
        resetCount++;
    });

    // Refresh the color previews to match the reset values
    document.querySelectorAll('.color-input').forEach(input => {
        input.dispatchEvent(new Event('input', { bubbles: true }));
    });

    alert('Settings reset to defaults (' + resetCount + ' fields updated). Click "Apply Settings" to save.');
}
