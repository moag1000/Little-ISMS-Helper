import { Controller } from '@hotwired/stimulus';

/**
 * capa-builder — S17 B4 follow-up visual editor for
 * AuditFinding.nonconformityDetails (ISO 27001 Cl. 10.2 b)+d)).
 *
 * Data contract (matches AuditFinding entity JSON):
 *   {
 *     rootCauseAnalysisMethod: '5-why|ishikawa|fmea|other',
 *     correctiveActions: [
 *       { description: string, ownerId: int|null, deadline: 'YYYY-MM-DD'|null }, ...
 *     ],
 *     verificationMethod: 'document-review|walkthrough|test|metrics-monitoring',
 *     verificationEvidence: string
 *   }
 *
 * Backward-compat: unknown method-keys are coerced to '' so no data is
 * lost on first render; legacy shapes are best-effort-parsed.
 */
export default class extends Controller {
    static targets = [
        'methodSelect',
        'verificationMethodSelect',
        'verificationEvidenceInput',
        'correctionsList',
        'addCorrectionBtn',
        'correctionTemplate',
        'hiddenJsonInput',
        'userChoicesJson',
        'emptyState',
    ];

    static values = { name: String };

    static METHOD_WHITELIST = ['5-why', 'ishikawa', 'fmea', 'other'];
    static VERIFICATION_WHITELIST = ['document-review', 'walkthrough', 'test', 'metrics-monitoring'];

    connect() {
        this.userChoices = this.parseUserChoices();
        this.state = this.parseInitial(this.hiddenJsonInputTarget.value || '');
        this.hydrate();
    }

    parseUserChoices() {
        if (!this.hasUserChoicesJsonTarget) {
            return [];
        }
        try {
            const parsed = JSON.parse(this.userChoicesJsonTarget.textContent || '[]');
            return Array.isArray(parsed) ? parsed : [];
        } catch (_) {
            return [];
        }
    }

    parseInitial(raw) {
        const trimmed = (raw || '').trim();
        if (trimmed === '') {
            return this.emptyState();
        }
        let decoded;
        try {
            decoded = JSON.parse(trimmed);
        } catch (_) {
            return this.emptyState();
        }
        if (!decoded || typeof decoded !== 'object' || Array.isArray(decoded)) {
            return this.emptyState();
        }
        return this.normalise(decoded);
    }

    emptyState() {
        return {
            rootCauseAnalysisMethod: '',
            correctiveActions: [],
            verificationMethod: '',
            verificationEvidence: '',
        };
    }

    normalise(obj) {
        const method = this.constructor.METHOD_WHITELIST.includes(obj.rootCauseAnalysisMethod)
            ? obj.rootCauseAnalysisMethod
            : '';
        const verification = this.constructor.VERIFICATION_WHITELIST.includes(obj.verificationMethod)
            ? obj.verificationMethod
            : '';
        const corrections = Array.isArray(obj.correctiveActions)
            ? obj.correctiveActions.map((c) => this.normaliseCorrection(c))
            : [];
        return {
            rootCauseAnalysisMethod: method,
            correctiveActions: corrections,
            verificationMethod: verification,
            verificationEvidence: String(obj.verificationEvidence || '').slice(0, 4000),
        };
    }

    normaliseCorrection(c) {
        if (!c || typeof c !== 'object') {
            return { description: '', ownerId: null, deadline: null };
        }
        return {
            description: String(c.description || '').slice(0, 1000),
            ownerId: c.ownerId !== undefined && c.ownerId !== null && c.ownerId !== ''
                ? parseInt(c.ownerId, 10) || null
                : null,
            deadline: c.deadline ? String(c.deadline).slice(0, 10) : null,
        };
    }

    hydrate() {
        if (this.hasMethodSelectTarget) {
            this.methodSelectTarget.value = this.state.rootCauseAnalysisMethod || '';
        }
        if (this.hasVerificationMethodSelectTarget) {
            this.verificationMethodSelectTarget.value = this.state.verificationMethod || '';
        }
        if (this.hasVerificationEvidenceInputTarget) {
            this.verificationEvidenceInputTarget.value = this.state.verificationEvidence || '';
        }
        this.renderCorrections();
    }

