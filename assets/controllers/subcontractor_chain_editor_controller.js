import { Controller } from '@hotwired/stimulus';

/**
 * DORA Art. 28.6 / 30 — structured sub-outsourcing chain editor.
 *
 * Renders a table of subcontractor rows with tier/name/LEI/country/service/
 * criticality inputs, serialises the whole chain as JSON into a single
 * hidden <textarea name="...[subcontractorChain]"> that the SupplierType
 * POST_SUBMIT hook decodes.
 *
 * Data contract:
 *   rows: Array<
 *     { tier: int, name: string, lei: string, country: string,
 *       service: string, criticality: string }
 *   >
 *
 * Backward compat: if the textarea carries a newline-separated plain-
 * string list (legacy format), each line is lifted into a row with
 * tier=1 and the string in `name`.
 */
export default class extends Controller {
    static targets = ['hidden', 'body', 'template', 'emptyState'];

    connect() {
        const raw = (this.hiddenTarget.value || '').trim();
        let rows = [];
        if (raw.startsWith('[')) {
            try {
                const parsed = JSON.parse(raw);
                if (Array.isArray(parsed)) {
                    rows = parsed.map(this.normaliseRow);
                }
            } catch (_) {
                rows = [];
            }
        } else if (raw !== '') {
            rows = raw.split(/\r?\n/).map(line => line.trim()).filter(Boolean)
                .map(name => ({ tier: 1, name, lei: '', country: '', service: '', criticality: '' }));
        }
        this.rows = rows;
        this.render();
    }

    addRow(event) {
        event.preventDefault();
        const nextTier = this.rows.length > 0
            ? Math.min((this.rows[this.rows.length - 1].tier || 1) + 1, 5)
            : 1;
        this.rows.push({ tier: nextTier, name: '', lei: '', country: '', service: '', criticality: '' });
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
        if (field === 'tier') {
            value = parseInt(value, 10) || 1;
        }
        this.rows[idx][field] = value;
        this.sync();
    }

    normaliseRow = (row) => {
        if (typeof row === 'string') {
            return { tier: 1, name: row, lei: '', country: '', service: '', criticality: '' };
        }
        if (row && typeof row === 'object') {
            return {
                tier: parseInt(row.tier, 10) || 1,
                name: row.name || '',
                lei: row.lei || '',
                country: (row.country || '').toUpperCase(),
                service: row.service || '',
                criticality: row.criticality || '',
            };
        }
        return { tier: 1, name: '', lei: '', country: '', service: '', criticality: '' };
    };

    render() {
        const tpl = this.hasTemplateTarget ? this.templateTarget.innerHTML : '';
        this.bodyTarget.innerHTML = '';
        this.rows.forEach((row, idx) => {
            const html = this.buildRow(tpl, row, idx);
            this.bodyTarget.insertAdjacentHTML('beforeend', html);
        });
        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.classList.toggle('d-none', this.rows.length > 0);
        }
        this.sync();
    }

    buildRow(tpl, row, idx) {
        // Simple placeholder replacement — `__INDEX__`, `__TIER__`, etc.
        return tpl
            .replaceAll('__INDEX__', String(idx))
            .replaceAll('__TIER__', String(row.tier))
            .replaceAll('__NAME__', this.escape(row.name))
            .replaceAll('__LEI__', this.escape(row.lei))
            .replaceAll('__COUNTRY__', this.escape(row.country))
            .replaceAll('__SERVICE__', this.escape(row.service))
            .replaceAll('__CRIT__', this.escape(row.criticality))
            .replaceAll('__TIER_SEL_1__', row.tier === 1 ? 'selected' : '')
            .replaceAll('__TIER_SEL_2__', row.tier === 2 ? 'selected' : '')
            .replaceAll('__TIER_SEL_3__', row.tier === 3 ? 'selected' : '')
            .replaceAll('__TIER_SEL_4__', row.tier === 4 ? 'selected' : '')
            .replaceAll('__TIER_SEL_5__', row.tier === 5 ? 'selected' : '')
            .replaceAll('__CRIT_SEL_LOW__', row.criticality === 'low' ? 'selected' : '')
            .replaceAll('__CRIT_SEL_MEDIUM__', row.criticality === 'medium' ? 'selected' : '')
            .replaceAll('__CRIT_SEL_HIGH__', row.criticality === 'high' ? 'selected' : '')
            .replaceAll('__CRIT_SEL_CRITICAL__', row.criticality === 'critical' ? 'selected' : '');
    }

    sync() {
        this.hiddenTarget.value = JSON.stringify(this.rows);
    }

    escape(v) {
        return String(v ?? '').replace(/[&<>"']/g, ch => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;',
        }[ch]));
    }
}
