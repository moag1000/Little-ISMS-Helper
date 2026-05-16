import { Controller } from '@hotwired/stimulus';

/**
 * team-role-builder — P-9 JsonBuilder visual repeater for
 * BCPlan.responseTeamMembers (ISO 22301 §8.5.3).
 *
 * Data contract (matches BCPlan entity JSON):
 *   [
 *     {
 *       role: 'incident_commander|comms_lead|recovery_lead|technical_lead|other',
 *       userId: int|null,
 *       name: string,
 *       contact: string,
 *       responsibilities: string
 *     }, ...
 *   ]
 *
 * Backward compat: a legacy JSON value with unknown role-strings is coerced
 * to {role: 'other'} so no data is lost on first render.
 */
export default class extends Controller {
    static targets = ['hidden', 'body', 'template', 'emptyState', 'rawPanel', 'rawTextarea'];
    static values = { name: String };

    static ROLE_WHITELIST = [
        'incident_commander',
        'comms_lead',
        'recovery_lead',
        'technical_lead',
        'other',
    ];

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
        return decoded.map(this.normaliseRow.bind(this));
    }

    normaliseRow(row) {
        if (row && typeof row === 'object') {
            const role = this.constructor.ROLE_WHITELIST.includes(row.role) ? row.role : 'other';
            return {
                role,
                userId: row.userId !== undefined && row.userId !== null && row.userId !== ''
                    ? parseInt(row.userId, 10) || null
                    : null,
                name: String(row.name || '').slice(0, 255),
                contact: String(row.contact || '').slice(0, 255),
                responsibilities: String(row.responsibilities || '').slice(0, 1000),
            };
        }
        return { role: 'other', userId: null, name: '', contact: '', responsibilities: '' };
    }

    addRow(event) {
        if (event) event.preventDefault();
        this.rows.push({ role: 'incident_commander', userId: null, name: '', contact: '', responsibilities: '' });
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
        if (field === 'role' && !this.constructor.ROLE_WHITELIST.includes(value)) {
            value = 'other';
        }
        this.rows[idx][field] = value;
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
            .replaceAll('__NAME__', this.escapeAttr(row.name))
            .replaceAll('__CONTACT__', this.escapeAttr(row.contact))
            .replaceAll('__RESP__', this.escapeAttr(row.responsibilities))
            .replaceAll('__ROLE__', this.escapeAttr(row.role))
            .replaceAll('__ROLE_SEL_INCIDENT_COMMANDER__', sel(row.role === 'incident_commander'))
            .replaceAll('__ROLE_SEL_COMMS_LEAD__', sel(row.role === 'comms_lead'))
            .replaceAll('__ROLE_SEL_RECOVERY_LEAD__', sel(row.role === 'recovery_lead'))
            .replaceAll('__ROLE_SEL_TECHNICAL_LEAD__', sel(row.role === 'technical_lead'))
            .replaceAll('__ROLE_SEL_OTHER__', sel(row.role === 'other'));
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
