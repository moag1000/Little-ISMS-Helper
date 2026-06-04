import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../../../assets/controllers/fa_modal_controller.js';
import { mount, tick, stopAll } from '../support/mount.js';

const FIXTURE = `
  <div data-controller="fa-modal"
       data-fa-modal-mode-value="confirm"
       aria-hidden="true">
    <div class="fa-modal__backdrop" data-fa-modal-target="backdrop"></div>
    <div class="fa-modal__container" data-fa-modal-target="dialog">
      <button type="button" class="fa-modal__close" data-action="fa-modal#cancel">x</button>
    </div>
  </div>`;

describe('fa-modal controller — aria-hidden / inert lifecycle', () => {
    beforeEach(() => {
        // jsdom has no layout engine.
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => stopAll());

    it('starts closed: inert + aria-hidden set', async () => {
        const { element } = await mount('fa-modal', Controller, FIXTURE);
        expect(element.hasAttribute('inert')).toBe(true);
        expect(element.getAttribute('aria-hidden')).toBe('true');
        expect(element.classList.contains('is-open')).toBe(false);
    });

    it('clears BOTH aria-hidden and inert when opened (the fixed bug)', async () => {
        const { application, element } = await mount('fa-modal', Controller, FIXTURE);
        const ctrl = application.getControllerForElementAndIdentifier(element, 'fa-modal');

        ctrl.openValue = true;
        await tick();

        expect(element.classList.contains('is-open')).toBe(true);
        expect(element.hasAttribute('inert')).toBe(false);
        // The regression: a static aria-hidden="true" must NOT survive opening,
        // or screen readers can't see the live dialog.
        expect(element.hasAttribute('aria-hidden')).toBe(false);
    });

    it('re-applies aria-hidden + inert when closed again', async () => {
        const { application, element } = await mount('fa-modal', Controller, FIXTURE);
        const ctrl = application.getControllerForElementAndIdentifier(element, 'fa-modal');

        ctrl.openValue = true;
        await tick();
        ctrl.openValue = false;
        await tick();

        expect(element.classList.contains('is-open')).toBe(false);
        expect(element.hasAttribute('inert')).toBe(true);
        expect(element.getAttribute('aria-hidden')).toBe('true');
    });
});
