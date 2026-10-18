import { describe, it, expect, afterEach } from 'vitest';
import ConditionBuilderController from '../../../assets/controllers/condition_builder_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

const row = (index, keyword) => `
    <div class="fa-condition-builder__row" data-condition-builder-target="row">
        <span class="fa-condition-builder__keyword fa-condition-builder__keyword--${keyword}"
              data-condition-builder-target="keyword">${keyword}</span>
        <input name="rule[conditionRows][${index}][field]">
        <select name="rule[conditionRows][${index}][op]"><option value="==">=</option></select>
        <input name="rule[conditionRows][${index}][value]">
        <button type="button" class="remove" data-action="click->condition-builder#remove">x</button>
    </div>`;

function fixture(rows, logic = 'and') {
    return `
        <div data-controller="condition-builder"
             data-condition-builder-index-value="${rows}"
             data-condition-builder-prototype-name-value="__condition_index__"
             data-condition-builder-when-label-value="WENN"
             data-condition-builder-and-label-value="UND"
             data-condition-builder-or-label-value="ODER"
             data-condition-builder-added-label-value="Bedingung hinzugefügt"
             data-condition-builder-removed-label-value="Bedingung entfernt">
            <select data-condition-builder-target="logic" data-action="change->condition-builder#relabel">
                <option value="and"${logic === 'and' ? ' selected' : ''}>and</option>
                <option value="or"${logic === 'or' ? ' selected' : ''}>or</option>
            </select>
            <div data-condition-builder-target="rows">${Array.from({ length: rows }, (_, i) => row(i, i ? logic : 'when')).join('')}</div>
            <p data-condition-builder-target="empty"${rows ? ' hidden' : ''}>leer</p>
            <template data-condition-builder-target="template">${row('__condition_index__', logic)}</template>
            <button type="button" class="add" data-action="click->condition-builder#add">add</button>
            <div data-condition-builder-target="status"></div>
        </div>`;
}

const keywords = () => Array.from(document.querySelectorAll('[data-condition-builder-target="rows"] [data-condition-builder-target="keyword"]')).map(k => k.textContent);
const status = () => document.querySelector('[data-condition-builder-target="status"]').textContent;

describe('condition-builder controller', () => {
    afterEach(() => stopAll());

    it('adds a row from the prototype with the next index and a translated keyword', async () => {
        await mount('condition-builder', ConditionBuilderController, fixture(2));
        document.querySelector('.add').click();
        await tick();

        const names = Array.from(document.querySelectorAll('[data-condition-builder-target="rows"] input')).map(i => i.name);
        expect(names).toContain('rule[conditionRows][2][field]');
        expect(names.some(n => n.includes('__condition_index__'))).toBe(false);
        expect(keywords()).toEqual(['WENN', 'UND', 'UND']);
        expect(status()).toBe('Bedingung hinzugefügt');

        document.querySelector('.add').click();
        await tick();
        expect(document.querySelector('[name="rule[conditionRows][3][value]"]')).not.toBeNull();
    });

    it('removes a row, promotes the next one to WHEN and shows the empty hint at zero rows', async () => {
        await mount('condition-builder', ConditionBuilderController, fixture(2));
        document.querySelector('[data-condition-builder-target="rows"] .remove').click();
        await tick();

        expect(keywords()).toEqual(['WENN']);
        expect(document.querySelector('[name="rule[conditionRows][1][field]"]')).not.toBeNull();
        expect(status()).toBe('Bedingung entfernt');

        document.querySelector('[data-condition-builder-target="rows"] .remove').click();
        await tick();
        expect(document.querySelector('[data-condition-builder-target="empty"]').hidden).toBe(false);
    });

    it('switches AND/OR keywords when the logic select changes', async () => {
        await mount('condition-builder', ConditionBuilderController, fixture(3));
        const logic = document.querySelector('[data-condition-builder-target="logic"]');
        logic.value = 'or';
        logic.dispatchEvent(new Event('change', { bubbles: true }));
        await tick();

        expect(keywords()).toEqual(['WENN', 'ODER', 'ODER']);
        expect(document.querySelectorAll('.fa-condition-builder__keyword--or')).toHaveLength(2);
    });
});
