import { describe, it, expect, afterEach, vi } from 'vitest';
import CommandPaletteController from '../../../assets/controllers/command_palette_controller.js';
import { mount, stopAll } from '../support/mount.js';

const COMMANDS = [
    { id: 'goto-risks', label: 'Risiken anzeigen', category: 'Navigation', icon: 'risk-register', url: '/de/risk', keywords: ['risk'] },
    { id: 'admin-tags', label: 'Tags', category: 'Administration', icon: 'nav-tags', url: '/de/admin/tags', keywords: ['label'] },
];

const fixture = () => `
    <button data-command-palette-open>Suche</button>
    <div data-controller="command-palette"
         data-command-palette-commands-value='${JSON.stringify(COMMANDS)}'
         data-command-palette-search-url-value="/api/search"
         data-command-palette-debounce-delay-value="0">
        <div class="command-palette-modal" data-command-palette-target="modal">
            <input data-command-palette-target="input" data-action="input->command-palette#search">
            <div data-command-palette-target="results"></div>
            <div data-command-palette-target="noResults" class="hidden"></div>
        </div>
    </div>`;

const modal = () => document.querySelector('[data-command-palette-target="modal"]');
const flush = () => new Promise((r) => setTimeout(r, 0));

describe('command-palette (one search + command overlay)', () => {
    afterEach(() => { stopAll(); vi.restoreAllMocks(); delete global.fetch; });

    it('opens on ⌘K, Ctrl+P and the navbar trigger', async () => {
        await mount('command-palette', CommandPaletteController, fixture());
        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'k', metaKey: true }));
        expect(modal().classList.contains('command-palette-open')).toBe(true);
        modal().classList.remove('command-palette-open');

        document.dispatchEvent(new KeyboardEvent('keydown', { key: 'p', ctrlKey: true }));
        expect(modal().classList.contains('command-palette-open')).toBe(true);
        modal().classList.remove('command-palette-open');

        document.querySelector('[data-command-palette-open]').click();
        expect(modal().classList.contains('command-palette-open')).toBe(true);
    });

    it('shows the commands when empty and filters them while typing', async () => {
        const { controller } = await mount('command-palette', CommandPaletteController, fixture());
        controller.open();
        expect(document.querySelectorAll('.command-item')).toHaveLength(2);

        controller.inputTarget.value = 'label';
        controller.search();
        const items = document.querySelectorAll('.command-item');
        expect(items).toHaveLength(1);
        expect(items[0].textContent).toContain('Tags');
    });

    it('adds records and pages from the search API above the commands', async () => {
        global.fetch = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({
                navigation: [{ title: 'Risikomatrix', url: '/de/risk/matrix' }],
                risks: [{ title: 'Risiko Datenabfluss', url: '/de/risk/7', description: 'Asset Portal' }],
            }),
        });
        const { controller } = await mount('command-palette', CommandPaletteController, fixture());
        controller.open();
        controller.inputTarget.value = 'risik';
        controller.search();
        await flush();
        await flush();

        expect(global.fetch).toHaveBeenCalledWith('/api/search?q=risik', expect.anything());
        const labels = [...document.querySelectorAll('.command-item-label')].map((e) => e.textContent);
        expect(labels).toEqual(['Risikomatrix', 'Risiko Datenabfluss', 'Risiken anzeigen']);
    });

    it('moves through all groups with the arrow keys and closes on Escape', async () => {
        const { controller } = await mount('command-palette', CommandPaletteController, fixture());
        controller.open();
        modal().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        expect(controller.selectedIndex).toBe(1);
        modal().dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }));
        expect(controller.selectedIndex).toBe(0);
        modal().dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        expect(modal().classList.contains('command-palette-open')).toBe(false);
    });
});
