import { Controller } from '@hotwired/stimulus';

/**
 * GDPR Data Breach Assessment Wizard Controller
 *
 * Multi-step wizard to guide non-technical users through GDPR Art. 33 assessment.
 * Determines if a data breach is reportable within 72 hours.
 *
 * Features:
 * - 4-step wizard with progress tracking
 * - Form state persistence via localStorage
 * - Automatic risk calculation
 * - Pre-fills incident form with results
 * - WCAG 2.1 AA keyboard navigation
 *
 * Usage:
 * <div data-controller="gdpr-breach-wizard">
 *     <button data-action="click->gdpr-breach-wizard#open">Start Wizard</button>
 * </div>
 */
export default class extends Controller {
    static targets = [
        'modal',
        'backdrop',
        'step1', 'step2', 'step3', 'step4',
        'progressBar',
        'progressText',
        'nextBtn',
        'backBtn',
        'personalDataYes', 'personalDataNo',
        'dataTypeCheckbox',
        'scaleRadio',
        'resultRiskLevel',
        'resultReportable',
        'resultRecommendation',
        'resultDeadline',
        'completeBtn'
    ];

    static values = {
        assessmentUrl: String
    };

    connect() {
        this.currentStep = 1;
        this.totalSteps = 4;
        this.wizardData = this.loadFromLocalStorage() || {
            personalDataInvolved: null,
            dataTypes: [],
            scale: null,
            assessment: null
        };

        // Load translations from DOM
        this.loadTranslations();

        // Bind keyboard handler
        this.boundHandleEscape = this.handleEscape.bind(this);

        // Connect external trigger button (outside controller scope)
        this.boundOpen = this.open.bind(this);
        const triggerBtn = document.getElementById('gdpr-wizard-trigger-btn');
        if (triggerBtn) {
            triggerBtn.addEventListener('click', this.boundOpen);
        }
    }

    /**
     * Load translations from DOM data attribute
     */
    loadTranslations() {
        const wizardElement = document.querySelector('.gdpr-wizard');
        if (wizardElement && wizardElement.dataset.translations) {
            try {
                this.translations = JSON.parse(wizardElement.dataset.translations);
            } catch (e) {
                this.translations = {};
            }
        } else {
            this.translations = {};
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleEscape);

        // Cleanup external trigger button listener
        const triggerBtn = document.getElementById('gdpr-wizard-trigger-btn');
        if (triggerBtn && this.boundOpen) {
            triggerBtn.removeEventListener('click', this.boundOpen);
        }
    }

    /**
     * Open wizard modal
     */
    open(event) {
        if (event) event.preventDefault();

        // Reset wizard to step 1
        this.currentStep = 1;
        this.showStep(1);
        this.updateProgress();

        // Show modal
        this.modalTarget.classList.add('modal-open');
        this.backdropTarget.classList.add('modal-backdrop-show');
        document.body.style.overflow = 'hidden';

        // Enable ESC key handler
        document.addEventListener('keydown', this.boundHandleEscape);

        // Focus first element
        requestAnimationFrame(() => {
            const firstFocusable = this.modalTarget.querySelector('input, button');
            if (firstFocusable) firstFocusable.focus();
        });
    }

    /**
     * Close wizard modal
     */
    close(event) {
        if (event) event.preventDefault();

        this.modalTarget.classList.remove('modal-open');
        this.backdropTarget.classList.remove('modal-backdrop-show');
        document.body.style.overflow = '';

        document.removeEventListener('keydown', this.boundHandleEscape);
    }

