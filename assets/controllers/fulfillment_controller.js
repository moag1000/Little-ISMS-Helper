import { Controller } from '@hotwired/stimulus';

/**
 * Fulfillment Controller - Quick percentage setter for compliance requirements
 *
 * Usage:
 * <div data-controller="fulfillment">
 *     <input type="range" data-fulfillment-target="slider" data-action="input->fulfillment#update">
 *     <span data-fulfillment-target="display"></span>
 *     <div data-fulfillment-target="progressBar"></div>
 *     <button data-action="click->fulfillment#set" data-fulfillment-value-param="0">0%</button>
 *     <button data-action="click->fulfillment#set" data-fulfillment-value-param="100">100%</button>
 * </div>
 */
export default class extends Controller {
    static targets = ['slider', 'display', 'progressBar'];

    set(event) {
        event.preventDefault();
        const value = parseInt(event.params.value, 10);

        if (this.hasSliderTarget) {
            this.sliderTarget.value = value;
            // Trigger input event to update other elements
            this.sliderTarget.dispatchEvent(new Event('input', { bubbles: true }));
        }

        this.updateDisplay(value);
        this.updateProgressBar(value);
    }

    update() {
        if (this.hasSliderTarget) {
            const value = parseInt(this.sliderTarget.value, 10);
            this.updateDisplay(value);
            this.updateProgressBar(value);
        }
    }

    updateDisplay(value) {
        if (this.hasDisplayTarget) {
            this.displayTarget.textContent = value + '%';
        }
    }

    updateProgressBar(value) {
        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = value + '%';

            // Update color based on value
            this.progressBarTarget.style.backgroundColor =
                value >= 75 ? 'var(--success)' : (value >= 50 ? 'var(--warning)' : 'var(--danger)');
        }
    }
}
