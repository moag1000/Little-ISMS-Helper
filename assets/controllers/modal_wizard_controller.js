import { Controller } from '@hotwired/stimulus';

/**
 * ModalWizard Controller — High-stakes linear-flow modal with validation-gates
 *
 * Responsibilities:
 *   1. Step navigation: Back/Next buttons toggle .is-active on steps and
 *      pip states (.is-current / .is-done)
 *   2. Validation gate: before Next, scan current step's [required] inputs —
 *      if any empty, prevent Next + show validation hint + mark step
 *   3. Final submit: on last step, swap Next for Submit; on submit-click,
 *      call form.requestSubmit() on the nearest enclosing <form>
 *   4. Cancel confirmation: if any input has changed, show browser confirm()
 *      before dismissing
 *   5. Pip-rail clickable: clicking a done pip jumps back (forward requires
 *      validation chain)
 *   6. Focus management: focus first focusable element in each new step
 *   7. Change-tracking: mark hasChanges when any input in the wizard fires
 *      an input/change event
 *
 * Values:
 *   currentStep  Number   (default: 0)   — 0-based index of active step
 *   totalSteps   Number   (required)     — total number of steps
 *   hasChanges   Boolean  (default: false) — dirty-state for cancel guard
 *
 * Targets:
 *   step           — .fa-modal__wizard-step elements (multiple)
 *   pip            — .fa-modal__wizard-pip elements (multiple)
 *   backBtn        — Back button
 *   nextBtn        — Next button
 *   submitBtn      — Submit button
 *   cancelBtn      — Cancel button
 *   closeBtn       — Header close button (alias for cancel)
 *   validationHint — .fa-modal__wizard-validation banners (one per step)
 *   stepCounter    — inline "X of Y" counter text node
 */
export default class extends Controller {
    static targets = [
        'step',
        'pip',
        'backBtn',
        'nextBtn',
        'submitBtn',
        'cancelBtn',
        'closeBtn',
        'validationHint',
        'stepCounter',
    ];

    static values = {
        currentStep: { type: Number, default: 0 },
        totalSteps: { type: Number, default: 0 },
        hasChanges: { type: Boolean, default: false },
    };

    // ── Lifecycle ──────────────────────────────────────────────────────────

    connect() {
        this._boundOnInput = this._onInput.bind(this);
        this._attachInputListeners();
        this._syncUI(this.currentStepValue);
    }

    disconnect() {
        this._detachInputListeners();
    }

    // ── Navigation actions ─────────────────────────────────────────────────

    /**
     * Advance to the next step after validating the current one.
     */
    next() {
        const currentIdx = this.currentStepValue;

        if (!this._validateStep(currentIdx)) {
            return; // validation banner shown by _validateStep
        }

        const nextIdx = currentIdx + 1;
        if (nextIdx >= this.totalStepsValue) {
            return;
        }

        this._goToStep(nextIdx);
    }

    /**
     * Go back to the previous step (no validation required).
     */
    back() {
        const prevIdx = this.currentStepValue - 1;
        if (prevIdx < 0) {
            return;
        }

        // Clear any validation error on current step before moving back
        this._clearValidationError(this.currentStepValue);

        this._goToStep(prevIdx);
    }

    /**
     * Jump to a past (done) pip — called by pip click.
     */
    jumpToPip(event) {
        const pip = event.currentTarget;
        const targetIdx = parseInt(pip.dataset.stepIndex, 10);

        if (isNaN(targetIdx)) {
            return;
        }

        // Only allow jumping backwards
        if (targetIdx >= this.currentStepValue) {
            return;
        }

        // Clear validation error on current step
        this._clearValidationError(this.currentStepValue);

        this._goToStep(targetIdx);
    }

    /**
     * Submit: call requestSubmit() on the wrapping form, or dispatch a
     * custom event for non-form use-cases.
     */
    submit(event) {
        event.preventDefault();

        const lastIdx = this.totalStepsValue - 1;
        if (!this._validateStep(lastIdx)) {
            return;
        }

        const form = this.element.closest('form');
        if (form) {
            form.requestSubmit();
        } else {
            // No form — dispatch custom event for controller-handled modals
            this.element.dispatchEvent(
                new CustomEvent('modal-wizard:submit', {
                    bubbles: true,
                    detail: { wizardId: this.element.id },
                }),
            );
        }
    }

