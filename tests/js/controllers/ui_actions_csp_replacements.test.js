import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../../../assets/controllers/ui_actions_controller.js';
import DiffSelect from '../../../assets/controllers/diff_select_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

// Actions that replaced inline on*="…" handlers (blocked by the CSP).

function submit(form, submitter = null) {
    const ev = new Event('submit', { bubbles: true, cancelable: true });
    if (submitter) {
        Object.defineProperty(ev, 'submitter', { value: submitter });
    }
    form.dispatchEvent(ev);
    return ev;
}

describe('ui-actions — CSP replacements for inline handlers', () => {
    afterEach(() => {
        stopAll();
        delete window.faConfirm;
        delete window.faToast;
    });

    it('submitOnce disables the submitter after the submission was built', async () => {
        const { element } = await mount('ui-actions', Controller, `
          <form data-controller="ui-actions" data-action="submit->ui-actions#submitOnce">
            <button type="submit" id="b">Go</button>
          </form>`);
        const button = element.querySelector('#b');

        const ev = submit(element, button);
        expect(ev.defaultPrevented).toBe(false);
        expect(button.disabled).toBe(false);
        await tick();
        expect(button.disabled).toBe(true);
    });

    it('splitToHidden turns the free-text list into one hidden input per id, without duplicates on resubmit', async () => {
        const { element } = await mount('ui-actions', Controller, `
          <form data-controller="ui-actions" data-action="submit->ui-actions#splitToHidden"
                data-ui-actions-source-param="control_ids_raw" data-ui-actions-name-param="control_ids[]">
            <input name="control_ids_raw" value="12, 34  56">
          </form>`);

        submit(element);
        submit(element);

        const values = [...element.querySelectorAll('input[name="control_ids[]"]')].map((i) => i.value);
        expect(values).toEqual(['12', '34', '56']);
    });

    it('disclose flips aria-expanded, the panel and the chevron', async () => {
        const { element } = await mount('ui-actions', Controller, `
          <button data-controller="ui-actions" data-action="ui-actions#disclose"
                  aria-expanded="false" aria-controls="detail"><i class="chevron-icon"></i></button>
          <div id="detail" hidden></div>`);
        const panel = document.getElementById('detail');

        element.click();
        expect(element.getAttribute('aria-expanded')).toBe('true');
        expect(panel.hidden).toBe(false);
        expect(element.querySelector('.chevron-icon').style.transform).toBe('rotate(90deg)');

        element.click();
        expect(element.getAttribute('aria-expanded')).toBe('false');
        expect(panel.hidden).toBe(true);
    });

    it('toggle hides all matching rows and swaps the trigger classes', async () => {
        const { element } = await mount('ui-actions', Controller, `
          <button class="fa-cyber-btn--ghost" aria-pressed="false" data-controller="ui-actions"
                  data-action="ui-actions#toggle" data-ui-actions-target-param=".row.ok"
                  data-ui-actions-active-class-param="fa-cyber-btn--primary"
                  data-ui-actions-inactive-class-param="fa-cyber-btn--ghost">x</button>
          <div class="row ok"></div><div class="row ok"></div><div class="row missing"></div>`);

        element.click();

        expect(document.querySelectorAll('.row.ok.d-none')).toHaveLength(2);
        expect(document.querySelector('.row.missing').classList.contains('d-none')).toBe(false);
        expect(element.classList.contains('fa-cyber-btn--primary')).toBe(true);
        expect(element.classList.contains('fa-cyber-btn--ghost')).toBe(false);
        expect(element.getAttribute('aria-pressed')).toBe('true');
    });

    it('toggle without a target toggles the given class on the next sibling', async () => {
        const { element } = await mount('ui-actions', Controller, `
          <button data-controller="ui-actions" data-action="ui-actions#toggle" data-ui-actions-class-param="is-open">x</button><div class="fa-menu"></div>`);

        element.click();

        expect(element.nextElementSibling.classList.contains('is-open')).toBe(true);
    });

    it('showPanel shows one panel and moves the active chip', async () => {
        await mount('ui-actions', Controller, `
          <button id="t-all" data-tab="all" class="chip chip--active" data-controller="ui-actions" data-action="ui-actions#showPanel"
                  data-ui-actions-panel-param="#tab-all" data-ui-actions-panels-param=".panel"
                  data-ui-actions-triggers-param="[data-tab]" data-ui-actions-active-class-param="chip--active">all</button>
          <button id="t-high" data-tab="high" class="chip" data-controller="ui-actions" data-action="ui-actions#showPanel"
                  data-ui-actions-panel-param="#tab-high" data-ui-actions-panels-param=".panel"
                  data-ui-actions-triggers-param="[data-tab]" data-ui-actions-active-class-param="chip--active">high</button>
          <div id="tab-all" class="panel"></div><div id="tab-high" class="panel d-none"></div>`);

        document.getElementById('t-high').click();

        expect(document.getElementById('tab-all').classList.contains('d-none')).toBe(true);
        expect(document.getElementById('tab-high').classList.contains('d-none')).toBe(false);
        expect(document.getElementById('t-high').classList.contains('chip--active')).toBe(true);
        expect(document.getElementById('t-all').classList.contains('chip--active')).toBe(false);
    });

    it('copyValue mirrors the select into the hidden field', async () => {
        const { element } = await mount('ui-actions', Controller, `
          <select data-controller="ui-actions" data-action="change->ui-actions#copyValue" data-ui-actions-target-param="#h">
            <option value="ISO27001">a</option><option value="NIS2">b</option>
          </select>
          <input type="hidden" id="h" value="ISO27001">`);

        element.value = 'NIS2';
        element.dispatchEvent(new Event('change', { bubbles: true }));

        expect(document.getElementById('h').value).toBe('NIS2');
    });

    it('copyToClipboard copies the value of a source field', async () => {
        const writeText = vi.fn(() => Promise.resolve());
        Object.defineProperty(navigator, 'clipboard', { value: { writeText }, configurable: true });
        window.faToast = vi.fn();
        const { element } = await mount('ui-actions', Controller, `
          <input id="url" value="https://example.test/p">
          <button data-controller="ui-actions" data-action="ui-actions#copyToClipboard" data-ui-actions-source-param="#url">c</button>`);

        element.click();
        await tick();

        expect(writeText).toHaveBeenCalledWith('https://example.test/p');
    });

    it('confirmAndFetch posts the fields as form data with the CSRF token', async () => {
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        window.faToast = vi.fn();
        global.fetch = vi.fn(() => Promise.resolve({ json: () => Promise.resolve({ success: true }) }));
        const { element } = await mount('ui-actions', Controller, `
          <button data-controller="ui-actions" data-action="ui-actions#confirmAndFetch"
                  data-ui-actions-confirm-param="Unlink?" data-ui-actions-url-param="/evidence/unlink"
                  data-ui-actions-csrf-param="tok" data-ui-actions-fields-param='{"document_id": 5, "entity_type": "control"}'>x</button>`);

        element.click();
        await tick();

        expect(window.faConfirm).toHaveBeenCalledWith('Unlink?', expect.objectContaining({ tone: 'danger' }));
        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/evidence/unlink');
        expect(init.body).toBeInstanceOf(FormData);
        expect(init.body.get('document_id')).toBe('5');
        expect(init.body.get('entity_type')).toBe('control');
        expect(init.body.get('_token')).toBe('tok');
    });

    it('historyBack goes back in history', async () => {
        const back = vi.spyOn(window.history, 'back').mockImplementation(() => {});
        const { element } = await mount('ui-actions', Controller, `
          <button data-controller="ui-actions" data-action="ui-actions#historyBack">back</button>`);

        element.click();

        expect(back).toHaveBeenCalledTimes(1);
    });
});

