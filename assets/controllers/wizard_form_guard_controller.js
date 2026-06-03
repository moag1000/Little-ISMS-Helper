import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard step-form submit guard.
 *
 * Native HTML5 constraint validation has a sharp edge: when a required field is
 * invalid AND not focusable (off-screen, inside a long inventory table, or a
 * collapsed block) the browser blocks the POST but cannot show its bubble — so
 * the "Weiter" button silently does nothing. Users (rightly) read that as
 * "the button is broken".
 *
 * This guard makes the button ALWAYS respond AND always show WHAT is missing:
 *   1. The host form carries `novalidate`, so the native silent block never
 *      fires and our `submit` handler always runs.
 *   2. On submit we collect every empty `required` control ourselves. If all
 *      are filled → the native POST proceeds.
 *   3. If any are missing → we DON'T submit. Instead every offender is
 *      highlighted (`.is-invalid`), the first is scrolled into view + focused,
 *      and a toast names how many fields need attention. Nothing is hidden or
 *      silently auto-filled — the user sees exactly what to decide.
 *
 * Not a fully reactive form (that would validate inline), but robust: the
 * button either submits or shows the user precisely what's blocking it.
 */
export default class extends Controller {
    connect() {
        this.#onSubmit = this.#onSubmit.bind(this);
        this.element.addEventListener('submit', this.#onSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.#onSubmit);
    }

    #onSubmit(event) {
        // `:invalid` still reflects per-element constraint validation even though
        // the form carries `novalidate` (novalidate only suppresses the form's
        // own silent block). So this catches required-empty AND format errors
        // (email/pattern/min/max) — everything the browser would have blocked,
        // but now surfaced by us instead of failing silently.
        const missing = Array.from(this.element.querySelectorAll(':invalid'))
            .filter((el) => !el.disabled && el.willValidate);

        if (missing.length === 0) {
            return; // valid → native POST proceeds
        }

        event.preventDefault();

        // Highlight EVERY offender so the user sees all of them, not just one.
        missing.forEach((el) => {
            el.classList?.add('is-invalid');
            const row = el.closest('tr');
            row?.classList.add('wizard-row-invalid');
            const clear = () => {
                el.classList?.remove('is-invalid');
                row?.classList.remove('wizard-row-invalid');
            };
            el.addEventListener('input', clear, { once: true });
            el.addEventListener('change', clear, { once: true });
        });

        // Bring the first offender into view + focus it (even if it was the
        // off-screen one that the native validator could not surface).
        const first = missing[0];
        (first.closest('tr, .fa-form-section, .mb-3') || first)
            .scrollIntoView({ behavior: 'smooth', block: 'center' });
        try { first.focus({ preventScroll: true }); } catch (_) { /* hidden */ }

        this.#toast(this.#missingMessage(missing.length), 'warn');
    }

    #toast(message, tone) {
        if (typeof window.faToast === 'function') {
            window.faToast(message, { tone });
        } else {
            // Last-resort visibility if the toast helper is not mounted.
            // eslint-disable-next-line no-alert
            window.alert(message);
        }
    }

    #missingMessage(n) {
        const tpl = this.element.dataset.wizardFormGuardMissingTemplate
            || '%count% Pflichtfeld(er) fehlen noch — rot markiert. Bitte ausfüllen, dann erneut „Weiter".';
        return tpl.replace('%count%', String(n));
    }
}
