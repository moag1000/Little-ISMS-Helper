import { Application } from '@hotwired/stimulus';

const started = [];

/**
 * Mount one Stimulus controller on a fixture and wait for connect().
 *
 * Stimulus connects asynchronously (MutationObserver / microtask), so we yield
 * a macrotask after starting the application before returning.
 *
 * IMPORTANT: call stopAll() in an afterEach — otherwise the application's
 * MutationObserver fires during environment teardown (when jsdom globals like
 * `Node` are gone) and throws ReferenceError.
 *
 * @param {string} identifier  e.g. 'fa-modal'
 * @param {Function} ControllerClass  the controller module's default export
 * @param {string} html  fixture markup with data-controller="<identifier>"
 * @returns {Promise<{application, element, controller}>}
 */
export async function mount(identifier, ControllerClass, html) {
    document.body.innerHTML = html;
    const application = Application.start();
    application.register(identifier, ControllerClass);
    started.push(application);
    await tick();
    const element = document.querySelector(`[data-controller~="${identifier}"]`);
    const controller = application.getControllerForElementAndIdentifier(element, identifier);
    return { application, element, controller };
}

/** Stop every application started this test + clear the fixture. Use in afterEach. */
export function stopAll() {
    while (started.length) {
        try { started.pop().stop(); } catch (_) { /* already stopped */ }
    }
    document.body.innerHTML = '';
}

/** Resolve after a macrotask so Stimulus lifecycle + RAFs flush. */
export function tick() {
    return new Promise((resolve) => setTimeout(resolve, 0));
}
