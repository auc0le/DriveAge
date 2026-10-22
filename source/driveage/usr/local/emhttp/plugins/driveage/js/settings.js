/**
 * DriveAge Settings Page JavaScript
 *
 * Note: live year->hour threshold syncing and color previews are handled by
 * the inline script in DriveAgeSettings.page - this file only provides the
 * reset-to-defaults action (defaults are injected as DRIVEAGE_DEFAULTS).
 *
 * Uses Unraid's SweetAlert dialogs when present (native look), with the
 * browser's confirm()/alert() as a fallback.
 */

function driveageConfirm(title, text, onConfirm) {
    if (typeof swal === 'function') {
        swal({ title: title, text: text, type: 'warning', showCancelButton: true, confirmButtonText: 'Reset', cancelButtonText: 'Cancel' },
            function (confirmed) { if (confirmed !== false) onConfirm(); });
    } else if (confirm(title + '\n\n' + text)) {
        onConfirm();
    }
}

function driveageNotify(title, text) {
    if (typeof swal === 'function') {
        swal({ title: title, text: text, type: 'info' });
    } else {
        alert(title + '\n\n' + text);
    }
}

function resetToDefaults() {
    const form = document.getElementById('driveage-settings-form');
    if (!form) {
        return;
    }
    if (typeof DRIVEAGE_DEFAULTS === 'undefined') {
        driveageNotify('Defaults not loaded', 'Please refresh the page and try again.');
        return;
    }

    driveageConfirm('Reset all settings?', 'Every DriveAge setting returns to its default. Nothing is saved until you click Apply Settings.', function () {
        let resetCount = 0;

        Object.keys(DRIVEAGE_DEFAULTS).forEach(function (key) {
            const value = DRIVEAGE_DEFAULTS[key];

            // Threshold fields - convert hours to years for the visible input
            if (key.startsWith('THRESHOLD_')) {
                const yearsInput = form.querySelector('[data-field="' + key + '"]');
                const hiddenInput = form.querySelector('[name="' + key + '"]');
                if (yearsInput) {
                    yearsInput.value = (parseFloat(value) / 8760).toFixed(1);
                    resetCount++;
                }
                if (hiddenInput) {
                    hiddenInput.value = value;
                }
                return;
            }

            // Checkboxes come as a hidden "false" + checkbox "true" pair
            const field = form.querySelector('input[type="checkbox"][name="' + key + '"]') ||
                          form.querySelector('[name="' + key + '"]');
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
        form.querySelectorAll('.color-input').forEach(function (input) {
            input.dispatchEvent(new Event('input', { bubbles: true }));
        });

        driveageNotify('Defaults restored', resetCount + ' fields reset. Click "Apply Settings" to save.');
    });
}
