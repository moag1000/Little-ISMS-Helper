import { Controller } from '@hotwired/stimulus';

/**
 * Generic Slider Controller
 *
 * Syncs a range slider with a value display badge and hidden input.
 * Supports dynamic color variants based on value thresholds.
 *
 * Usage with _slider.html.twig component:
 * {% include '_components/_slider.html.twig' with {
 *     name: 'score',
 *     value: 75,
 *     label: 'Score',
 *     value_suffix: '%',
 *     dynamic_variant: true
 * } %}
 *
 * Manual usage:
 * <div data-controller="slider" data-slider-thresholds-value='{"danger":25,"warning":50,"success":75}'>
 *     <input type="range" data-slider-target="range" data-action="input->slider#update">
 *     <span class="badge" data-slider-target="value">50%</span>
 *     <input type="hidden" data-slider-target="input">
 * </div>
 */
export default class extends Controller {
    static targets = ['range', 'value', 'input'];

    static values = {
        thresholds: { type: Object, default: null },
        prefix: { type: String, default: '' },
        suffix: { type: String, default: '' }
    };

    connect() {
        // Initial update to sync all elements
        this.update();
    }

    update() {
        const value = parseInt(this.rangeTarget.value, 10);

        // Update value display
        if (this.hasValueTarget) {
            this.valueTarget.textContent = this.prefixValue + value + this.suffixValue;

            // Update badge color based on thresholds
            if (this.hasThresholdsValue && this.thresholdsValue) {
                this.updateVariant(value);
            }
        }

        // Update hidden input
        if (this.hasInputTarget) {
            this.inputTarget.value = value;
        }

        // Update ARIA attributes
        this.rangeTarget.setAttribute('aria-valuenow', value);

        // Dispatch custom event for other components to listen to
        this.dispatch('change', {
            detail: { value: value },
            bubbles: true
        });
    }

    updateVariant(value) {
        const thresholds = this.thresholdsValue;
        let variant;

        if (value >= thresholds.success) {
            variant = 'success';
        } else if (value >= thresholds.warning) {
            variant = 'warning';
        } else if (value >= thresholds.danger) {
            variant = 'info';
        } else {
            variant = 'danger';
        }

        // Remove existing bg-* classes and add new one
        const badge = this.valueTarget;
        badge.className = badge.className.replace(/bg-\w+/g, '');
        badge.classList.add('badge', `bg-${variant}`, 'fs-6');
    }

    // Preset methods for quick value setting
    setMin() {
        this.rangeTarget.value = this.rangeTarget.min;
        this.update();
    }

    setMax() {
        this.rangeTarget.value = this.rangeTarget.max;
        this.update();
    }

    setMid() {
        const min = parseInt(this.rangeTarget.min, 10);
        const max = parseInt(this.rangeTarget.max, 10);
        this.rangeTarget.value = Math.floor((min + max) / 2);
        this.update();
    }

    setValue(event) {
        const value = event.params?.value || event.target.dataset.value;
        if (value !== undefined) {
            this.rangeTarget.value = value;
            this.update();
        }
    }
}
