import { Controller } from '@hotwired/stimulus'

/**
 * Audit Finding Correlation Help Controller
 *
 * C5-03 (Cluster E · Audit-Finding-Polish, S14). ISO 19011 Cl. 6.4.8 guidance:
 * `severity` and `type` of an audit finding are conventionally correlated.
 * A `critical` severity finding is virtually always a Major Nonconformity;
 * a `low` severity finding is rarely more than an Observation or Opportunity
 * for Improvement.
 *
 * This controller does NOT enforce the correlation — junior implementers
 * should be free to deviate when the auditor's professional judgement
 * requires it. Instead, it shows a discreet hint underneath the `type`
 * field whenever the chosen severity does not match the conventional type.
 *
 * Wiring (in the form template — see templates/audit_finding/_form.html.twig):
 *   <div data-controller="audit-finding-correlation-help"
 *        data-audit-finding-correlation-help-severity-selector-value='[name$="[severity]"]'
 *        data-audit-finding-correlation-help-type-selector-value='[name$="[type]"]'>
 *     ... severity radios / select ...
 *     ... type select (with adjacent .audit-finding-correlation-help slot) ...
 *   </div>
 *
 * The controller looks up severity + type by selectors (defaults handle the
 * default Symfony FormType field names `audit_finding[severity]` and
 * `audit_finding[type]`). The help slot is auto-inserted under the type
 * field's wrapper if not pre-rendered.
 */
export default class extends Controller {
    static values = {
        severitySelector: { type: String, default: '[name$="[severity]"]' },
        typeSelector: { type: String, default: '[name$="[type]"]' },
    }

    /**
     * Conventional severity → recommended-type mapping per ISO 19011 Cl. 6.4.8.
     * `critical`/`high` typically map to Major-NC; `medium` is the borderline
     * Minor-NC / Observation case; `low` is typically an OfI / Observation.
     */
    static MAPPING = {
        critical: { recommended: 'major_nc', text: 'critical_to_major_nc' },
        high: { recommended: 'major_nc', text: 'high_to_major_nc' },
        medium: { recommended: 'minor_nc', text: 'medium_to_minor_nc' },
        low: { recommended: 'observation', text: 'low_to_observation' },
    }

    connect() {
        this.severityInputs = this.findInputs(this.severitySelectorValue)
        this.typeInput = this.element.querySelector(this.typeSelectorValue)

        if (this.typeInput === null || this.severityInputs.length === 0) {
            return
        }

        this.helpSlot = this.ensureHelpSlot()

        const refresh = () => this.update()

        this.severityInputs.forEach(input => input.addEventListener('change', refresh))
        this.typeInput.addEventListener('change', refresh)
        // Initial render (form rehydration / edit views)
        refresh()
    }

    findInputs(selector) {
        // severity can be either a single <select> or a radio group — handle both.
        const matches = Array.from(this.element.querySelectorAll(selector))
        return matches.length > 0 ? matches : []
    }

    currentSeverity() {
        for (const input of this.severityInputs) {
            if (input.type === 'radio') {
                if (input.checked) {
                    return input.value
                }
            } else {
                return input.value
            }
        }
        return ''
    }

    currentType() {
        return this.typeInput ? this.typeInput.value : ''
    }

    update() {
        if (!this.helpSlot) {
            return
        }

        const severity = this.currentSeverity()
        const type = this.currentType()
        const mapping = this.constructor.MAPPING[severity]

        if (!severity || !mapping) {
            this.helpSlot.hidden = true
            this.helpSlot.textContent = ''
            return
        }

        // Always show the recommendation — junior implementers benefit from
        // the guidance, not just from deviation warnings. The wording shifts
        // between "this is conventional" and "consider <X>" depending on
        // whether the current type matches the recommended one.
        const matches = type === mapping.recommended
        const messageKey = matches
            ? `audit_finding.correlation.${mapping.text}.match`
            : `audit_finding.correlation.${mapping.text}.suggest`

        // Localised strings live in the data-* attributes of the help slot
        // (rendered by Twig with |trans({}, 'audits')). Fall back to the
        // English default if the translation is missing.
        const text = this.helpSlot.dataset[this.toCamel(messageKey)] ||
            this.helpSlot.dataset[this.toCamel(`audit_finding.correlation.${mapping.text}.suggest`)] ||
            ''

        this.helpSlot.textContent = text
        this.helpSlot.hidden = text === ''
        // Tone: green when it matches the convention, amber when it deviates.
        this.helpSlot.classList.toggle('text-success', matches)
        this.helpSlot.classList.toggle('text-warning', !matches)
    }

    toCamel(key) {
        // audit_finding.correlation.low_to_observation.suggest →
        // auditFindingCorrelationLowToObservationSuggest
        return key
            .replace(/[.](.)/g, (_, c) => c.toUpperCase())
            .replace(/_(.)/g, (_, c) => c.toUpperCase())
    }

    ensureHelpSlot() {
        // If the template already shipped a slot, reuse it.
        const existing = this.element.querySelector('[data-audit-finding-correlation-help-slot]')
        if (existing) {
            return existing
        }

        // Otherwise inject a discreet slot beneath the type field wrapper.
        const typeWrapper = this.typeInput.closest('[class*="col-md-"]')
            || this.typeInput.closest('.mb-3')
            || this.typeInput.closest('.form-group')
            || this.typeInput.parentElement

        const slot = document.createElement('div')
        slot.setAttribute('data-audit-finding-correlation-help-slot', '')
        slot.className = 'form-text small mt-1'
        slot.hidden = true
        typeWrapper.appendChild(slot)
        return slot
    }
}
