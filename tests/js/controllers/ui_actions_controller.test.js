import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../../../assets/controllers/ui_actions_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

function submit(form) {
    const ev = new Event('submit', { bubbles: true, cancelable: true });
    form.dispatchEvent(ev);
    return ev;
}

describe('ui-actions controller — confirm', () => {
    beforeEach(() => {
        window.confirm = vi.fn(() => true);
        HTMLFormElement.prototype.requestSubmit = vi.fn();
    });
    afterEach(() => {
        stopAll();
        delete window.faConfirm;
    });

    it('asks via faConfirm (not native confirm) and re-submits on OK', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        const { element } = await mount('ui-actions', Controller, `
          <form data-controller="ui-actions" data-action="submit->ui-actions#confirm"
                data-ui-actions-message-param="Delete it?" data-ui-actions-tone-param="danger">
            <button type="submit">x</button>
          </form>`);

        const ev = submit(element);
        await tick();

        expect(ev.defaultPrevented).toBe(true);
        expect(window.faConfirm).toHaveBeenCalledWith('Delete it?', expect.objectContaining({ tone: 'danger' }));
        expect(window.confirm).not.toHaveBeenCalled();
        expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledTimes(1);
    });

    it('does not submit when the dialog is cancelled', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(false));
        const { element } = await mount('ui-actions', Controller, `
          <form data-controller="ui-actions" data-action="submit->ui-actions#confirm"
                data-ui-actions-message-param="Sure?"><button type="submit">x</button></form>`);

        submit(element);
        await tick();

        expect(HTMLFormElement.prototype.requestSubmit).not.toHaveBeenCalled();
    });

    it('a click-confirm on a button submits its form with that button as submitter, even when an inner icon was clicked', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        const { element } = await mount('ui-actions', Controller, `
          <form>
            <button type="submit" name="apply" data-controller="ui-actions"
                    data-action="click->ui-actions#confirm" data-ui-actions-message-param="Apply?">
              <i class="icon"></i>
            </button>
          </form>`);

        element.querySelector('i').dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
        await tick();

        expect(window.faConfirm).toHaveBeenCalledTimes(1);
        expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledWith(element);
    });

    it('falls back to the translated generic question when no message is given', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(false));
        window.translations = { ui: { are_you_sure: 'Sind Sie sicher?' } };
        const { element } = await mount('ui-actions', Controller, `
          <form data-controller="ui-actions" data-action="submit->ui-actions#confirm"><button type="submit">x</button></form>`);

        submit(element);
        await tick();

        expect(window.faConfirm).toHaveBeenCalledWith('Sind Sie sicher?', expect.any(Object));
        delete window.translations;
    });
});

describe('ui-actions controller — promptSubmit', () => {
    const FIXTURE = `
      <form data-controller="ui-actions" data-action="submit->ui-actions#promptSubmit"
            data-ui-actions-message-param="Reason?" data-ui-actions-field-param="reason">
        <input type="hidden" name="reason" value="">
        <button type="submit">x</button>
      </form>`;

    beforeEach(() => {
        window.prompt = vi.fn(() => 'native');
        HTMLFormElement.prototype.requestSubmit = vi.fn();
    });
    afterEach(() => {
        stopAll();
        delete window.faPrompt;
    });

    it('writes the Aurora prompt answer into the field and submits', async () => {
        window.faPrompt = vi.fn(() => Promise.resolve('duplicate account'));
        const { element } = await mount('ui-actions', Controller, FIXTURE);

        const ev = submit(element);
        await tick();

        expect(ev.defaultPrevented).toBe(true);
        expect(window.prompt).not.toHaveBeenCalled();
        expect(window.faPrompt).toHaveBeenCalledWith('Reason?', expect.objectContaining({ allowEmpty: true }));
        expect(element.querySelector('[name=reason]').value).toBe('duplicate account');
        expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledTimes(1);
    });

    it('aborts the submission when the prompt is cancelled', async () => {
        window.faPrompt = vi.fn(() => Promise.resolve(null));
        const { element } = await mount('ui-actions', Controller, FIXTURE);

        submit(element);
        await tick();

        expect(HTMLFormElement.prototype.requestSubmit).not.toHaveBeenCalled();
    });

    it('submits with an empty value when the optional answer is left blank', async () => {
        window.faPrompt = vi.fn(() => Promise.resolve(''));
        const { element } = await mount('ui-actions', Controller, FIXTURE);

        submit(element);
        await tick();

        expect(element.querySelector('[name=reason]').value).toBe('');
        expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledTimes(1);
    });
});

describe('ui-actions controller — bypass flag hygiene', () => {
    afterEach(() => {
        stopAll();
        delete window.faConfirm;
    });

    it('clears the bypass flag when native validation blocks the re-submit, so the next submit asks again', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        // Validation-blocked requestSubmit: no submit event is dispatched.
        HTMLFormElement.prototype.requestSubmit = vi.fn();
        const { element } = await mount('ui-actions', Controller, `
          <form data-controller="ui-actions" data-action="submit->ui-actions#confirm"
                data-ui-actions-message-param="Delete it?"><button type="submit">x</button></form>`);

        submit(element);
        await tick();
        expect(HTMLFormElement.prototype.requestSubmit).toHaveBeenCalledTimes(1);
        expect(element.dataset.faConfirmBypass).toBeUndefined();

        const ev = submit(element);
        expect(ev.defaultPrevented).toBe(true);
        await tick();
        expect(window.faConfirm).toHaveBeenCalledTimes(2);
    });
});
