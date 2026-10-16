import { describe, it, expect, afterEach, beforeEach } from 'vitest';
import { faT } from '../../../assets/js/fa-i18n.js';
import PasswordStrengthController from '../../../assets/controllers/password_strength_controller.js';
import SearchController from '../../../assets/controllers/search_controller.js';
import ReportDesignerController from '../../../assets/controllers/report_designer_controller.js';
import BulkDeleteConfirmationController from '../../../assets/controllers/bulk_delete_confirmation_controller.js';
import { mount, stopAll } from '../support/mount.js';

// Controller texts come from window.translations.ui (base layout, rendered
// from translations/ui.*.yaml `ui.js.*`) — never from strings in the JS.
const GERMAN = {
    cancel: 'Abbrechen',
    loading: 'Lädt …',
    'password_strength.weak': 'Schwach',
    'password_strength.missing': 'Fehlend: %items%',
    'password_strength.uppercase': 'Großbuchstaben (A-Z)',
    'password_strength.numbers': 'Zahlen (0-9)',
    'password_strength.special': 'Sonderzeichen (!@#$%^&*)',
    'password_strength.very_weak': 'Sehr schwach',
    'password_strength.medium': 'Mittel',
    'search.no_results': 'Keine Ergebnisse für „%query%“',
    'search.no_results_hint': 'Andere Suchbegriffe versuchen.',
    'report_designer.no_data': 'Keine Daten',
    'bulk_actions.confirm_delete': '%count% %entity% wirklich löschen?',
};

describe('faT dictionary', () => {
    afterEach(() => { delete window.translations; });

    it('reads window.translations.ui and substitutes placeholders', () => {
        window.translations = { ui: GERMAN };
        expect(faT('cancel')).toBe('Abbrechen');
        expect(faT('search.no_results', { '%query%': 'x' })).toBe('Keine Ergebnisse für „x“');
    });

    it('falls back to the built-in English generic texts, then to the key', () => {
        expect(faT('cancel')).toBe('Cancel');
        expect(faT('error_status', { '%status%': 500 })).toBe('Error (500)');
        expect(faT('heat_map.load_error')).toBe('heat_map.load_error');
    });
});

describe('controllers render dictionary texts', () => {
    beforeEach(() => { window.translations = { ui: GERMAN }; });
    afterEach(() => { stopAll(); delete window.translations; });

    it('password-strength shows translated level and missing criteria', async () => {
        const { controller } = await mount('password-strength', PasswordStrengthController, `
            <div data-controller="password-strength">
                <input data-password-strength-target="input" value="abcdefgh">
                <div data-password-strength-target="meter"><div data-password-strength-target="bar"></div></div>
                <div data-password-strength-target="feedback"></div>
            </div>`);
        controller.check();
        const text = document.querySelector('[data-password-strength-target="feedback"]').textContent;
        expect(text).toContain('Fehlend: ');
        expect(text).toContain('Großbuchstaben (A-Z)');
        expect(text).not.toMatch(/Missing|Upper/);
    });

    it('search escapes the query in the empty state and uses the dictionary', async () => {
        const { controller } = await mount('search', SearchController, `
            <div data-controller="search"><div data-search-target="results"></div></div>`);
        controller.displayEmpty('<img src=x onerror=alert(1)>');
        const results = document.querySelector('[data-search-target="results"]');
        expect(results.querySelector('img')).toBeNull();
        expect(results.textContent).toContain('Keine Ergebnisse für „<img src=x onerror=alert(1)>“');
        expect(controller.highlight('a<b>c', 'b')).toBe('a&lt;<mark>b</mark>&gt;c');
    });

    it('report-designer renders an escaped Aurora table and translated empty text', async () => {
        const { controller } = await mount('report-designer', ReportDesignerController, `
            <div data-controller="report-designer"><div id="w"></div></div>`);
        const box = document.getElementById('w');
        controller.renderWidgetContent(box, 'table_risks', { columns: ['<b>Name</b>'], rows: [{ id: 1, name: '<script>x</script>' }] });
        expect(box.querySelector('table.fa-table')).not.toBeNull();
        expect(box.querySelector('table.table')).toBeNull();
        expect(box.querySelector('script, b')).toBeNull();
        controller.renderWidgetContent(box, 'table_risks', { columns: [], rows: [] });
        expect(box.textContent.trim()).toBe('Keine Daten');
    });

    it('bulk-delete-confirmation builds its default message from the dictionary', async () => {
        const { controller } = await mount('bulk-delete-confirmation', BulkDeleteConfirmationController, `
            <div data-controller="bulk-delete-confirmation"></div>`);
        expect(controller.getDefaultMessage(3, 'Assets')).toBe('3 Assets wirklich löschen?');
    });
});
