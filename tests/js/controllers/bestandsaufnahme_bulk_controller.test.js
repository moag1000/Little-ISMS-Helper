import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../../../assets/controllers/bestandsaufnahme_bulk_controller.js';
import { mount, tick, stopAll } from '../support/mount.js';

function row(docId, suggested) {
    return `
      <tr data-controller="bestandsaufnahme-row" data-suggested-action="${suggested}" data-wizard-tagged="0">
        <td>
          <select data-bestandsaufnahme-row-target="actionSelect" name="decisions[${docId}][action]">
            <option value="">—</option>
            <option value="replace">replace</option>
            <option value="keep">keep</option>
            <option value="merge_into_topic">merge</option>
          </select>
        </td>
      </tr>`;
}

const FIXTURE = `
  <div class="wizard-step">
    <section data-controller="bestandsaufnahme-bulk"
             data-bestandsaufnahme-bulk-applied-template="%count% rows updated"
             data-bestandsaufnahme-bulk-keep-all-body="keep all %count%?">
      <button type="button" data-action="bestandsaufnahme-bulk#applySuggestions">go</button>
      <div data-bestandsaufnahme-bulk-target="feedback"></div>
    </section>
    <table><tbody>
      ${row(1, 'replace')}
      ${row(2, 'keep')}
    </tbody></table>
  </div>`;

describe('bestandsaufnahme-bulk controller', () => {
    beforeEach(() => {
        // No confirm modal in the fixture → controller falls back to faConfirm.
        window.faConfirm = vi.fn().mockResolvedValue(true);
    });
    afterEach(() => stopAll());

    it('_dataset() resolves the prefixed data-bestandsaufnahme-bulk-* attrs', async () => {
        const { controller } = await mount('bestandsaufnahme-bulk', Controller, FIXTURE);
        // Regression: the helper used to read bare keys (appliedTemplate) and
        // silently fall back to English; it must read the prefixed attribute.
        expect(controller._dataset('appliedTemplate')).toBe('%count% rows updated');
        expect(controller._dataset('keepAllBody')).toBe('keep all %count%?');
    });

    it('_applyPerRowSuggestion sets each row to its own suggested action', async () => {
        const { controller } = await mount('bestandsaufnahme-bulk', Controller, FIXTURE);

        const applied = controller._applyPerRowSuggestion(controller._collectRows(() => true));

        const selects = document.querySelectorAll('select[data-bestandsaufnahme-row-target="actionSelect"]');
        expect(applied).toBe(2);
        expect(selects[0].value).toBe('replace'); // row 1 suggested replace
        expect(selects[1].value).toBe('keep');    // row 2 suggested keep
    });

    it('_applyToRows forces every row to a single value', async () => {
        const { controller } = await mount('bestandsaufnahme-bulk', Controller, FIXTURE);

        const applied = controller._applyToRows(controller._collectRows(() => true), 'keep');

        expect(applied).toBe(2);
        const values = [...document.querySelectorAll('select[data-bestandsaufnahme-row-target="actionSelect"]')]
            .map((s) => s.value);
        expect(values).toEqual(['keep', 'keep']);
    });

    it('applySuggestions confirm-gate calls faConfirm then applies', async () => {
        const { controller } = await mount('bestandsaufnahme-bulk', Controller, FIXTURE);

        controller.applySuggestions(new Event('click'));
        await tick();
        await tick();

        expect(window.faConfirm).toHaveBeenCalledTimes(1);
        const values = [...document.querySelectorAll('select[data-bestandsaufnahme-row-target="actionSelect"]')]
            .map((s) => s.value);
        expect(values).toEqual(['replace', 'keep']);
    });

    it('never blanks a select when the target value is not an option', async () => {
        // applyToRows must skip, not set an unavailable value (split_to_topics
        // is not offered on these rows) — leaving the select non-empty/valid.
        const { controller } = await mount('bestandsaufnahme-bulk', Controller, FIXTURE);
        const rows = controller._collectRows(() => true);
        const applied = controller._applyToRows(rows, 'split_to_topics');
        expect(applied).toBe(0);
        const values = [...document.querySelectorAll('select[data-bestandsaufnahme-row-target="actionSelect"]')]
            .map((s) => s.value);
        expect(values).toEqual(['', '']); // untouched, not blanked to an invalid value
    });
});
