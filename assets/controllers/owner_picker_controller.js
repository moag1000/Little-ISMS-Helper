import { Controller } from '@hotwired/stimulus'

/**
 * OwnerPicker controller (audit-s4 P-1).
 *
 * Soft cross-disable UX for the User + Person owner cluster: when one
 * primary slot has a value, fade the other to signal "either / or".
 * Both stay submittable so the user can still clear / swap freely.
 *
 * Targets:
 *   - user      <select> bound to the User EntityType
 *   - person    <select> bound to the Person EntityType
 *   - deputies  <select multiple> bound to the Deputies EntityType (optional)
 *   - legacy    <input type="text"> bound to the legacy free-text field (optional)
 *
 * The picker tolerates the targets missing (e.g. forms without
 * deputies or legacy field) — every callback no-ops gracefully.
 */
export default class extends Controller {
    static targets = ['user', 'person', 'deputies', 'legacy']

    connect() {
        this.toggle()
    }

    toggle() {
        const userHasValue = this._hasValue(this.hasUserTarget ? this.userTarget : null)
        const personHasValue = this._hasValue(this.hasPersonTarget ? this.personTarget : null)

        // Visually dim the "other" slot when one is chosen — both remain
        // editable so the user can clear / swap. CSS handles the actual
        // styling via [data-owner-picker-dimmed="true"].
        this._setDimmed(this.hasUserTarget ? this.userTarget : null, !userHasValue && personHasValue)
        this._setDimmed(this.hasPersonTarget ? this.personTarget : null, userHasValue && !personHasValue)
    }

    _hasValue(el) {
        if (!el) {
            return false
        }
        if (el.tagName === 'SELECT') {
            return Array.from(el.selectedOptions).some((opt) => opt.value !== '' && opt.value != null)
        }
        return el.value != null && el.value.trim() !== ''
    }

    _setDimmed(el, dimmed) {
        if (!el) {
            return
        }
        // Walk up to the surrounding form-row wrapper so the label, hint
        // and Aurora frame all dim together.
        const wrapper = el.closest('.fa-owner-picker__slot') || el.parentElement
        if (wrapper) {
            wrapper.dataset.ownerPickerDimmed = dimmed ? 'true' : 'false'
        }
    }
}
