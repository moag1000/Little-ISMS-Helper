import { Controller } from '@hotwired/stimulus';

/**
 * escalation-chain — P-9 JsonBuilder for BCPlan.escalationLevels
 * (BSI 200-4 §6.2). Renders an auto-numbered escalation cascade with
 * trigger / responder / escalateAfter inputs per level.
 *
 * Data contract (matches BCPlan entity JSON):
 *   [
 *     {
 *       level: 1,
 *       trigger: string,
 *       responder: string,
 *       escalateAfter: '15min' | '1h' | string
 *     }, ...
 *   ]
 *
 * Levels renumbered 1..N on every render so the cascade stays contiguous.
 */
export default class extends Controller {
    static targets = ['hidden', 'rows', 'template', 'emptyState', 'rawPanel', 'rawTextarea'];
    static values = { name: String };

    connect() {
        this.rows = this.parseRows(this.hiddenTarget.value || '');
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
        if (!Array.isArray(decoded)) return [];
        return decoded.map(this.normaliseRow);
    }

    normaliseRow = (row, idx) => {
        if (row && typeof row === 'object') {
            return {
                level: Number.isInteger(row.level) ? row.level : (idx + 1),
                trigger: String(row.trigger || '').slice(0, 500),
                responder: String(row.responder || '').slice(0, 255),
                escalateAfter: String(row.escalateAfter || '').slice(0, 50),
            };
        }
        return { level: (idx + 1), trigger: '', responder: '', escalateAfter: '' };
    };

    renumber() {
        this.rows.forEach((row, idx) => {
            row.level = idx + 1;
        });
    }

    addRow(event) {
        if (event) event.preventDefault();
        this.rows.push({ level: this.rows.length + 1, trigger: '', responder: '', escalateAfter: '' });
        this.render();
    }

    removeRow(event) {
        event.preventDefault();
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (!Number.isNaN(idx) && idx >= 0 && idx < this.rows.length) {
            this.rows.splice(idx, 1);
            this.renumber();
            this.render();
        }
    }

    updateField(event) {
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        const field = event.currentTarget.dataset.field;
        if (Number.isNaN(idx) || !this.rows[idx]) return;
        this.rows[idx][field] = event.currentTarget.value;
        this.sync();
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
            this.renumber();
            this.render();
        }
    }

    render() {
        this.renumber();
        const tpl = this.hasTemplateTarget ? this.templateTarget.innerHTML : '';
        this.rowsTarget.innerHTML = '';
        this.rows.forEach((row, idx) => {
            this.rowsTarget.insertAdjacentHTML('beforeend', this.buildRow(tpl, row, idx));
        });
        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.classList.toggle('d-none', this.rows.length > 0);
        }
        this.sync();
    }

    buildRow(tpl, row, idx) {
        return tpl
            .replaceAll('__INDEX__', String(idx))
            .replaceAll('__LEVEL__', String(row.level))
            .replaceAll('__TRIGGER__', this.escapeAttr(row.trigger))
            .replaceAll('__RESPONDER__', this.escapeAttr(row.responder))
            .replaceAll('__ESCALATE_AFTER__', this.escapeAttr(row.escalateAfter));
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
