import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../../../assets/controllers/wizard_form_guard_controller.js';
import { mount, stopAll } from '../support/mount.js';

const FIXTURE = `
  <form data-controller="wizard-form-guard" novalidate>
    <select required name="decisions[1][action]" id="action-1">
      <option value="">— choose —</option>
      <option value="keep">keep</option>
    </select>
    <button type="submit">Weiter</button>
  </form>`;

function submit(form) {
    const ev = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(ev);
    return ev;
}

describe('wizard-form-guard controller', () => {
    beforeEach(() => {
        Element.prototype.scrollIntoView = vi.fn();
        window.faToast = vi.fn();
    });
    afterEach(() => stopAll());

    it('blocks submit + flags the invalid field + toasts when required is empty', async () => {
        const { element } = await mount('wizard-form-guard', Controller, FIXTURE);
        const select = element.querySelector('#action-1'); // value '' → :invalid

        const ev = submit(element);

        expect(ev.defaultPrevented).toBe(true);
        expect(select.classList.contains('is-invalid')).toBe(true);
        expect(window.faToast).toHaveBeenCalledTimes(1);
    });

    it('lets submit through when every required field has a value', async () => {
        const { element } = await mount('wizard-form-guard', Controller, FIXTURE);
        element.querySelector('#action-1').value = 'keep';

        const ev = submit(element);

        expect(ev.defaultPrevented).toBe(false);
        expect(window.faToast).not.toHaveBeenCalled();
    });

    it('clears the highlight once the user fixes the field', async () => {
        const { element } = await mount('wizard-form-guard', Controller, FIXTURE);
        const select = element.querySelector('#action-1');
        submit(element);
        expect(select.classList.contains('is-invalid')).toBe(true);

        select.value = 'keep';
        select.dispatchEvent(new Event('change', { bubbles: true }));

        expect(select.classList.contains('is-invalid')).toBe(false);
    });
});
