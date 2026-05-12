/**
 * F4 Evidence-Versioning — 5-second undo toast controller.
 *
 * Counts down from `windowValue` seconds. When the countdown reaches 0 the
 * toast is hidden and the undo window has passed. If the user clicks "Cancel"
 * (the undo button), the form is submitted normally.
 *
 * Usage:
 *   data-controller="evidence-undo"
 *   data-evidence-undo-version-id-value="42"
 *   data-evidence-undo-window-value="5"
 *
 * Targets:
 *   countdown — <span> showing remaining seconds
 *   form      — <form> submitted to undo endpoint
 *   cancelBtn — <button> inside the form (submit = undo)
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        versionId: Number,
        window: { type: Number, default: 5 },
    };

    static targets = ['countdown', 'form', 'cancelBtn'];

    connect() {
        this._remaining = this.windowValue;
        this._interval = setInterval(() => this._tick(), 1000);
    }

    disconnect() {
        clearInterval(this._interval);
    }

    _tick() {
        this._remaining -= 1;
        if (this.hasCountdownTarget) {
            this.countdownTarget.textContent = String(this._remaining);
        }
        if (this._remaining <= 0) {
            clearInterval(this._interval);
            this._hide();
        }
    }

    _hide() {
        // Remove the entire toast stack from DOM
        this.element.remove();
    }

    /** Called when the undo button is clicked — let the form POST proceed. */
    cancel(event) {
        clearInterval(this._interval);
        // Native form submission — no JS needed, browser handles it
    }
}
