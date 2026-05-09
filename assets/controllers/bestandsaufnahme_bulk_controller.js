import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard Bestandsaufnahme — MUST #4: Bulk action bar.
 *
 * Two mass-set actions for the inventory table:
 *   - replaceAllOutdated  — sets every row whose `data-suggested-action`
 *                           is "replace" to chosen_action="replace".
 *   - keepAllWizardTagged — sets every row marked `data-wizard-tagged="1"`
 *                           to chosen_action="keep".
 *
 * Each row is a `<tr data-controller="bestandsaufnahme-row" …>` carrying
 * a `<select name="decisions[X][action]" data-bestandsaufnahme-row-target=
 * "actionSelect">`. We dispatch a synthetic `change` event after mutation
 * so the row controller's `updateVisibility()` re-runs (which toggles the
 * merge / split sub-blocks).
 *
 * A confirmation modal (Bootstrap, declared in the template) gates the
 * mutation — Junior-ISBs deserve a "you can still adjust individual rows"
 * reassurance before 12 selects flip in one click.
 */
export default class extends Controller {
    static targets = ['confirmModal', 'confirmBody', 'confirmApplyBtn', 'feedback'];

    /**
     * Trigger the "replace all outdated" flow — counts targets, opens the
     * confirm modal with the count interpolated, hooks the apply button.
     */
    replaceAllOutdated(event) {
        event.preventDefault();
        const rows = this._collectRows((row) =>
            (row.dataset.suggestedAction || '').toLowerCase() === 'replace'
        );
        this._confirmAndApply(rows, 'replace', this._readMessage(event, 'replace'));
    }

    /**
     * Trigger the "skip all wizard-tagged" flow.
     */
    keepAllWizardTagged(event) {
        event.preventDefault();
        const rows = this._collectRows((row) =>
            (row.dataset.wizardTagged || '') === '1'
        );
        this._confirmAndApply(rows, 'keep', this._readMessage(event, 'keep'));
    }

    /**
     * Apply the mutation: walk the candidate rows, set their action select
     * value, dispatch `change` so the row controller re-renders.
     */
    _applyToRows(rows, value) {
        let applied = 0;
        rows.forEach((row) => {
            const select = row.querySelector('select[data-bestandsaufnahme-row-target="actionSelect"]');
            if (!select) {
                return;
            }
            select.value = value;
            // Notify the row controller (visibility, validity).
            select.dispatchEvent(new Event('change', { bubbles: true }));
            applied += 1;
        });
        return applied;
    }

    /**
     * Open the confirm modal (if present), wire the apply button to commit
     * on click. Without a modal we fall back to a window.confirm prompt so
     * tests + non-Bootstrap fallbacks still gate the destructive action.
     */
    _confirmAndApply(rows, value, body) {
        if (rows.length === 0) {
            this._announce(this._dataset('noTargetsMessage') || 'No matching rows found.');
            return;
        }

        const interpolated = (body || '').replace('%count%', String(rows.length));

        if (this.hasConfirmModalTarget && this.hasConfirmApplyBtnTarget) {
            if (this.hasConfirmBodyTarget) {
                this.confirmBodyTarget.textContent = interpolated;
            }

            // Replace prior listener to avoid double-apply on repeated clicks.
            const fresh = this.confirmApplyBtnTarget.cloneNode(true);
            this.confirmApplyBtnTarget.parentNode.replaceChild(fresh, this.confirmApplyBtnTarget);
            fresh.addEventListener('click', () => {
                const applied = this._applyToRows(rows, value);
                this._announce((this._dataset('appliedTemplate') || '%count% rows updated.').replace('%count%', String(applied)));
                this._hideModal();
            }, { once: true });

            this._showModal();
        } else if (typeof window.faConfirm === 'function') {
            // Aurora helper available — async dialog tone=warn for bulk-set.
            window.faConfirm(interpolated, { tone: 'warn' }).then((ok) => {
                if (!ok) {
                    return;
                }
                const applied = this._applyToRows(rows, value);
                this._announce((this._dataset('appliedTemplate') || '%count% rows updated.').replace('%count%', String(applied)));
            });
        } else {
            // Last-resort fallback — should never fire in production since
            // fa-alerts.js is bundled in app.js. Native confirm preserved
            // so the bulk-action still works on stripped-down envs.
            // eslint-disable-next-line no-alert
            if (window.confirm(interpolated)) {
                const applied = this._applyToRows(rows, value);
                this._announce((this._dataset('appliedTemplate') || '%count% rows updated.').replace('%count%', String(applied)));
            }
        }
    }

    _collectRows(predicate) {
        // Scope to rows controlled by bestandsaufnahme-row, inside the same
        // wizard-step container as this bulk controller (defensive against
        // accidentally mutating other tables on the page).
        const scope = this.element.closest('form, .wizard-step, body') || document;
        return Array.from(scope.querySelectorAll('tr[data-controller~="bestandsaufnahme-row"]'))
            .filter(predicate);
    }

    _showModal() {
        if (!this.hasConfirmModalTarget) {
            return;
        }
        const el = this.confirmModalTarget;
        if (window.bootstrap && window.bootstrap.Modal) {
            window.bootstrap.Modal.getOrCreateInstance(el).show();
        } else {
            el.classList.add('show');
            el.style.display = 'block';
        }
    }

    _hideModal() {
        if (!this.hasConfirmModalTarget) {
            return;
        }
        const el = this.confirmModalTarget;
        if (window.bootstrap && window.bootstrap.Modal) {
            const instance = window.bootstrap.Modal.getInstance(el);
            if (instance) {
                instance.hide();
            }
        } else {
            el.classList.remove('show');
            el.style.display = 'none';
        }
    }

    _announce(message) {
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.textContent = message;
        }
    }

    _readMessage(event, kind) {
        const fromParam = event && event.params ? event.params[kind === 'replace' ? 'replaceBody' : 'keepBody'] : null;
        if (fromParam) {
            return fromParam;
        }
        return this._dataset(kind === 'replace' ? 'replaceBody' : 'keepBody');
    }

    _dataset(key) {
        return this.element.dataset[key] || '';
    }
}
