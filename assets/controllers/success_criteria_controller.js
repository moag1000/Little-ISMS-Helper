import { Controller } from '@hotwired/stimulus';

/**
 * success-criteria — P-9 JsonBuilder for BCExercise.successCriteria
 * (ISO 22301 §8.6c). Auto-detects Shape A (array of objects) vs Shape B
 * (legacy flat key:bool object) on load. Always writes Shape A back.
 *
 * Shape A:
 *   [{criterion: "...", target: "...", actual: "...", met: "met|not_met|unknown"}, ...]
 *
 * Shape B (legacy, coerced on load):
 *   {rtoMet: true, communicationEffective: false, ...}
 *   → [{criterion: 'Rto Met', target: '', actual: '', met: 'met'}, ...]
 */
export default class extends Controller {
    static targets = ['hidden', 'body', 'template', 'emptyState', 'prefillBanner', 'prefillText', 'prefillBtn', 'rawPanel', 'rawTextarea'];
    static values = {
        name: String,
        prefill: { type: Array, default: [] },
    };

    static MET_WHITELIST = ['unknown', 'met', 'not_met'];

    connect() {
        this.rows = this.parseRows(this.hiddenTarget.value || '');
        if (this.prefillValue.length > 0 && this.hasPrefillBtnTarget) {
            this.prefillBtnTarget.classList.remove('d-none');
        }
        this.render();
    }

    parseRows(raw) {
        const trimmed = (raw || '').trim();
        if (trimmed === '') return [];
        let decoded;
        try {
            decoded = JSON.parse(trimmed);
        } catch (_) {
            return [];
        }
        if (Array.isArray(decoded)) {
            return decoded.map(this.normaliseRow);
        }
        if (decoded && typeof decoded === 'object') {
            // Shape B → Shape A. Each key:bool becomes a criterion row.
            return Object.entries(decoded).map(([key, val]) => ({
                criterion: this.humanise(key),
                target: '',
                actual: '',
                met: val === true ? 'met' : (val === false ? 'not_met' : 'unknown'),
            }));
        }
        return [];
    }

    normaliseRow = (row) => {
        if (row && typeof row === 'object') {
            let met = row.met;
            if (typeof met === 'boolean') {
                met = met ? 'met' : 'not_met';
            }
            if (!this.constructor.MET_WHITELIST.includes(met)) {
                met = 'unknown';
            }
            return {
                criterion: String(row.criterion || '').slice(0, 500),
                target: String(row.target || '').slice(0, 255),
                actual: String(row.actual || '').slice(0, 255),
                met,
            };
        }
        return { criterion: '', target: '', actual: '', met: 'unknown' };
    };

    humanise(key) {
        return String(key)
            .replace(/([A-Z])/g, ' $1')
            .replace(/^./, (c) => c.toUpperCase())
            .replace(/_/g, ' ')
            .trim();
    }

    addRow(event) {
        if (event) event.preventDefault();
        this.rows.push({ criterion: '', target: '', actual: '', met: 'unknown' });
        this.render();
    }

    removeRow(event) {
        event.preventDefault();
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (!Number.isNaN(idx) && idx >= 0 && idx < this.rows.length) {
            this.rows.splice(idx, 1);
            this.render();
        }
    }

    updateField(event) {
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        const field = event.currentTarget.dataset.field;
        if (Number.isNaN(idx) || !this.rows[idx]) return;
        let value = event.currentTarget.value;
        if (field === 'met' && !this.constructor.MET_WHITELIST.includes(value)) {
            value = 'unknown';
        }
        this.rows[idx][field] = value;
        this.sync();
    }

    applyPrefill(event) {
        event.preventDefault();
        const existing = new Set(this.rows.map((r) => r.criterion.toLowerCase()));
        let added = 0;
        this.prefillValue.forEach((item) => {
            const crit = String((item && item.criterion) || '').trim();
            if (crit !== '' && !existing.has(crit.toLowerCase())) {
                this.rows.push({
                    criterion: crit,
                    target: String((item && item.target) || ''),
                    actual: '',
                    met: 'unknown',
                });
                added += 1;
            }
        });
        if (added > 0) {
            if (this.hasPrefillBannerTarget) {
                this.prefillBannerTarget.classList.remove('d-none');
            }
            this.render();
        }
    }

    showRawJson(event) {
        event.preventDefault();
        const btn = event.currentTarget;
        const isOpen = !this.rawPanelTarget.classList.contains('d-none');
        if (isOpen) {
            this.rawPanelTarget.classList.add('d-none');
            btn.setAttribute('aria-pressed', 'false');
        } else {
            this.rawTextareaTarget.value = JSON.stringify(this.rows, null, 2);
            this.rawPanelTarget.classList.remove('d-none');
            btn.setAttribute('aria-pressed', 'true');
        }
    }

    syncFromRaw(event) {
        const raw = event.currentTarget.value;
        const parsed = this.parseRows(raw);
        if (parsed.length > 0 || raw.trim() === '' || raw.trim() === '[]') {
            this.rows = parsed;
            this.render();
        }
    }

    render() {
        const tpl = this.hasTemplateTarget ? this.templateTarget.innerHTML : '';
        this.bodyTarget.innerHTML = '';
        this.rows.forEach((row, idx) => {
            this.bodyTarget.insertAdjacentHTML('beforeend', this.buildRow(tpl, row, idx));
        });
        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.classList.toggle('d-none', this.rows.length > 0);
        }
        this.sync();
    }

    buildRow(tpl, row, idx) {
        const sel = (test) => (test ? 'selected' : '');
        return tpl
            .replaceAll('__INDEX__', String(idx))
            .replaceAll('__CRITERION__', this.escapeAttr(row.criterion))
            .replaceAll('__TARGET__', this.escapeAttr(row.target))
            .replaceAll('__ACTUAL__', this.escapeAttr(row.actual))
            .replaceAll('__MET__', this.escapeAttr(row.met))
            .replaceAll('__MET_SEL_UNKNOWN__', sel(row.met === 'unknown'))
            .replaceAll('__MET_SEL_MET__', sel(row.met === 'met'))
            .replaceAll('__MET_SEL_NOT_MET__', sel(row.met === 'not_met'));
    }

    sync() {
        this.hiddenTarget.value = this.rows.length === 0 ? '' : JSON.stringify(this.rows);
    }

    escapeAttr(value) {
        return String(value ?? '').replace(/[&<>"']/g, (ch) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[ch]));
    }
}
