import { Controller } from '@hotwired/stimulus';

/**
 * Applicability Toggle Controller
 *
 * Junior-ISB-Audit-2026-05-22 S-07 — closes the UX-trap on the SoA Control
 * edit form where the `justification` field is server-side conditionally
 * required (when `applicable=false`) but the label-level `required:false`
 * leaves the user without a visible red required marker. The user submits
 * blind, the server bounces back with a violation, the user is confused.
 *
 * The controller watches the `applicable` radio group (ChoiceType with
 * boolean values, expanded), and:
 *
 *   - Toggles a `.fa-cyber-input__req` "*" marker on the justification label
 *     when applicable=false
 *   - Toggles `aria-required` between "true" and "false" on the textarea
 *
 * No backend change — pure UX consistency mirror of the existing server-side
 * `validateJustificationWhenNotApplicable` callback in
 * `src/Form/ControlType.php` (ISO 27001 6.1.3 d / 8.3 b).
 *
 * Usage (in FormType-built attrs):
 *
 *   $builder->add('justification', TextareaType::class, [
 *       'attr' => [
 *           'data-applicability-toggle-target' => 'justification',
 *           'data-applicability-toggle-required-when' => 'false',
 *       ],
 *   ]);
 *
 *   // …and on a wrapping element (e.g. the form root):
 *   <form data-controller="applicability-toggle"
 *         data-applicability-toggle-trigger-name="control[applicable]">
 *
 * If `trigger-name` is omitted, the controller falls back to discovering any
 * radio group with name ending in `[applicable]`.
 */
export default class extends Controller {
    static targets = ['justification'];
    static values = {
        triggerName: { type: String, default: '' },
        requiredWhen: { type: String, default: 'false' },
    };

    connect() {
        this.triggers = this.#findTriggers();
        if (this.triggers.length === 0) {
            return;
        }
        this.boundUpdate = () => this.update();
        this.triggers.forEach((t) => t.addEventListener('change', this.boundUpdate));
        this.update();
    }

    disconnect() {
        if (this.triggers && this.boundUpdate) {
            this.triggers.forEach((t) => t.removeEventListener('change', this.boundUpdate));
        }
    }

    update() {
        const isRequired = this.#isRequired();
        this.justificationTargets.forEach((field) => {
            this.#applyState(field, isRequired);
        });
    }

    // ── private helpers ─────────────────────────────────────────────────

    #findTriggers() {
        // Explicit name provided → exact match.
        if (this.triggerNameValue) {
            return Array.from(
                this.element.querySelectorAll(
                    `input[type="radio"][name="${this.triggerNameValue}"], input[type="checkbox"][name="${this.triggerNameValue}"]`
                )
            );
        }
        // Fallback: any radio/checkbox whose name ends with `[applicable]`.
        return Array.from(this.element.querySelectorAll('input[type="radio"], input[type="checkbox"]'))
            .filter((el) => /\[applicable\]$/.test(el.name || ''));
    }

    #isRequired() {
        // `requiredWhen` is the trigger-VALUE that activates required-state.
        // For Symfony boolean ChoiceType the values are "0" / "1". We accept
        // "false" / "true" / "0" / "1" interchangeably.
        const want = this.#normalize(this.requiredWhenValue);
        const checked = this.triggers.find((t) => t.checked);
        if (!checked) {
            return false;
        }
        return this.#normalize(checked.value) === want;
    }

    #normalize(v) {
        if (v === '1' || v === 'true') return 'true';
        if (v === '0' || v === 'false') return 'false';
        return String(v);
    }

    #applyState(field, isRequired) {
        // 1) aria-required on the input element itself
        field.setAttribute('aria-required', isRequired ? 'true' : 'false');

        // 2) red-* marker on the label
        const label = this.#findLabel(field);
        if (!label) return;

        let marker = label.querySelector('.fa-cyber-input__req[data-applicability-toggle-marker]');
        if (isRequired) {
            if (!marker) {
                marker = document.createElement('span');
                marker.className = 'fa-cyber-input__req';
                marker.setAttribute('aria-hidden', 'true');
                marker.setAttribute('data-applicability-toggle-marker', '');
                marker.textContent = '*';
                label.appendChild(document.createTextNode(' '));
                label.appendChild(marker);
            }
        } else if (marker) {
            marker.remove();
        }
    }

    #findLabel(field) {
        if (field.id) {
            const byFor = this.element.querySelector(`label[for="${field.id}"]`);
            if (byFor) return byFor;
        }
        const wrapper = field.closest('.mb-3, .form-group, .fa-cyber-input, [class*="col-md-"]');
        return wrapper ? wrapper.querySelector('label') : null;
    }
}
