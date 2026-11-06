import { describe, it, expect, afterEach, vi } from 'vitest';
import Controller from '../../../assets/controllers/unsaved_changes_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

const entityForm = `
  <form method="post" id="f">
    <input name="risk[title]" id="title">
    <input type="hidden" name="risk[_token]" value="t">
  </form>`;

function visit(url = '/de/risk') {
    const ev = new CustomEvent('turbo:before-visit', { cancelable: true, detail: { url } });
    document.dispatchEvent(ev);
    return ev;
}

function unload() {
    const ev = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(ev);
    return ev;
}

describe('unsaved-changes controller', () => {
    afterEach(() => {
        stopAll();
        delete window.faConfirm;
        delete window.Turbo;
    });

    it('lets the user leave an untouched form', async () => {
        await mount('unsaved-changes', Controller, `<div data-controller="unsaved-changes">${entityForm}</div>`);

        expect(visit().defaultPrevented).toBe(false);
        expect(unload().defaultPrevented).toBe(false);
    });

    it('asks before leaving an edited entity form and follows the link on confirm', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        window.Turbo = { visit: vi.fn() };
        await mount('unsaved-changes', Controller, `<div data-controller="unsaved-changes">${entityForm}</div>`);

        document.getElementById('title').dispatchEvent(new Event('input', { bubbles: true }));
        expect(unload().defaultPrevented).toBe(true);
        const ev = visit('/de/asset');
        await tick();

        expect(ev.defaultPrevented).toBe(true);
        expect(window.faConfirm).toHaveBeenCalledTimes(1);
        expect(window.Turbo.visit).toHaveBeenCalledWith('/de/asset');
    });

    it('stays on the page when the user cancels', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(false));
        window.Turbo = { visit: vi.fn() };
        await mount('unsaved-changes', Controller, `<div data-controller="unsaved-changes">${entityForm}</div>`);

        document.getElementById('title').dispatchEvent(new Event('input', { bubbles: true }));
        visit();
        await tick();

        expect(window.Turbo.visit).not.toHaveBeenCalled();
    });

    it('clears the warning when the form is submitted', async () => {
        await mount('unsaved-changes', Controller, `<div data-controller="unsaved-changes">${entityForm}</div>`);
        const form = document.getElementById('f');

        document.getElementById('title').dispatchEvent(new Event('input', { bubbles: true }));
        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));

        expect(visit().defaultPrevented).toBe(false);
    });

    it('ignores filter, bulk, modal and opted-out forms', async () => {
        await mount('unsaved-changes', Controller, `
          <div data-controller="unsaved-changes">
            <form method="get"><input name="q" id="q"></form>
            <form method="post"><input type="checkbox" name="ids[]" id="bulk"></form>
            <div class="fa-modal"><form method="post"><input name="x[a]" id="m"><input type="hidden" name="x[_token]"></form></div>
            <form method="post" data-unsaved-guard="off"><input name="y[a]" id="o"><input type="hidden" name="y[_token]"></form>
          </div>`);

        for (const id of ['q', 'bulk', 'm', 'o']) {
            document.getElementById(id).dispatchEvent(new Event('input', { bubbles: true }));
        }

        expect(visit().defaultPrevented).toBe(false);
    });
});
