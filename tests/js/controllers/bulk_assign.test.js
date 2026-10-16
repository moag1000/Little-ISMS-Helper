import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import BulkActions from '../../../assets/controllers/bulk_actions_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

const FIXTURE = `
  <div data-controller="bulk-actions" data-bulk-actions-endpoint-value="/en/risk">
    <input type="checkbox" value="7" checked data-bulk-actions-target="item">
    <input type="checkbox" value="9" checked data-bulk-actions-target="item">
    <div data-bulk-actions-target="actionBar" data-bulk-actions-csrf-token="tok"
         data-bulk-actions-assignees-url="/en/bulk/assignees">
      <button id="assign" type="button" data-action="click->bulk-actions#bulkAssign">assign</button>
    </div>
  </div>`;

const toasts = [];
const flush = async () => { for (let i = 0; i < 4; i += 1) { await tick(); } };

describe('bulk-actions — assign', () => {
    beforeEach(() => {
        toasts.length = 0;
        document.addEventListener('toast:show', (e) => toasts.push(e.detail));
        Object.defineProperty(window, 'location', { value: { reload: vi.fn() }, writable: true });
    });
    afterEach(() => {
        stopAll();
        delete window.faPrompt;
    });

    it('loads assignees, asks with a select dialog and posts the chosen id with CSRF', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ assignees: [{ id: 3, label: 'Ada Admin' }] }) })
            .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, changed: 2, rejected: [] }) });
        window.faPrompt = vi.fn(() => Promise.resolve('3'));
        const { element } = await mount('bulk-actions', BulkActions, FIXTURE);

        element.querySelector('#assign').click();
        await flush();

        expect(global.fetch.mock.calls[0][0]).toBe('/en/bulk/assignees');
        expect(window.faPrompt).toHaveBeenCalledWith(expect.any(String), expect.objectContaining({
            choices: [{ value: 3, label: 'Ada Admin' }],
            required: true,
        }));
        expect(global.fetch.mock.calls[1][0]).toBe('/en/risk/bulk-assign');
        expect(JSON.parse(global.fetch.mock.calls[1][1].body)).toEqual({ ids: ['7', '9'], assignee_id: 3, _token: 'tok' });
        expect(toasts.at(-1).type).toBe('success');
    });

    it('does not post when the dialog is cancelled', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ assignees: [{ id: 3, label: 'A' }] }) });
        window.faPrompt = vi.fn(() => Promise.resolve(null));
        const { element } = await mount('bulk-actions', BulkActions, FIXTURE);

        element.querySelector('#assign').click();
        await flush();

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('reports partial success when rows were rejected by the voter', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ assignees: [{ id: 3, label: 'A' }] }) })
            .mockResolvedValueOnce({ ok: true, status: 200, json: () => Promise.resolve({ ok: true, changed: 1, rejected: [{ id: 9, reason: 'access_denied' }] }) });
        window.faPrompt = vi.fn(() => Promise.resolve('3'));
        const { element } = await mount('bulk-actions', BulkActions, FIXTURE);

        element.querySelector('#assign').click();
        await flush();

        expect(toasts.at(-1).type).toBe('warning');
    });
});
