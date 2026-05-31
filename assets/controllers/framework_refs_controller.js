import { Controller } from '@hotwired/stimulus';

/**
 * framework-refs controller
 *
 * Wraps the per-framework reference rows on the Control edit form
 * (ControlFrameworkReferencesType). Each row input is also wired to the
 * generic `tom-select` controller (create=true → free entry). This controller
 * seeds the TomSelect with the framework's catalogue of known requirement-ids
 * so the user can autocomplete instead of typing references blind.
 *
 * The catalogue is rendered into a `data-framework-refs-options` attribute on
 * each input as a JSON list of `{value, label}` objects (see
 * templates/form/control_framework_references.html.twig). TomSelect exposes
 * its instance on `element.tomselect`; we poll briefly because tom-select
 * initialises asynchronously on connect.
 *
 * Frameworks without a catalogue have no `data-framework-refs-options`
 * attribute — free entry via tom-select create=true still works.
 */
export default class extends Controller {
    connect() {
        this.inputs = Array.from(
            this.element.querySelectorAll('[data-framework-refs-options]')
        );
        if (this.inputs.length === 0) {
            return;
        }
        // tom-select boots asynchronously — retry a few times before giving up.
        this.attempts = 0;
        this.#seed();
    }

    disconnect() {
        if (this.timer) {
            window.clearTimeout(this.timer);
        }
    }

    #seed() {
        let allReady = true;
        this.inputs.forEach((input) => {
            const ts = input.tomselect;
            if (!ts) {
                allReady = false;
                return;
            }
            if (input.dataset.frameworkRefsSeeded === '1') {
                return;
            }
            let options = [];
            try {
                options = JSON.parse(input.getAttribute('data-framework-refs-options') || '[]');
            } catch (_) {
                options = [];
            }
            options.forEach((opt) => {
                if (opt && typeof opt.value === 'string') {
                    ts.addOption({ value: opt.value, text: opt.label || opt.value });
                }
            });
            ts.refreshOptions(false);
            input.dataset.frameworkRefsSeeded = '1';
        });

        if (!allReady && this.attempts < 20) {
            this.attempts += 1;
            this.timer = window.setTimeout(() => this.#seed(), 50);
        }
    }
}
