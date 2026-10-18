import { describe, it, expect, beforeAll, afterEach } from 'vitest';

describe('faPrompt — choices (select dialog)', () => {
    beforeAll(async () => {
        window.translations = { ui: { choose: '– bitte wählen –', apply: 'Übernehmen', cancel: 'Abbrechen' } };
        await import('../../assets/js/fa-alerts.js');
    });
    afterEach(() => { document.body.innerHTML = ''; });

    it('renders a select with escaped labels and resolves the chosen value', async () => {
        const answer = window.faPrompt('Wer?', { choices: [{ value: 3, label: 'Ada <b>' }, { value: 5, label: 'Bob' }], required: true });
        const select = document.querySelector('select[data-fa-prompt-input]');
        const ok = document.querySelector('[data-fa-prompt-ok]');

        expect(select).not.toBeNull();
        expect(select.options[0].textContent).toBe('– bitte wählen –');
        expect(select.innerHTML).toContain('Ada &lt;b&gt;');
        expect(ok.disabled).toBe(true);

        select.value = '5';
        select.dispatchEvent(new Event('change'));
        expect(ok.disabled).toBe(false);
        ok.click();

        await expect(answer).resolves.toBe('5');
    });

    it('keeps the text input without choices', async () => {
        const answer = window.faPrompt('Tag?', {});
        expect(document.querySelector('input[data-fa-prompt-input]')).not.toBeNull();
        document.querySelector('[data-fa-prompt-cancel]').click();
        await expect(answer).resolves.toBeNull();
    });
});
