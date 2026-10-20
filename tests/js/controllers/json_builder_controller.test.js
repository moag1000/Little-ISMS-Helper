import { describe, it, expect, afterEach } from 'vitest';
import JsonBuilderController from '../../../assets/controllers/json_builder_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

function fixture(config, value = '') {
    const cfg = JSON.stringify(config).replace(/"/g, '&quot;');
    return `
        <div data-controller="json-builder"
             data-json-builder-config-value="${cfg}"
             data-json-builder-remove-label-value="Entfernen"
             data-json-builder-choose-label-value="Bitte wählen"
             data-json-builder-entry-label-value="Eintrag %index%"
             data-json-builder-duplicate-key-label-value="Schlüssel doppelt">
            <textarea data-json-builder-target="hidden">${value.replace(/</g, '&lt;')}</textarea>
            <div data-json-builder-target="items"></div>
            <div class="d-none" data-json-builder-target="empty"></div>
            <button type="button" data-action="click->json-builder#add">add</button>
        </div>`;
}

function typeInto(input, value) {
    input.value = value;
    input.dispatchEvent(new Event(input.tagName === 'SELECT' ? 'change' : 'input', { bubbles: true }));
}

const hidden = () => document.querySelector('[data-json-builder-target="hidden"]');
const cards = () => document.querySelectorAll('.fa-json-builder__item');

describe('json-builder controller', () => {
    afterEach(() => stopAll());

    const listConfig = {
        shape: 'list',
        fields: [
            { key: 'action', type: 'text', label: 'Maßnahme', required: true },
            { key: 'deadline', type: 'date', label: 'Frist' },
            { key: 'status', type: 'select', label: 'Status', choices: [{ value: 'open', label: 'Offen' }] },
        ],
    };

    it('renders one card per list entry with translated labels and keeps undeclared keys', async () => {
        const value = JSON.stringify([{ action: 'A', priority: 'high' }, { action: 'B', status: 'legacy' }]);
        await mount('json-builder', JsonBuilderController, fixture(listConfig, value));

        expect(cards()).toHaveLength(2);
        expect(cards()[0].getAttribute('aria-label')).toBe('Eintrag 1');
        expect(cards()[0].textContent).toContain('Maßnahme');
        // legacy select value stays selectable instead of being silently dropped
        const select = cards()[1].querySelector('select');
        expect(select.value).toBe('legacy');

        typeInto(cards()[0].querySelector('input[type="date"]'), '2026-12-31');
        expect(JSON.parse(hidden().value)).toEqual([
            { action: 'A', priority: 'high', deadline: '2026-12-31' },
            { action: 'B', status: 'legacy' },
        ]);
    });

    it('adds and removes entries, writing an empty string when nothing is left', async () => {
        await mount('json-builder', JsonBuilderController, fixture(listConfig));
        expect(document.querySelector('[data-json-builder-target="empty"]').classList.contains('d-none')).toBe(false);

        document.querySelector('[data-action="click->json-builder#add"]').click();
        typeInto(cards()[0].querySelector('input'), 'Neu');
        expect(JSON.parse(hidden().value)).toEqual([{ action: 'Neu' }]);

        await tick(); // Stimulus binds data-action on freshly rendered nodes asynchronously
        cards()[0].querySelector('.fa-json-builder__remove').click();
        expect(cards()).toHaveLength(0);
        expect(hidden().value).toBe('');
    });

    it('map shape preserves hidden keys, coerces numbers and flags duplicate keys', async () => {
        const config = {
            shape: 'map',
            hiddenKeys: ['_global'],
            keyLabel: 'Kategorie',
            fields: [
                { key: 'retention_days', type: 'number', label: 'Tage', required: true },
                { key: 'auto_delete', type: 'bool', label: 'Auto' },
            ],
        };
        const value = JSON.stringify({ incident: { retention_days: 1825 }, _global: { digest_enabled: true } });
        await mount('json-builder', JsonBuilderController, fixture(config, value));

        expect(cards()).toHaveLength(1);
        cards()[0].querySelector('input[type="checkbox"]').click();
        typeInto(cards()[0].querySelector('input[type="number"]'), '30');
        expect(JSON.parse(hidden().value)).toEqual({
            incident: { retention_days: 30, auto_delete: true },
            _global: { digest_enabled: true },
        });

        document.querySelector('[data-action="click->json-builder#add"]').click();
        const keyInput = cards()[1].querySelector('[data-key-index="1"]');
        typeInto(keyInput, 'incident');
        expect(keyInput.validationMessage || keyInput.classList.contains('is-invalid')).toBeTruthy();
        expect(keyInput.classList.contains('is-invalid')).toBe(true);
    });

    it('map shape with scalar values serialises key → value', async () => {
        const config = { shape: 'map', scalarValue: true, keyLabel: 'Kategorie', fields: [{ key: 'period', type: 'text', label: 'Frist', required: true }] };
        await mount('json-builder', JsonBuilderController, fixture(config, JSON.stringify({ payroll: '10 years' })));

        typeInto(cards()[0].querySelectorAll('input')[1], '6 years');
        expect(JSON.parse(hidden().value)).toEqual({ payroll: '6 years' });
    });

    it('object shape edits tags and keeps unknown keys', async () => {
        const config = { shape: 'object', hiddenKeys: ['delivery_mode'], fields: [{ key: 'recipients', type: 'tags', label: 'Empfänger' }] };
        const value = JSON.stringify({ recipients: ['a@example.com'], delivery_mode: 'digest_daily' });
        await mount('json-builder', JsonBuilderController, fixture(config, value));

        expect(document.querySelector('.fa-json-builder__remove')).toBeNull();
        const tagInput = document.querySelector('.fa-json-builder__tag-input');
        tagInput.value = 'b@example.com';
        tagInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }));
        expect(JSON.parse(hidden().value)).toEqual({ recipients: ['a@example.com', 'b@example.com'], delivery_mode: 'digest_daily' });
    });
});

describe('json-builder legacy non-object list entries', () => {
    afterEach(() => stopAll());

    const cfg = { shape: 'list', fields: [{ key: 'action', type: 'text', label: 'Maßnahme' }] };

    it('keeps plain-string/number entries verbatim on round-trip and shows them read-only', async () => {
        const value = JSON.stringify(['Call the DPO', { action: 'A' }, 42]);
        await mount('json-builder', JsonBuilderController, fixture(cfg, value));

        expect(cards()).toHaveLength(3);
        expect(cards()[0].textContent).toContain('Call the DPO');
        expect(cards()[0].querySelector('input')).toBeNull();

        typeInto(cards()[1].querySelector('input'), 'B');
        expect(JSON.parse(hidden().value)).toEqual(['Call the DPO', { action: 'B' }, 42]);
    });

    it('lets the user remove a raw entry explicitly', async () => {
        await mount('json-builder', JsonBuilderController, fixture(cfg, JSON.stringify(['x', { action: 'A' }])));
        cards()[0].querySelector('.fa-json-builder__remove').click();
        expect(JSON.parse(hidden().value)).toEqual([{ action: 'A' }]);
    });
});
