import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard Bestandsaufnahme — MUST #4: Bulk action bar.
 *
 * Every inventory row's action-<select> is `required`, so on large brownfield
 * estates the user must touch many rows. These mass-set actions make that one
 * click each. All of them gate through a single confirm modal first (Junior-ISBs
 * deserve a "you can still adjust individual rows" reassurance).
 *
 * Targeted:
 *   - replaceAllOutdated  — rows whose `data-suggested-action` is "replace"
 *   - keepAllWizardTagged — rows marked `data-wizard-tagged="1"`
 * Broad:
 *   - keepAll             — every row → keep
 *   - replaceAll          — every row → replace
 *   - applySuggestions    — every row → its own `data-suggested-action`
 *
 * After mutating a select we dispatch a synthetic `change` so the row
 * controller's updateVisibility() re-runs (toggling merge/split sub-blocks).
 */
export default class extends Controller {
    static targets = ['confirmModal', 'confirmBody', 'confirmApplyBtn', 'feedback'];

    // ── Targeted ──────────────────────────────────────────────────────────
    replaceAllOutdated(event) {
        event.preventDefault();
        const rows = this._collectRows((row) => (row.dataset.suggestedAction || '').toLowerCase() === 'replace');
        this._gateAndApply(rows, this._readMessage(event, 'replace'), () => this._applyToRows(rows, 'replace'));
    }

    keepAllWizardTagged(event) {
        event.preventDefault();
        const rows = this._collectRows((row) => (row.dataset.wizardTagged || '') === '1');
        this._gateAndApply(rows, this._readMessage(event, 'keep'), () => this._applyToRows(rows, 'keep'));
    }

    // ── Broad ─────────────────────────────────────────────────────────────
    keepAll(event) {
        event.preventDefault();
        const rows = this._collectRows(() => true);
        this._gateAndApply(rows, this._readMessage(event, 'keepAll'), () => this._applyToRows(rows, 'keep'));
    }

    replaceAll(event) {
        event.preventDefault();
        const rows = this._collectRows(() => true);
        this._gateAndApply(rows, this._readMessage(event, 'replaceAll'), () => this._applyToRows(rows, 'replace'));
    }

    applySuggestions(event) {
        event.preventDefault();
        // Only rows that carry a usable suggestion in the known action set.
        const known = ['replace', 'keep', 'merge_into_topic', 'split_to_topics'];
        const rows = this._collectRows((row) => known.includes((row.dataset.suggestedAction || '').toLowerCase()));
        this._gateAndApply(rows, this._readMessage(event, 'suggestions'), () => this._applyPerRowSuggestion(rows));
    }

    // ── Apply primitives ──────────────────────────────────────────────────
    _applyToRows(rows, value) {
        let applied = 0;
        rows.forEach((row) => {
            const select = row.querySelector('select[data-bestandsaufnahme-row-target="actionSelect"]');
            if (!select) {
                return;
            }
            // Never blank a select: skip if the target value is not an option here.
            if (!Array.from(select.options).some((o) => o.value === value)) {
                return;
            }
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            applied += 1;
        });
        return applied;
    }

    _applyPerRowSuggestion(rows) {
        let applied = 0;
        rows.forEach((row) => {
            const select = row.querySelector('select[data-bestandsaufnahme-row-target="actionSelect"]');
            const value = (row.dataset.suggestedAction || '').toLowerCase();
            if (!select || !value) {
                return;
            }
            if (!Array.from(select.options).some((o) => o.value === value)) {
                return;
            }
            select.value = value;
            select.dispatchEvent(new Event('change', { bubbles: true }));
            applied += 1;
        });
        return applied;
    }

    // ── Confirm gate (shared by all actions) ──────────────────────────────
    _gateAndApply(rows, body, runFn) {
        if (rows.length === 0) {
            this._announce(this._dataset('noTargetsMessage') || 'No matching rows found.');
            return;
        }

        const interpolated = (body || '').replace('%count%', String(rows.length));
        const commit = () => {
            const applied = runFn();
            this._announce((this._dataset('appliedTemplate') || '%count% rows updated.').replace('%count%', String(applied)));
        };

        if (this.hasConfirmModalTarget && this.hasConfirmApplyBtnTarget) {
            if (this.hasConfirmBodyTarget) {
                this.confirmBodyTarget.textContent = interpolated;
            }
            // Replace prior listener to avoid double-apply on repeated clicks.
            const fresh = this.confirmApplyBtnTarget.cloneNode(true);
            this.confirmApplyBtnTarget.parentNode.replaceChild(fresh, this.confirmApplyBtnTarget);
            fresh.addEventListener('click', () => { commit(); this._hideModal(); }, { once: true });
            this._showModal();
        } else if (typeof window.faConfirm === 'function') {
            window.faConfirm(interpolated, { tone: 'warn' }).then((ok) => { if (ok) { commit(); } });
        } else {
            // eslint-disable-next-line no-alert
            if (window.confirm(interpolated)) {
                commit();
            }
        }
    }

    _collectRows(predicate) {
        const scope = this.element.closest('form, .wizard-step, body') || document;
        return Array.from(scope.querySelectorAll('tr[data-controller~="bestandsaufnahme-row"]')).filter(predicate);
    }

    _showModal() {
        if (!this.hasConfirmModalTarget) {
            return;
        }
        document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
            bubbles: true,
            detail: { id: this.confirmModalTarget.id },
        }));
    }

    _hideModal() {
        if (!this.hasConfirmModalTarget) {
            return;
        }
        const faModal = this.application.getControllerForElementAndIdentifier(this.confirmModalTarget, 'fa-modal');
        faModal?.close();
    }

    _announce(message) {
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.textContent = message;
        }
    }

    _readMessage(event, kind) {
        const map = {
            replace: 'replaceBody',
            keep: 'keepBody',
            keepAll: 'keepAllBody',
            replaceAll: 'replaceAllBody',
            suggestions: 'suggestionsBody',
        };
        const datasetKey = map[kind] || 'keepBody';
        const fromParam = event && event.params ? event.params[datasetKey] : null;
        return fromParam || this._dataset(datasetKey) || this._dataset('keepBody') || 'Apply to %count% row(s)?';
    }

    _dataset(key) {
        // Template attrs are `data-bestandsaufnahme-bulk-<key>` → dataset key
        // `bestandsaufnahmeBulk<Key>`. Resolve both the prefixed and the bare
        // form so older markup keeps working.
        const prefixed = 'bestandsaufnahmeBulk' + key.charAt(0).toUpperCase() + key.slice(1);
        return this.element.dataset[prefixed] || this.element.dataset[key] || '';
    }
}