    /**
     * Cancel: guard against unsaved changes, then close/navigate.
     */
    cancel() {
        if (this.hasChangesValue) {
            const confirmed = window.confirm(
                'Sind Sie sicher? Eingaben gehen verloren.',
            );
            if (!confirmed) {
                return;
            }
        }

        this._dispatchCancel();
    }

    // ── Value change callbacks ─────────────────────────────────────────────

    currentStepValueChanged(value) {
        this._syncUI(value);
    }

    // ── Private helpers ────────────────────────────────────────────────────

    /**
     * Navigate to a specific step index.
     *
     * @param {number} targetIdx
     */
    _goToStep(targetIdx) {
        const prevIdx = this.currentStepValue;

        // Mark previous step as done in the pip-rail (if moving forward)
        if (targetIdx > prevIdx) {
            this._markPipDone(prevIdx);
        }

        // Deactivate previous step
        const prevStep = this.stepTargets[prevIdx];
        if (prevStep) {
            prevStep.classList.remove('is-active');
        }

        // Activate new step
        const newStep = this.stepTargets[targetIdx];
        if (newStep) {
            newStep.classList.add('is-active');
        }

        this.currentStepValue = targetIdx;

        // Focus first focusable element in new step after transition
        requestAnimationFrame(() => {
            if (!newStep) return;
            const focusable = newStep.querySelector(
                'input:not([type="hidden"]):not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])',
            );
            if (focusable) {
                focusable.focus({ preventScroll: true });
            }
        });
    }

    /**
     * Update pip states, button visibility, and step counter.
     *
     * @param {number} currentIdx
     */
    _syncUI(currentIdx) {
        const total = this.totalStepsValue;

        // ── Pip states ──────────────────────────────────────────────────
        this.pipTargets.forEach((pip, idx) => {
            pip.classList.remove(
                'fa-modal__wizard-pip--current',
                'fa-modal__wizard-pip--done',
                'fa-modal__wizard-pip--pending',
                'fa-modal__wizard-pip--error',
            );

            if (idx < currentIdx) {
                pip.classList.add('fa-modal__wizard-pip--done');
                pip.setAttribute('tabindex', '0');
                pip.removeAttribute('aria-current');
                // Ensure click action is present for done pips
                if (!pip.dataset.action || !pip.dataset.action.includes('jumpToPip')) {
                    pip.dataset.action = `click->modal-wizard#jumpToPip`;
                }
            } else if (idx === currentIdx) {
                pip.classList.add('fa-modal__wizard-pip--current');
                pip.setAttribute('aria-current', 'step');
                pip.removeAttribute('tabindex');
            } else {
                pip.classList.add('fa-modal__wizard-pip--pending');
                pip.removeAttribute('aria-current');
                pip.removeAttribute('tabindex');
            }

            // Update pip number label (done → check icon, otherwise number)
            const numEl = pip.querySelector('.fa-modal__wizard-pip-num');
            if (numEl) {
                if (idx < currentIdx) {
                    numEl.innerHTML = '<i class="fa-icon fa-icon--ui-check" aria-hidden="true"></i>';
                } else {
                    numEl.textContent = String(idx + 1);
                }
            }
        });

        // ── Button visibility ───────────────────────────────────────────
        const isFirst = currentIdx === 0;
        const isLast = currentIdx === total - 1;

        if (this.hasBackBtnTarget) {
            this.backBtnTarget.hidden = isFirst;
        }

        if (this.hasNextBtnTarget) {
            this.nextBtnTarget.hidden = isLast;
        }

        if (this.hasSubmitBtnTarget) {
            this.submitBtnTarget.hidden = !isLast;
        }

        // ── Step counter ────────────────────────────────────────────────
        if (this.hasStepCounterTarget) {
            this.stepCounterTarget.textContent = String(currentIdx + 1);
        }
    }

    /**
     * Mark a pip as done (filled primary bar + check icon).
     *
     * @param {number} idx
     */
    _markPipDone(idx) {
        const pip = this.pipTargets[idx];
        if (!pip) return;

        pip.classList.remove('fa-modal__wizard-pip--current', 'fa-modal__wizard-pip--pending');
        pip.classList.add('fa-modal__wizard-pip--done');
    }

