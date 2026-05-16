import { Controller } from '@hotwired/stimulus';

/**
 * subcontractor-chain — P-9 JsonBuilder for Supplier.subcontractorChain
 * (DORA Art. 28 sub-outsourcing chain, max-depth 4).
 *
 * NOTE: this is the new P-9 macro controller. The legacy
 * `subcontractor-chain-editor` controller (table layout) still serves the
 * existing fields. New _fa_subcontractor_chain macro uses this controller.
 *
 * Data contract (additive — backward-compatible with the legacy editor):
 *   [
 *     {
 *       tier: 1|2|3|4,
 *       name: string,
 *       lei: string,
 *       country: string (ISO-2),
 *       service: string,
 *       criticality: 'low|medium|high|critical' (legacy field, kept),
 *       critical: bool (NEW — DORA Art. 28 critical flag)
 *     }, ...
 *   ]
 *
 * Backward compat:
 *   - Legacy newline-separated string lists → tier=1 rows with the line as name
 *   - Legacy rows without `critical` → `critical: false`
 *   - Legacy rows with `criticality === 'critical' | 'high'` are auto-flagged
 *     `critical: true` on first render (one-shot upgrade, persisted on sync).
 */
export default class extends Controller {
    static targets = ['hidden', 'tree', 'template', 'emptyState', 'rawPanel', 'rawTextarea'];
    static values = { name: String };

    static MAX_TIER = 4;

    connect() {
        this.rows = this.parseRows(this.hiddenTarget.value || '');
        this.render();
    }

    parseRows(raw) {
        const trimmed = (raw || '').trim();
        if (trimmed === '') return [];
        if (trimmed.startsWith('[')) {
            try {
                const decoded = JSON.parse(trimmed);
                if (Array.isArray(decoded)) {
                    return decoded.map(this.normaliseRow.bind(this));
                }
            } catch (_) {
                return [];
            }
        }
        // Legacy newline-separated free-text list.
        return trimmed
            .split(/\r?\n/)
            .map((line) => line.trim())
            .filter(Boolean)
            .map((name) => ({ tier: 1, name, lei: '', country: '', service: '', criticality: '', critical: false }));
    }

    normaliseRow(row) {
        if (typeof row === 'string') {
            return { tier: 1, name: row, lei: '', country: '', service: '', criticality: '', critical: false };
        }
        if (row && typeof row === 'object') {
            const tier = Math.max(1, Math.min(this.constructor.MAX_TIER, parseInt(row.tier, 10) || 1));
            const legacyCriticality = row.criticality || '';
            // Auto-flag critical on first load when legacy criticality says so.
            const critical = typeof row.critical === 'boolean'
                ? row.critical
                : (legacyCriticality === 'critical' || legacyCriticality === 'high');
            return {
                tier,
                name: String(row.name || '').slice(0, 255),
                lei: String(row.lei || '').slice(0, 20),
                country: String(row.country || '').toUpperCase().slice(0, 2),
                service: String(row.service || '').slice(0, 255),
                criticality: legacyCriticality,
                critical,
            };
        }
        return { tier: 1, name: '', lei: '', country: '', service: '', criticality: '', critical: false };
    }

    addRow(event) {
        if (event) event.preventDefault();
        // Default tier = same as last row, or 1.
        const tier = this.rows.length > 0 ? this.rows[this.rows.length - 1].tier : 1;
        this.rows.push({ tier, name: '', lei: '', country: '', service: '', criticality: '', critical: false });
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
        if (field === 'country') value = String(value).toUpperCase().slice(0, 2);
        this.rows[idx][field] = value;
        this.sync();
    }

    updateCritical(event) {
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (Number.isNaN(idx) || !this.rows[idx]) return;
        this.rows[idx].critical = event.currentTarget.checked;
        this.sync();
    }

    indent(event) {
        event.preventDefault();
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (Number.isNaN(idx) || !this.rows[idx]) return;
        if (this.rows[idx].tier < this.constructor.MAX_TIER) {
            this.rows[idx].tier += 1;
            this.render();
        }
    }

    outdent(event) {
        event.preventDefault();
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (Number.isNaN(idx) || !this.rows[idx]) return;
        if (this.rows[idx].tier > 1) {
            this.rows[idx].tier -= 1;
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
        this.treeTarget.innerHTML = '';
        this.rows.forEach((row, idx) => {
            this.treeTarget.insertAdjacentHTML('beforeend', this.buildRow(tpl, row, idx));
        });
        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.classList.toggle('d-none', this.rows.length > 0);
        }
        this.sync();
    }

    buildRow(tpl, row, idx) {
        const depth = String(row.tier - 1);
        return tpl
            .replaceAll('__INDEX__', String(idx))
            .replaceAll('__TIER__', String(row.tier))
            .replaceAll('__DEPTH__', depth)
            .replaceAll('__NAME__', this.escapeAttr(row.name))
            .replaceAll('__LEI__', this.escapeAttr(row.lei))
            .replaceAll('__COUNTRY__', this.escapeAttr(row.country))
            .replaceAll('__SERVICE__', this.escapeAttr(row.service))
            .replaceAll('__CRITICAL_CHECKED__', row.critical ? 'checked' : '')
            .replaceAll('__CAN_INDENT__', row.tier >= this.constructor.MAX_TIER ? 'disabled' : '')
            .replaceAll('__CAN_OUTDENT__', row.tier <= 1 ? 'disabled' : '');
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
