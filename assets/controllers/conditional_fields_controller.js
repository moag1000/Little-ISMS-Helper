import { Controller } from '@hotwired/stimulus'

/**
 * Conditional Fields Controller
 *
 * Progressive disclosure: hides/shows form fields based on the state of a trigger field.
 * Supports both checkboxes and expanded ChoiceType (radio buttons with boolean values).
 *
 * Usage: Add data-depends-on="TRIGGER_FIELD_ID" to conditional field elements.
 *   - For checkboxes: set the checkbox input ID
 *   - For radio groups: set the container/fieldset ID (the radio group wrapper)
 *
 * The controller looks for the closest .mb-3, .form-group, or .col-md-* wrapper
 * to toggle visibility on the entire form row.
 */
export default class extends Controller {
    connect() {
        this.element.querySelectorAll('[data-depends-on]').forEach(field => {
            const triggerId = field.dataset.dependsOn
            const negated = field.dataset.dependsOnNegated === 'true'
            const expectedValue = field.dataset.dependsOnValue
            const trigger = document.getElementById(triggerId)

            // Find the wrapper element to show/hide (climb up to .mb-3, .form-group, or col-md-*)
            const wrapper = field.closest('[class*="col-md-"]') || field.closest('.mb-3') || field.closest('.form-group') || field.parentElement

            if (trigger) {
                if (trigger.type === 'checkbox') {
                    // Simple checkbox trigger (supports negation)
                    const toggle = () => {
                        const isActive = negated ? !trigger.checked : trigger.checked
                        wrapper.style.display = isActive ? '' : 'none'
                    }
                    trigger.addEventListener('change', toggle)
                    toggle()
                } else if (trigger.tagName === 'SELECT') {
                    // Select-based trigger (match expected value, or any non-empty value)
                    const toggle = () => {
                        const val = trigger.value
                        const match = expectedValue !== undefined ? val === expectedValue : val !== ''
                        const isActive = negated ? !match : match
                        wrapper.style.display = isActive ? '' : 'none'
                    }
                    trigger.addEventListener('change', toggle)
                    toggle()
                } else if (trigger.querySelector && trigger.querySelector('input[type="radio"]')) {
                    // Radio button group trigger (expanded ChoiceType)
                    const radios = trigger.querySelectorAll('input[type="radio"]')
                    const toggle = () => {
                        const checked = Array.from(radios).find(r => r.checked)
                        // For boolean ChoiceType: value "1" or "true" means yes
                        // If expectedValue set, match exact value
                        let match
                        if (expectedValue !== undefined) {
                            match = checked && checked.value === expectedValue
                        } else {
                            match = checked && (checked.value === '1' || checked.value === 'true')
                        }
                        const isActive = negated ? !match : match
                        wrapper.style.display = isActive ? '' : 'none'
                    }
                    radios.forEach(radio => radio.addEventListener('change', toggle))
                    toggle()
                }
            }
        })
    }
}
