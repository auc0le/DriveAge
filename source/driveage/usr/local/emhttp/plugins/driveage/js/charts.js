/**
 * DriveAge Plugin - Charts
 *
 * Two small dependency-free charts (inline SVG + CSS) replacing the 200 KB
 * Chart.js bundle. Colors come from the same CSS custom properties as the
 * risk badges, so user-configured category colors and every Unraid theme
 * apply without any JavaScript color handling.
 */
window.DriveAge = window.DriveAge || {};

(function (DA) {
    'use strict';

    const CATEGORIES = ['minimal_risk', 'low_risk', 'moderate_risk', 'elevated_risk', 'high_risk', 'unknown'];

    // Replacement timeline buckets by months_remaining
    const BUCKETS = [
        { key: 'now', label: 'Now', test: m => m === 0 },
        { key: 'y1', label: '≤ 1 year', test: m => m > 0 && m <= 12 },
        { key: 'y2', label: '1–2 years', test: m => m > 12 && m <= 24 },
        { key: 'y3', label: '2–3 years', test: m => m > 24 && m <= 36 },
        { key: 'y5', label: '3–5 years', test: m => m > 36 && m <= 60 },
        { key: 'later', label: '5+ years', test: m => m > 60 },
        { key: 'unknown', label: 'Unknown', test: m => m === null }
    ];

    function esc(value) {
        return DA.escapeHtml(value);
    }

    function categoryLabel(category, data) {
        return (data.labels && data.labels[category]) || category;
    }

    /**
     * Donut of drives per overall risk category, with a counted legend.
     */
    function renderRisk(el, data) {
        if (!el) return;
        const counts = {};
        CATEGORIES.forEach(c => { counts[c] = 0; });
        data.drives.forEach(d => {
            counts[Object.prototype.hasOwnProperty.call(counts, d.risk_category) ? d.risk_category : 'unknown']++;
        });
        const total = data.drives.length;
        if (!total) {
            el.innerHTML = '<p class="muted">No drives.</p>';
            return;
        }

        // Circle with circumference 100: dash lengths are percentages.
        // Offset 25 starts the first segment at 12 o'clock.
        const r = 15.91549430918954;
        let done = 0;
        let segments = '';
        CATEGORIES.forEach(c => {
            if (!counts[c]) return;
            const pct = counts[c] / total * 100;
            segments += `<circle class="donut-seg seg-${c}" cx="21" cy="21" r="${r}" ` +
                `stroke-dasharray="${pct.toFixed(3)} ${(100 - pct).toFixed(3)}" stroke-dashoffset="${(125 - done).toFixed(3)}">` +
                `<title>${esc(categoryLabel(c, data))}: ${counts[c]}</title></circle>`;
            done += pct;
        });

        const legend = CATEGORIES.filter(c => counts[c]).map(c =>
            `<li><span class="legend-swatch age-${c}"></span>` +
            `<span class="legend-text">${esc(categoryLabel(c, data))}</span>` +
            `<span class="legend-count">${counts[c]}</span></li>`
        ).join('');

        const summary = CATEGORIES.filter(c => counts[c]).map(c => categoryLabel(c, data) + ': ' + counts[c]).join(', ');
        el.innerHTML =
            `<div class="donut-wrap"><svg class="donut" viewBox="0 0 42 42" role="img" aria-label="${esc('Risk distribution. ' + summary)}">` +
            `<circle class="donut-track" cx="21" cy="21" r="${r}"></circle>${segments}` +
            `<text x="21" y="19.5" class="donut-total">${total}</text>` +
            `<text x="21" y="27.5" class="donut-caption">drives</text></svg>` +
            `<ul class="donut-legend">${legend}</ul></div>`;
    }

    /**
     * Horizontal bars of drives due per time bucket, with the capacity
     * involved and the drive names - the replacement budgeting view.
     */
    function renderForecast(el, data) {
        if (!el) return;
        const rows = BUCKETS.map(b => ({ bucket: b, drives: [], bytes: 0 }));

        data.drives.forEach(d => {
            const p = d.replacement_prediction || {};
            const m = (p.months_remaining === null || p.months_remaining === undefined) ? null : p.months_remaining;
            const row = rows.find(r => r.bucket.test(m));
            if (row) {
                row.drives.push(d);
                row.bytes += d.size_bytes || 0;
            }
        });

        const max = Math.max(1, ...rows.map(r => r.drives.length));
        el.innerHTML = '<div class="forecast">' + rows.map(r => {
            const n = r.drives.length;
            const width = (n / max * 100).toFixed(1);
            const names = r.drives
                .sort((a, b) => (a.replacement_prediction.months_remaining ?? 1e9) - (b.replacement_prediction.months_remaining ?? 1e9))
                .map(d => d.device_name);
            const shown = names.slice(0, 6).join(', ') + (names.length > 6 ? ' +' + (names.length - 6) + ' more' : '');
            const tb = r.bytes / 1e12;
            const capacity = n ? (tb >= 10 ? tb.toFixed(0) : tb.toFixed(1)) + ' TB' : '';
            return `<div class="fc-row fc-${r.bucket.key}${n ? '' : ' fc-empty'}">` +
                `<span class="fc-label">${esc(r.bucket.label)}</span>` +
                `<span class="fc-track"><span class="fc-bar" style="width:${width}%"></span></span>` +
                `<span class="fc-count">${n}${capacity ? ' <small>· ' + esc(capacity) + '</small>' : ''}</span>` +
                `<span class="fc-names" title="${esc(names.join(', '))}">${esc(shown)}</span></div>`;
        }).join('') + '</div>';
    }

    DA.charts = { risk: renderRisk, forecast: renderForecast };
})(window.DriveAge);