    /**
     * Validate the current step.
     * Shows the validation banner and adds .has-validation-error if invalid.
     *
     * @param {number} stepIdx
     * @returns {boolean}
     */
    _validateStep(stepIdx) {
        const step = this.stepTargets[stepIdx];
        if (!step) return true;

        // Skip validation if step is not marked as required
        const isRequired = step.dataset.stepRequired === 'true';
        if (!isRequired) {
            this._clearValidationError(stepIdx);
            return true;
        }

        // Check all required inputs within this step
        const requiredInputs = Array.from(
            step.querySelectorAll(
                'input[required]:not([type="hidden"]):not([disabled]), ' +
                'select[required]:not([disabled]), ' +
                'textarea[required]:not([disabled])',
            ),
        );

        const hasEmpty = requiredInputs.some((input) => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                // For radio groups, at least one must be checked
                if (input.type === 'radio') {
                    const group = step.querySelectorAll(
                        `input[type="radio"][name="${CSS.escape(input.name)}"]`,
                    );
                    return !Array.from(group).some((r) => r.checked);
                }
                return !input.checked;
            }
            return String(input.value).trim() === '';
        });

        if (hasEmpty) {
            this._showValidationError(step);
            return false;
        }

        this._clearValidationError(stepIdx);
        return true;
    }

    /**
     * Show the validation error state for a step.
     *
     * @param {HTMLElement} stepEl
     */
    _showValidationError(stepEl) {
        stepEl.classList.add('has-validation-error');

        const hint = stepEl.querySelector('.fa-modal__wizard-validation');
        if (hint) {
            hint.classList.add('is-visible');
        }

        // Mark corresponding pip as error
        const stepIdx = parseInt(stepEl.dataset.stepIndex, 10);
        const pip = this.pipTargets[stepIdx];
        if (pip) {
            pip.classList.remove('fa-modal__wizard-pip--current');
            pip.classList.add('fa-modal__wizard-pip--error');
            const bar = pip.querySelector('.fa-modal__wizard-pip-bar');
            if (bar) bar.style.background = 'var(--danger)';
        }

        // Focus first invalid input
        const firstInvalid = stepEl.querySelector(':invalid, input[required][value=""]');
        if (firstInvalid) {
            firstInvalid.focus({ preventScroll: false });
        }
    }

    /**
     * Clear the validation error state for a step.
     *
     * @param {number} stepIdx
     */
    _clearValidationError(stepIdx) {
        const step = this.stepTargets[stepIdx];
        if (!step) return;

        step.classList.remove('has-validation-error');

        const hint = step.querySelector('.fa-modal__wizard-validation');
        if (hint) {
            hint.classList.remove('is-visible');
        }

        // Restore pip state
        const pip = this.pipTargets[stepIdx];
        if (pip && pip.classList.contains('fa-modal__wizard-pip--error')) {
            pip.classList.remove('fa-modal__wizard-pip--error');
            // Restore to current or done based on index vs currentStepValue
            if (stepIdx < this.currentStepValue) {
                pip.classList.add('fa-modal__wizard-pip--done');
            } else {
                pip.classList.add('fa-modal__wizard-pip--current');
            }
            const bar = pip.querySelector('.fa-modal__wizard-pip-bar');
            if (bar) bar.style.background = '';
        }
    }

    /**
     * Dispatch a cancel event and attempt to close via Bootstrap Modal if available.
     */
    _dispatchCancel() {
        this.element.dispatchEvent(
            new CustomEvent('modal-wizard:cancel', {
                bubbles: true,
                detail: { wizardId: this.element.id },
            }),
        );

        // Attempt Bootstrap Modal close if we are inside one
        const bsModalEl = this.element.closest('.modal');
        if (bsModalEl && window.bootstrap && window.bootstrap.Modal) {
            const instance = window.bootstrap.Modal.getInstance(bsModalEl);
            if (instance) {
                instance.hide();
                return;
            }
        }

        // CSS-only fallback: hide the wizard element itself
        this.element.hidden = true;
    }

    // ── Change tracking ────────────────────────────────────────────────────

    _attachInputListeners() {
        this.element.addEventListener('input', this._boundOnInput);
        this.element.addEventListener('change', this._boundOnInput);
    }

    _detachInputListeners() {
        this.element.removeEventListener('input', this._boundOnInput);
        this.element.removeEventListener('change', this._boundOnInput);
    }

    _onInput(event) {
        const tag = event.target.tagName.toLowerCase();
        if (['input', 'select', 'textarea'].includes(tag)) {
            this.hasChangesValue = true;
        }
    }
}