    /**
     * Handle ESC key to close modal
     */
    handleEscape(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
        }
    }

    /**
     * Go to next step
     */
    next(event) {
        if (event) event.preventDefault();

        // Validate current step
        if (!this.validateStep(this.currentStep)) {
            return;
        }

        // Save current step data
        this.saveStepData(this.currentStep);

        // Check for early exit (Step 1: No personal data)
        if (this.currentStep === 1 && this.wizardData.personalDataInvolved === false) {
            this.showNotGdprBreach();
            return;
        }

        // Move to next step
        if (this.currentStep < this.totalSteps) {
            this.currentStep++;
            this.showStep(this.currentStep);
            this.updateProgress();

            // If step 4, calculate results
            if (this.currentStep === 4) {
                this.calculateResults();
            }
        }
    }

    /**
     * Go to previous step
     */
    back(event) {
        if (event) event.preventDefault();

        if (this.currentStep > 1) {
            this.currentStep--;
            this.showStep(this.currentStep);
            this.updateProgress();
        }
    }

    /**
     * Show specific step
     */
    showStep(stepNumber) {
        // Hide all steps
        [this.step1Target, this.step2Target, this.step3Target, this.step4Target].forEach(step => {
            step.classList.add('d-none');
        });

        // Show current step
        const stepTargets = [null, this.step1Target, this.step2Target, this.step3Target, this.step4Target];
        stepTargets[stepNumber]?.classList.remove('d-none');

        // Update navigation buttons
        this.backBtnTarget.classList.toggle('d-none', stepNumber === 1);
        this.nextBtnTarget.classList.toggle('d-none', stepNumber === 4);
        this.completeBtnTarget.classList.toggle('d-none', stepNumber !== 4);
    }

    /**
     * Update progress bar and text
     */
    updateProgress() {
        const percentage = (this.currentStep / this.totalSteps) * 100;
        this.progressBarTarget.style.width = `${percentage}%`;
        this.progressBarTarget.setAttribute('aria-valuenow', percentage);
        const stepOf = this.translations.step_of || 'of';
        this.progressTextTarget.textContent = `${this.currentStep} ${stepOf} ${this.totalSteps}`;
    }

    /**
     * Validate current step
     */
    validateStep(stepNumber) {
        switch (stepNumber) {
            case 1:
                // Personal data involvement must be selected
                return this.personalDataYesTarget.checked || this.personalDataNoTarget.checked;
            case 2:
                // At least one data type must be selected - check checkboxes directly
                const checkedTypes = this.dataTypeCheckboxTargets.filter(cb => cb.checked);
                return checkedTypes.length > 0;
            case 3:
                // Scale must be selected - check radio buttons directly
                const selectedScale = this.scaleRadioTargets.find(radio => radio.checked);
                return selectedScale !== undefined;
            default:
                return true;
        }
    }

    /**
     * Save current step data to wizardData object
     */
    saveStepData(stepNumber) {
        switch (stepNumber) {
            case 1:
                this.wizardData.personalDataInvolved = this.personalDataYesTarget.checked;
                break;
            case 2:
                this.wizardData.dataTypes = Array.from(this.dataTypeCheckboxTargets)
                    .filter(cb => cb.checked)
                    .map(cb => cb.value);
                break;
            case 3:
                const selectedScale = this.scaleRadioTargets.find(radio => radio.checked);
                this.wizardData.scale = selectedScale ? selectedScale.value : null;
                break;
        }

        this.saveToLocalStorage();
    }

    /**
     * Show "Not a GDPR Breach" message and exit
     */
    showNotGdprBreach() {
        alert(window.translations?.gdpr?.not_a_breach || 'This is NOT a GDPR breach. Personal data is not involved.');
        this.clearLocalStorage();
        this.close();
    }

    /**
     * Calculate risk assessment results
     */
    async calculateResults() {
        try {
            const response = await fetch(this.assessmentUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    dataTypes: this.wizardData.dataTypes,
                    scale: this.wizardData.scale
                })
            });

            if (!response.ok) {
                throw new Error('Assessment calculation failed');
            }

            const assessment = await response.json();
            this.wizardData.assessment = assessment;
            this.saveToLocalStorage();

            this.displayResults(assessment);
        } catch (error) {
            alert(window.translations?.gdpr?.error_calculating || 'Error calculating risk assessment. Please try again.');
        }
    }

    /**
     * Display results in Step 4
     */
    displayResults(assessment) {
        // Risk level with color coding
        const riskLevelText = this.getRiskLevelTranslation(assessment.risk_level);
        const riskLevelClass = this.getRiskLevelClass(assessment.risk_level);
        this.resultRiskLevelTarget.textContent = riskLevelText;
        this.resultRiskLevelTarget.className = `badge ${riskLevelClass} fs-5`;

        // Reportable status (translated)
        const yesText = this.translations.yes_reportable || 'YES - Reportable to Supervisory Authority';
        const noText = this.translations.no_not_reportable || 'NO - Not Reportable';
        const reportableText = assessment.is_reportable ? yesText : noText;
        const reportableBadge = assessment.is_reportable ? 'bg-danger' : 'bg-success';
        this.resultReportableTarget.textContent = reportableText;
        this.resultReportableTarget.className = `badge ${reportableBadge} fs-6`;

        // Recommendation
        const recommendationText = this.getRecommendationTranslation(assessment.recommendation);
        this.resultRecommendationTarget.textContent = recommendationText;

        // 72h deadline (if reportable)
        if (assessment.is_reportable) {
            const deadline = new Date();
            deadline.setHours(deadline.getHours() + 72);
            this.resultDeadlineTarget.textContent = deadline.toLocaleString();
            this.resultDeadlineTarget.parentElement.parentElement.classList.remove('d-none');
        } else {
            this.resultDeadlineTarget.parentElement.parentElement.classList.add('d-none');
        }
    }

    /**
     * Complete wizard and pre-fill incident form
     */
    completeWizard(event) {
        if (event) event.preventDefault();

        const assessment = this.wizardData.assessment;
        if (!assessment) {
            return;
        }

        // Pre-fill incident form fields
        this.prefillIncidentForm(assessment);

        // Clear localStorage
        this.clearLocalStorage();

        // Close wizard
        this.close();

        // Show success message
        this.showSuccessMessage();
    }

    /**
     * Pre-fill incident form with wizard results
     */
    prefillIncidentForm(assessment) {
        // Set severity based on risk level
        const severityField = document.getElementById('incident_severity');
        if (severityField) {
            const severityMap = {
                'very_high': 'critical',
                'high': 'high',
                'medium': 'medium',
                'low': 'low'
            };
            severityField.value = severityMap[assessment.risk_level] || 'medium';
            severityField.dispatchEvent(new Event('change'));
        }

        // Set category to data_breach
        const categoryField = document.getElementById('incident_category');
        if (categoryField) {
            categoryField.value = 'data_breach';
            categoryField.dispatchEvent(new Event('change'));
        }

        // Set affected users count
        const affectedUsersField = document.getElementById('incident_affectedUsersCount');
        if (affectedUsersField && this.wizardData.scale) {
            const scaleMap = {
                'under_100': 50,
                '100_to_1000': 550,
                '1001_to_10000': 5500,
                'over_10000': 15000
            };
            affectedUsersField.value = scaleMap[this.wizardData.scale] || 0;
        }

        // Add note to description
        const descriptionField = document.getElementById('incident_description');
        if (descriptionField && !descriptionField.value) {
            const riskText = this.getRiskLevelTranslation(assessment.risk_level);
            const reportableText = assessment.is_reportable ? 'REPORTABLE under GDPR Art. 33' : 'Not reportable';
            descriptionField.value = `GDPR Breach Assessment:\n- Risk Level: ${riskText}\n- ${reportableText}\n- Score: ${assessment.score}\n\n`;
        }
    }

    /**
     * Show success message
     */
    showSuccessMessage() {
        const message = document.createElement('div');
        message.className = 'alert alert-success alert-dismissible fade show';
        message.setAttribute('role', 'alert');
        message.innerHTML = `
            <strong>GDPR Assessment Complete!</strong> Incident form has been pre-filled with assessment results.
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        `;

        const container = document.querySelector('.container-fluid');
        if (container) {
            container.insertBefore(message, container.firstChild);
            setTimeout(() => message.remove(), 5000);
        }
    }

    /**
     * Get risk level translation
     */
    getRiskLevelTranslation(level) {
        const key = `risk_${level}`;
        return this.translations[key] || level;
    }

    /**
     * Get risk level CSS class
     */
    getRiskLevelClass(level) {
        const classes = {
            'low': 'bg-success',
            'medium': 'bg-warning text-dark',
            'high': 'bg-danger',
            'very_high': 'bg-dark'
        };
        return classes[level] || 'bg-secondary';
    }

    /**
     * Get recommendation translation
     */
    getRecommendationTranslation(recommendation) {
        const key = `recommendation_${recommendation}`;
        return this.translations[key] || recommendation;
    }

    /**
     * Save wizard data to localStorage with TTL
     */
    saveToLocalStorage() {
        try {
            const data = {
                ...this.wizardData,
                _timestamp: Date.now(),
                _ttl: 30 * 60 * 1000 // 30 minutes TTL
            };
            localStorage.setItem('gdpr_wizard_data', JSON.stringify(data));
        } catch (error) {
        }
    }

    /**
     * Load wizard data from localStorage with TTL check
     */
    loadFromLocalStorage() {
        try {
            const stored = localStorage.getItem('gdpr_wizard_data');
            if (!stored) return null;

            const data = JSON.parse(stored);

            // Check if data has expired
            if (data._timestamp && data._ttl) {
                const isExpired = Date.now() - data._timestamp > data._ttl;
                if (isExpired) {
                    this.clearLocalStorage();
                    return null;
                }
            }

            // Remove metadata before returning
            delete data._timestamp;
            delete data._ttl;

            return data;
        } catch (error) {
            return null;
        }
    }

    /**
     * Clear localStorage
     */
    clearLocalStorage() {
        try {
            localStorage.removeItem('gdpr_wizard_data');
        } catch (error) {
        }
    }
}
