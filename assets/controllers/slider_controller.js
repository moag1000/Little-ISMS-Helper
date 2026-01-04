import { Controller } from '@hotwired/stimulus';

/**
 * Generic Slider Controller
 *
 * Syncs a range slider with value displays, inputs, progress bars, and presets.
 * Supports dynamic color variants, value labels, and bidirectional input sync.
 *
 * Usage with _slider.html.twig component - see component documentation for examples.
 *
 * Targets:
 * - range: The range input element
 * - value: Badge/span showing current value
 * - input: Hidden input for form submission
 * - numberInput: Optional number input for direct entry
 * - progress: Optional progress bar element
 * - label: Optional label element for value labels
 *
 * Values (data attributes):
 * - min: Minimum value
 * - max: Maximum value
 * - default: Default value for reset
 * - prefix: Value prefix (e.g., '€')
 * - suffix: Value suffix (e.g., '%')
 * - decimals: Number of decimal places
 * - thresholds: Object with danger/warning/success thresholds
 * - invert: Whether lower values are better
 * - labels: Object mapping values to display labels
 */
export default class extends Controller {
    static targets = ['range', 'value', 'input', 'numberInput', 'progress', 'label'];

    static values = {
        min: { type: Number, default: 0 },
        max: { type: Number, default: 100 },
        default: { type: Number, default: 0 },
        prefix: { type: String, default: '' },
        suffix: { type: String, default: '' },
        decimals: { type: Number, default: 0 },
        thresholds: { type: Object, default: null },
        invert: { type: String, default: 'false' },
        labels: { type: Object, default: null }
    };

    connect() {
        // Initial update to sync all elements
        this.update();
    }

    /**
     * Main update method - syncs all targets with current range value
     */
    update() {
        const rawValue = parseFloat(this.rangeTarget.value);
        const value = this.decimalsValue > 0 ? rawValue : Math.round(rawValue);

        // Update value display (badge)
        if (this.hasValueTarget) {
            this.updateValueDisplay(value);
        }

        // Update number input if present
        if (this.hasNumberInputTarget) {
            this.numberInputTarget.value = this.formatValue(value);
        }

        // Update hidden input
        if (this.hasInputTarget) {
            this.inputTarget.value = value;
        }

        // Update progress bar
        if (this.hasProgressTarget) {
            const percent = ((value - this.minValue) / (this.maxValue - this.minValue)) * 100;
            this.progressTarget.style.width = `${percent}%`;

            // Update progress bar color if thresholds are set
            if (this.hasThresholdsValue && this.thresholdsValue) {
                const variant = this.getVariant(value);
                this.progressTarget.className = this.progressTarget.className.replace(/bg-\w+/g, '');
                this.progressTarget.classList.add(`bg-${variant}`);
            }
        }

        // Update value label
        if (this.hasLabelTarget && this.hasLabelsValue && this.labelsValue) {
            const label = this.labelsValue[value] || this.labelsValue[String(value)] || '';
            this.labelTarget.textContent = label;
        }

        // Update ARIA attributes
        this.rangeTarget.setAttribute('aria-valuenow', value);

        // Update preset buttons active state
        this.updatePresetButtons(value);

        // Dispatch custom event
        this.dispatch('change', {
            detail: {
                value: value,
                formattedValue: this.formatValue(value),
                label: this.getLabel(value)
            },
            bubbles: true
        });
    }

    /**
     * Update from number input field
     */
    updateFromInput() {
        let value = parseFloat(this.numberInputTarget.value);

        if (isNaN(value)) {
            value = this.defaultValue;
        }

        this.rangeTarget.value = value;
        this.update();
    }

    /**
     * Clamp value to min/max on blur
     */
    clamp() {
        let value = parseFloat(this.numberInputTarget.value);

        if (isNaN(value)) {
            value = this.defaultValue;
        }

        // Clamp to min/max
        value = Math.max(this.minValue, Math.min(this.maxValue, value));

        this.rangeTarget.value = value;
        this.numberInputTarget.value = this.formatValue(value);
        this.update();
    }

    /**
     * Reset to default value
     */
    reset() {
        this.rangeTarget.value = this.defaultValue;
        this.update();
    }

    /**
     * Set to minimum value
     */
    setMin() {
        this.rangeTarget.value = this.minValue;
        this.update();
    }

    /**
     * Set to maximum value
     */
    setMax() {
        this.rangeTarget.value = this.maxValue;
        this.update();
    }

    /**
     * Set to middle value
     */
    setMid() {
        this.rangeTarget.value = (this.minValue + this.maxValue) / 2;
        this.update();
    }

    /**
     * Set to specific value (from preset button or event)
     */
    setValue(event) {
        const value = event.params?.value ?? event.target.dataset.value;
        if (value !== undefined) {
            this.rangeTarget.value = value;
            this.update();
        }
    }

    /**
     * Increment value by step
     */
    increment() {
        const step = parseFloat(this.rangeTarget.step) || 1;
        const newValue = Math.min(this.maxValue, parseFloat(this.rangeTarget.value) + step);
        this.rangeTarget.value = newValue;
        this.update();
    }

    /**
     * Decrement value by step
     */
    decrement() {
        const step = parseFloat(this.rangeTarget.step) || 1;
        const newValue = Math.max(this.minValue, parseFloat(this.rangeTarget.value) - step);
        this.rangeTarget.value = newValue;
        this.update();
    }

    // Private methods

    updateValueDisplay(value) {
        // Check if we should show a label instead of the numeric value
        if (this.hasLabelsValue && this.labelsValue) {
            const label = this.labelsValue[value] || this.labelsValue[String(value)];
            if (label) {
                this.valueTarget.textContent = label;
            } else {
                this.valueTarget.textContent = this.prefixValue + this.formatValue(value) + this.suffixValue;
            }
        } else {
            this.valueTarget.textContent = this.prefixValue + this.formatValue(value) + this.suffixValue;
        }

        // Update badge color if thresholds are set
        if (this.hasThresholdsValue && this.thresholdsValue) {
            const variant = this.getVariant(value);
            this.valueTarget.className = this.valueTarget.className.replace(/bg-\w+/g, '');
            this.valueTarget.classList.add('badge', `bg-${variant}`, 'fs-6');
        }
    }

    formatValue(value) {
        if (this.decimalsValue > 0) {
            return value.toFixed(this.decimalsValue);
        }
        return String(Math.round(value));
    }

    getLabel(value) {
        if (this.hasLabelsValue && this.labelsValue) {
            return this.labelsValue[value] || this.labelsValue[String(value)] || '';
        }
        return '';
    }

    getVariant(value) {
        const thresholds = this.thresholdsValue;
        const invert = this.invertValue === 'true';

        if (invert) {
            // Lower is better (e.g., risk scores)
            if (value <= thresholds.success) return 'success';
            if (value <= thresholds.warning) return 'warning';
            if (value <= thresholds.danger) return 'info';
            return 'danger';
        } else {
            // Higher is better (e.g., compliance scores)
            if (value >= thresholds.success) return 'success';
            if (value >= thresholds.warning) return 'warning';
            if (value >= thresholds.danger) return 'info';
            return 'danger';
        }
    }

    updatePresetButtons(value) {
        // Find all preset buttons and update their active state
        this.element.querySelectorAll('[data-action*="setValue"]').forEach(button => {
            const presetValue = parseFloat(button.dataset.sliderValueParam);
            if (presetValue === value) {
                button.classList.add('active');
            } else {
                button.classList.remove('active');
            }
        });
    }
}
