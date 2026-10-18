import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import LifecycleDropdown from '../../../assets/controllers/lifecycle_dropdown_controller.js';
import ModalWizard from '../../../assets/controllers/modal_wizard_controller.js';
import { auroraTurboConfirm } from '../../../assets/js/turbo-confirm.js';
import { mount, stopAll, tick } from '../support/mount.js';

describe('lifecycle-dropdown — reason prompt', () => {
    beforeEach(() => {
        window.prompt = vi.fn(() => 'native');
        global.fetch = vi.fn(() => Promise.resolve({ ok: true, status: 200, json: () => Promise.resolve({}) }));
        Object.defineProperty(window, 'location', { value: { reload: vi.fn() }, writable: true });
    });
    afterEach(() => {
        stopAll();
        delete window.faPrompt;
    });

    const FIXTURE = `
      <div data-controller="lifecycle-dropdown"
           data-lifecycle-dropdown-transition-url-value="/t"
           data-lifecycle-dropdown-reason-label-value="Begründung?">
        <button data-lifecycle-dropdown-target="trigger"></button>
        <div data-lifecycle-dropdown-target="menu">
          <button id="tr" data-transition="approve" data-reason-required="1"
                  data-action="click->lifecycle-dropdown#applyTransition"></button>
        </div>
      </div>`;

    it('asks for the reason via faPrompt and posts it', async () => {
        window.faPrompt = vi.fn(() => Promise.resolve('  audit finding  '));
        const { element } = await mount('lifecycle-dropdown', LifecycleDropdown, FIXTURE);

        element.querySelector('#tr').click();
        await tick();

        expect(window.prompt).not.toHaveBeenCalled();
        expect(window.faPrompt).toHaveBeenCalledWith('Begründung?', expect.objectContaining({ required: true }));
        const body = JSON.parse(global.fetch.mock.calls[0][1].body);
        expect(body).toMatchObject({ transition: 'approve', reason: 'audit finding' });
    });

    it('does not transition when the prompt is cancelled', async () => {
        window.faPrompt = vi.fn(() => Promise.resolve(null));
        const { element } = await mount('lifecycle-dropdown', LifecycleDropdown, FIXTURE);

        element.querySelector('#tr').click();
        await tick();

        expect(global.fetch).not.toHaveBeenCalled();
    });
});

describe('modal-wizard — cancel guard', () => {
    afterEach(() => {
        stopAll();
        delete window.faConfirm;
    });

    it('asks via faConfirm with the translated message before discarding changes', async () => {
        window.confirm = vi.fn(() => true);
        window.faConfirm = vi.fn(() => Promise.resolve(false));
        const { controller } = await mount('modal-wizard', ModalWizard, `
          <div data-controller="modal-wizard" data-modal-wizard-total-steps-value="1"
               data-modal-wizard-has-changes-value="true"
               data-modal-wizard-discard-message-value="Discard your input?"></div>`);
        const cancelled = vi.fn();
        controller._dispatchCancel = cancelled;

        await controller.cancel();

        expect(window.confirm).not.toHaveBeenCalled();
        expect(window.faConfirm).toHaveBeenCalledWith('Discard your input?', expect.any(Object));
        expect(cancelled).not.toHaveBeenCalled();
    });
});

describe('Turbo confirm method', () => {
    afterEach(() => { delete window.faConfirm; });

    it('routes data-turbo-confirm through faConfirm with danger tone for DELETE links', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        const link = document.createElement('a');
        link.setAttribute('data-turbo-method', 'delete');

        await expect(auroraTurboConfirm('Delete?', link)).resolves.toBe(true);
        expect(window.faConfirm).toHaveBeenCalledWith('Delete?', { tone: 'danger' });
    });
});