describe('diff-select — compare two snapshots', () => {
    const FIXTURE = `
      <form data-controller="diff-select" data-action="submit->diff-select#compare"
            data-diff-select-url-value="/en/compliance-wizard/nis2/diff/0/0"
            data-diff-select-message-value="Pick two different snapshots">
        <select name="from"><option value="3">a</option><option value="7">b</option></select>
        <select name="to"><option value="3">a</option><option value="7">b</option></select>
      </form>`;

    beforeEach(() => {
        Object.defineProperty(window, 'location', { value: { href: '' }, writable: true });
    });
    afterEach(() => {
        stopAll();
        delete window.faToast;
    });

    it('navigates to the diff route of the chosen pair', async () => {
        const { element } = await mount('diff-select', DiffSelect, FIXTURE);
        element.querySelector('[name=from]').value = '7';

        const ev = submit(element);

        expect(ev.defaultPrevented).toBe(true);
        expect(window.location.href).toBe('/en/compliance-wizard/nis2/diff/7/3');
    });

    it('warns via faToast when both snapshots are the same', async () => {
        window.faToast = vi.fn();
        const { element } = await mount('diff-select', DiffSelect, FIXTURE);

        submit(element);

        expect(window.location.href).toBe('');
        expect(window.faToast).toHaveBeenCalledWith('Pick two different snapshots', 'warning');
    });
});