    renderCorrections() {
        if (!this.hasCorrectionsListTarget) return;
        const tpl = this.hasCorrectionTemplateTarget ? this.correctionTemplateTarget.innerHTML : '';
        this.correctionsListTarget.innerHTML = '';
        this.state.correctiveActions.forEach((row, idx) => {
            this.correctionsListTarget.insertAdjacentHTML('beforeend', this.buildRow(tpl, row, idx));
        });
        if (this.hasEmptyStateTarget) {
            this.emptyStateTarget.classList.toggle('d-none', this.state.correctiveActions.length > 0);
        }
        this.serialize();
    }

    buildRow(tpl, row, idx) {
        const optionsHtml = this.userChoices.map((u) => {
            const selected = row.ownerId !== null && parseInt(u.id, 10) === parseInt(row.ownerId, 10)
                ? ' selected' : '';
            return `<option value="${this.escapeAttr(String(u.id))}"${selected}>${this.escapeHtml(u.label)}</option>`;
        }).join('');
        return tpl
            .replaceAll('__INDEX__', String(idx))
            .replaceAll('__DESCRIPTION__', this.escapeAttr(row.description || ''))
            .replaceAll('__DEADLINE__', this.escapeAttr(row.deadline || ''))
            .replaceAll('__OWNER_OPTIONS__', optionsHtml);
    }

    addCorrection(event) {
        if (event) event.preventDefault();
        this.state.correctiveActions.push({ description: '', ownerId: null, deadline: null });
        this.renderCorrections();
    }

    removeCorrection(event) {
        event.preventDefault();
        const idx = parseInt(event.currentTarget.dataset.index, 10);
        if (!Number.isNaN(idx) && idx >= 0 && idx < this.state.correctiveActions.length) {
            this.state.correctiveActions.splice(idx, 1);
            this.renderCorrections();
        }
    }

    /**
     * Re-collect every field value from the DOM, re-write `this.state`,
     * then JSON-stringify into the hidden input. Idempotent.
     */
    serialize() {
        if (this.hasMethodSelectTarget) {
            const v = this.methodSelectTarget.value;
            this.state.rootCauseAnalysisMethod = this.constructor.METHOD_WHITELIST.includes(v) ? v : '';
        }
        if (this.hasVerificationMethodSelectTarget) {
            const v = this.verificationMethodSelectTarget.value;
            this.state.verificationMethod = this.constructor.VERIFICATION_WHITELIST.includes(v) ? v : '';
        }
        if (this.hasVerificationEvidenceInputTarget) {
            this.state.verificationEvidence = this.verificationEvidenceInputTarget.value || '';
        }
        if (this.hasCorrectionsListTarget) {
            const rows = this.correctionsListTarget.querySelectorAll('[data-field]');
            rows.forEach((el) => {
                const idx = parseInt(el.dataset.index, 10);
                const field = el.dataset.field;
                if (Number.isNaN(idx) || !this.state.correctiveActions[idx]) return;
                let value = el.value;
                if (field === 'ownerId') {
                    value = value === '' ? null : parseInt(value, 10) || null;
                } else if (field === 'deadline') {
                    value = value === '' ? null : value;
                }
                this.state.correctiveActions[idx][field] = value;
            });
        }
        this.writeHidden();
    }

    writeHidden() {
        if (!this.hasHiddenJsonInputTarget) return;
        const isEmpty = !this.state.rootCauseAnalysisMethod
            && !this.state.verificationMethod
            && !this.state.verificationEvidence
            && this.state.correctiveActions.length === 0;
        this.hiddenJsonInputTarget.value = isEmpty ? '' : JSON.stringify(this.state);
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

    escapeHtml(value) {
        return this.escapeAttr(value);
    }
}
