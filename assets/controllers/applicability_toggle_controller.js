import { Controller } from '@hotwired/stimulus'

/**
 * applicability-toggle
 *
 * Visualises the conditional-required state of "justification" when the
 * applicable=yes/no radio group flips. Adds a red `*` to the field label and
 * sets aria-required="true" so screen readers announce it before submission.
 *
 * Server-side enforcement is unchanged — the Callback validator on the entity
 * still rejects justification-empty + applicable=false. This controller only
 * closes the UX-P0 gap of users hitting "Save" and being surprised by a
 * server-side validation error they could not see coming.
 *
 * Usage:
 *   <form data-controller="applicability-toggle">
 *     <fieldset data-applicability-toggle-target="trigger"> ... applicable radios ... </fieldset>
 *     <textarea data-applicability-toggle-target="field" name="justification"></textarea>
 *   </form>
 *
 * Trigger value "0" (applicable=false) flips the marker on. Trigger value "1"
 * (applicable=true) flips it off.
 */
export default class extends Controller {
    static targets = ['trigger', 'field']

    connect() {
        if (!this.hasTriggerTarget) return

        const radios = this.triggerTarget.querySelectorAll('input[type="radio"]')
        radios.forEach(r => r.addEventListener('change', () => this.refresh()))
        this.refresh()
    }

    refresh() {
        if (!this.hasTriggerTarget || !this.hasFieldTarget) return

        const radios = this.triggerTarget.querySelectorAll('input[type="radio"]')
        const checked = Array.from(radios).find(r => r.checked)
        const isNotApplicable = checked && checked.value === '0'

        this.fieldTargets.forEach(field => {
            field.setAttribute('aria-required', isNotApplicable ? 'true' : 'false')
            const wrapper = field.closest('.mb-3') || field.closest('.form-group') || field.parentElement
            const label = wrapper?.querySelector('label')
            if (!label) return

            const existing = label.querySelector('.required-marker')
            if (isNotApplicable && !existing) {
                const marker = document.createElement('span')
                marker.className = 'required-marker text-danger ms-1'
                marker.textContent = '*'
                marker.setAttribute('aria-hidden', 'true')
                label.appendChild(marker)
            } else if (!isNotApplicable && existing) {
                existing.remove()
            }
        })
    }
}
