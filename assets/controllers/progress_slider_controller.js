import { Controller } from '@hotwired/stimulus';

/**
 * Progress Slider Controller
 *
 * Syncs a range slider with a percentage display and progress bar.
 *
 * Usage:
 * <div data-controller="progress-slider">
 *     <input type="range" data-progress-slider-target="slider" data-action="input->progress-slider#update">
 *     <span data-progress-slider-target="display">0%</span>
 *     <div class="progress">
 *         <div data-progress-slider-target="bar" class="progress-bar" style="width: 0%">0%</div>
 *     </div>
 * </div>
 */
export default class extends Controller {
    static targets = ['slider', 'display', 'bar'];

    update() {
        const value = this.sliderTarget.value;

        if (this.hasDisplayTarget) {
            this.displayTarget.textContent = value + '%';
        }

        if (this.hasBarTarget) {
            this.barTarget.style.width = value + '%';
            this.barTarget.textContent = value + '%';
        }
    }
}
