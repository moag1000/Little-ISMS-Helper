import { Controller } from '@hotwired/stimulus';

/**
 * Incident NIS2 Controller
 *
 * Shows/hides the NIS2 reporting section and alert banner based on
 * incident severity, affected users count, and cross-border impact.
 *
 * NIS2 criteria:
 *   - severity === 'critical'  → always show
 *   - severity === 'high' AND (affectedUsers > 100 OR crossBorderImpact === true)  → show
 *
 * Features:
 * - Auto-show/hide NIS2 fieldset and alert banner on field changes
 * - Manual override: show/hide buttons bypass auto-logic until criteria re-evaluated
 * - Turbo-safe: connect()/disconnect() lifecycle replaces global event listeners
 *
 * Usage:
 * <div data-controller="incident-nis2"
 *      data-incident-nis2-affected-users-threshold-value="100">
 *     <select data-incident-nis2-target="severitySelect"
 *             data-action="change->incident-nis2#checkNis2Relevance"></select>
 *     <input data-incident-nis2-target="affectedUsersInput"
 *            data-action="input->incident-nis2#checkNis2Relevance">
 *     <input type="radio" data-incident-nis2-target="crossBorderRadios"
 *            data-action="change->incident-nis2#checkNis2Relevance">
 *     <fieldset data-incident-nis2-target="nis2Section"></fieldset>
 *     <div data-incident-nis2-target="alertBanner"></div>
 *     <div data-incident-nis2-target="showButtonContainer">
 *         <button data-incident-nis2-target="showButton"
 *                 data-action="click->incident-nis2#show"></button>
 *     </div>
 *     <button data-incident-nis2-target="hideButton"
 *             data-action="click->incident-nis2#hide"></button>
 * </div>
 */
export default class extends Controller {
    static targets = [
        'nis2Section',
        'alertBanner',
        'severitySelect',
        'affectedUsersInput',
        'crossBorderRadios',
        'showButtonContainer',
        'showButton',
        'hideButton',
    ];

    static values = {
        affectedUsersThreshold: { type: Number, default: 100 },
    };

    connect() {
        this._manualOverride = false;
        this.checkNis2Relevance();
    }

    /**
     * Evaluate whether NIS2 criteria are met and update visibility accordingly.
     * Skipped when the user has manually overridden the section visibility.
     */
    checkNis2Relevance() {
        if (this._manualOverride) return;

        const shouldShow = this._meetsNis2Criteria();
        this._setNis2Visible(shouldShow);
    }

    /**
     * Manually show the NIS2 section (overrides auto-logic until hide() is called).
     */
    show() {
        this._manualOverride = true;
        this._setNis2Visible(true);
    }

    /**
     * Manually hide the NIS2 section and re-enable auto-logic.
     */
    hide() {
        this._manualOverride = false;
        this._setNis2Visible(false);
        // Re-evaluate immediately so the state is consistent with current field values
        this.checkNis2Relevance();
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when the current field values meet NIS2 reporting criteria.
     */
    _meetsNis2Criteria() {
        const severity = this.hasSeveritySelectTarget ? this.severitySelectTarget.value : '';
        const affectedUsers = this.hasAffectedUsersInputTarget
            ? (parseInt(this.affectedUsersInputTarget.value, 10) || 0)
            : 0;
        const crossBorder = this._isCrossBorderChecked();

        return (
            severity === 'critical' ||
            (severity === 'high' && (affectedUsers > this.affectedUsersThresholdValue || crossBorder))
        );
    }

    /**
     * Returns true when the cross-border radio with value "1" is selected.
     * The target may be the container div or individual radio inputs.
     */
    _isCrossBorderChecked() {
        if (!this.hasCrossBorderRadiosTarget) return false;

        // Collect all elements — could be container divs or actual radio inputs
        const radios = this.crossBorderRadiosTargets.flatMap(el => {
            if (el.type === 'radio') return [el];
            return Array.from(el.querySelectorAll('input[type="radio"]'));
        });

        return radios.some(radio => radio.checked && radio.value === '1');
    }

    /**
     * Show or hide the NIS2 section and alert banner.
     */
    _setNis2Visible(visible) {
        if (this.hasNis2SectionTarget) {
            this.nis2SectionTarget.classList.toggle('d-none', !visible);
        }
        if (this.hasAlertBannerTarget) {
            this.alertBannerTarget.classList.toggle('d-none', !visible);
        }
        if (this.hasShowButtonContainerTarget) {
            this.showButtonContainerTarget.classList.toggle('d-none', visible);
        }
    }
}
