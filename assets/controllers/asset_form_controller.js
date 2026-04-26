import { Controller } from '@hotwired/stimulus'

/**
 * Asset Form Controller
 *
 * Provides AI-Agent-specific UX helpers for the asset form:
 *
 *   1. Classification pre-suggestion based on the typed/selected provider.
 *      The mapping mirrors the AI Risk Decision Matrix
 *      (fixtures/mris/help-texts.yaml → blocker_solutions.ai_risk_decision_matrix).
 *      The suggestion is ONLY applied when the classification field is still
 *      empty — the user always has the final word (EU AI Act Art. 6 risk
 *      classification is use-case dependent, not vendor dependent).
 *
 *   2. Visual hint on the classification field after a suggestion is applied
 *      so the user notices the auto-fill.
 *
 * The progressive disclosure (show/hide) is handled by the existing
 * `conditional-fields` controller via `data-depends-on` — kept separate so
 * each controller has a single responsibility.
 */
export default class extends Controller {
    static targets = ['provider', 'classification']

    /**
     * Provider name -> recommended EU AI Act class.
     * Names are matched case-insensitively and via substring, so
     * "GitHub Copilot" matches "Copilot" or "github copilot".
     *
     * Source: fixtures/mris/help-texts.yaml (12 typical tools).
     * IMPORTANT: These are pre-classification HINTS only. The class depends
     * on the use case — document deviations.
     */
    static suggestionMap = [
        { match: 'copilot', class: 'limited_risk' },
        { match: 'cursor', class: 'limited_risk' },
        { match: 'claude code', class: 'limited_risk' },
        { match: 'chatgpt enterprise', class: 'limited_risk' },
        { match: 'chatgpt', class: 'limited_risk' },
        { match: 'hirevue', class: 'high_risk' },
        { match: 'eightfold', class: 'high_risk' },
        { match: 'recruiter', class: 'high_risk' },
        { match: 'robo-advisor', class: 'high_risk' },
        { match: 'bonität', class: 'high_risk' },
        { match: 'bonitaet', class: 'high_risk' },
        { match: 'credit scoring', class: 'high_risk' },
        { match: 'predictive policing', class: 'prohibited' },
        { match: 'social scoring', class: 'prohibited' },
        { match: 'medical imaging', class: 'high_risk' },
        { match: 'radiologie', class: 'high_risk' },
        { match: 'fraud detection', class: 'high_risk' },
        { match: 'recommender', class: 'limited_risk' },
        { match: 'spam', class: 'minimal_risk' },
        { match: 'smart home', class: 'minimal_risk' },
    ]

    suggestClassification() {
        if (!this.hasProviderTarget || !this.hasClassificationTarget) {
            return
        }
        const classificationField = this.classificationTarget
        // Never overwrite a value the user already chose.
        if (classificationField.value !== '') {
            return
        }
        const providerValue = (this.providerTarget.value || '').trim().toLowerCase()
        if (providerValue === '') {
            return
        }
        const hit = this.constructor.suggestionMap.find(entry => providerValue.includes(entry.match))
        if (!hit) {
            return
        }
        // Verify the option exists in the select before applying.
        const option = Array.from(classificationField.options).find(o => o.value === hit.class)
        if (!option) {
            return
        }
        classificationField.value = hit.class
        classificationField.classList.add('border-info')
        classificationField.dispatchEvent(new Event('change', { bubbles: true }))
        // Remove the visual hint as soon as the user interacts with the field.
        const clear = () => classificationField.classList.remove('border-info')
        classificationField.addEventListener('change', clear, { once: true })
        classificationField.addEventListener('focus', clear, { once: true })
    }
}
