import { describe, it, expect, afterEach, vi } from 'vitest';
import AuditSubsidiaries from '../../../assets/controllers/audit_subsidiaries_controller.js';
import BulkActions from '../../../assets/controllers/bulk_actions_controller.js';
import PresetPicker from '../../../assets/controllers/policy_wizard_preset_picker_controller.js';
import DensityToggle from '../../../assets/controllers/density_toggle_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

// Wiring repaired in the 2026-11 Stimulus sweep (Gate 79): each of these was
// referenced from a template but did nothing before.

afterEach(() => { stopAll(); vi.restoreAllMocks(); });

describe('audit-subsidiaries', () => {
    const FIXTURE = `
      <form data-controller="audit-subsidiaries">
        <select data-corporate-scope>
          <option value="full">full</option>
          <option value="corporate_wide">wide</option>
          <option value="corporate_subsidiaries">subs</option>
        </select>
        <div class="d-none" data-audit-subsidiaries-target="container">
          <select multiple data-corporate-subsidiaries>
            <option value="1">A</option><option value="2">B</option>
          </select>
        </div>
      </form>`;

    it('shows the picker for group audits and preselects all for a group-wide audit', async () => {
        const { element } = await mount('audit-subsidiaries', AuditSubsidiaries, FIXTURE);
        const scope = element.querySelector('[data-corporate-scope]');
        const container = element.querySelector('[data-audit-subsidiaries-target="container"]');
        const options = [...element.querySelectorAll('[data-corporate-subsidiaries] option')];
        expect(container.classList.contains('d-none')).toBe(true);

        scope.value = 'corporate_wide';
        scope.dispatchEvent(new Event('change'));
        expect(container.classList.contains('d-none')).toBe(false);
        expect(options.every((o) => o.selected)).toBe(true);

        scope.value = 'full';
        scope.dispatchEvent(new Event('change'));
        expect(container.classList.contains('d-none')).toBe(true);
        expect(options.some((o) => o.selected)).toBe(false);
    });
});

describe('bulk-actions row selection', () => {
    it('marks selected rows (formerly fa-bulk-select)', async () => {
        const { element } = await mount('bulk-actions', BulkActions, `
          <div data-controller="bulk-actions">
            <table><tbody><tr><td>
              <input type="checkbox" value="1" data-bulk-actions-target="item" data-action="bulk-actions#selectItem">
            </td></tr></tbody></table>
            <div data-bulk-actions-target="actionBar" hidden></div>
          </div>`);
        const box = element.querySelector('input');
        box.click(); // checks the box and fires input/change → bulk-actions#selectItem
        expect(element.querySelector('tr').classList.contains('fa-table__row--selected')).toBe(true);
        expect(element.querySelector('[data-bulk-actions-target="actionBar"]').hidden).toBe(false);
    });
});

describe('policy-wizard-preset-picker multi select', () => {
    it('ticks the union of standards of all picked bundles', async () => {
        global.fetch = vi.fn((url) => Promise.resolve({
            ok: true,
            json: () => Promise.resolve(url.includes('a')
                ? { preselected_standards: ['iso27001'], regulatory_references: ['ISO'] }
                : { preselected_standards: ['iso27001', 'nis2'], regulatory_references: ['NIS2'] }),
        }));
        const { element } = await mount('policy-wizard-preset-picker', PresetPicker, `
          <div data-controller="policy-wizard-preset-picker"
               data-policy-wizard-preset-picker-preview-url-value="/preview/__KEY__">
            <select multiple data-action="change->policy-wizard-preset-picker#onMultiChange">
              <option value="a" selected>a</option><option value="b" selected>b</option>
            </select>
            <input type="checkbox" value="iso27001" data-policy-wizard-preset-picker-target="standard">
            <input type="checkbox" value="nis2" data-policy-wizard-preset-picker-target="standard">
            <input type="checkbox" value="dora" data-policy-wizard-preset-picker-target="standard">
            <div class="d-none" data-policy-wizard-preset-picker-target="preview" data-documents-label="Dokumente"></div>
          </div>`);
        element.querySelector('select').dispatchEvent(new Event('change', { bubbles: true }));
        for (let i = 0; i < 4; i += 1) { await tick(); }
        const ticked = [...element.querySelectorAll('input:checked')].map((i) => i.value);
        expect(ticked).toEqual(['iso27001', 'nis2']);
        expect(element.querySelector('[data-policy-wizard-preset-picker-target="preview"]').textContent)
            .toBe('8 Dokumente — ISO, NIS2');
    });
});

describe('density-toggle', () => {
    it('saves the choice on the account with the CSRF token', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: true }));
        const { element } = await mount('density-toggle', DensityToggle, `
          <div data-controller="density-toggle"
               data-density-toggle-endpoint-value="/preferences/density"
               data-density-toggle-csrf-value="tok">
            <button data-density="expert" data-density-toggle-target="option"
                    data-action="density-toggle#set">x</button>
          </div>`);
        element.querySelector('button').click();
        await tick();
        expect(document.body.dataset.density).toBe('expert');
        const body = global.fetch.mock.calls[0][1].body;
        expect(body.get('density')).toBe('expert');
        expect(body.get('_token')).toBe('tok');
    });
});
