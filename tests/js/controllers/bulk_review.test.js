import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import BulkActions from '../../../assets/controllers/bulk_actions_controller.js';
import { mount, stopAll, tick } from '../support/mount.js';

const FIXTURE = `
  <div data-controller="bulk-actions" data-bulk-actions-endpoint-value="/en/supplier">
    <input type="checkbox" value="4" checked data-bulk-actions-target="item">
    <input type="checkbox" value="5" checked data-bulk-actions-target="item">
    <div data-bulk-actions-target="actionBar" data-bulk-actions-csrf-token="tok">
      <button id="review" type="button" data-action="click->bulk-actions#bulkReview">review</button>
    </div>
  </div>`;

const toasts = [];
const flush = async () => { for (let i = 0; i < 4; i += 1) { await tick(); } };
const reply = (status, body) => ({ ok: status < 400, status, json: () => Promise.resolve(body) });

describe('bulk-actions — review', () => {
    beforeEach(() => {
        toasts.length = 0;
        document.addEventListener('toast:show', (e) => toasts.push(e.detail));
        Object.defineProperty(window, 'location', { value: { reload: vi.fn() }, writable: true });
    });
    afterEach(() => {
        stopAll();
        delete window.faConfirm;
    });

    it('asks once and posts the selected ids with CSRF to {endpoint}/bulk-review', async () => {
        global.fetch = vi.fn().mockResolvedValueOnce(reply(200, { ok: true, changed: 2, rejected: [] }));
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        const { element } = await mount('bulk-actions', BulkActions, FIXTURE);

        element.querySelector('#review').click();
        await flush();

        expect(window.faConfirm).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/en/supplier/bulk-review');
        expect(JSON.parse(global.fetch.mock.calls[0][1].body)).toEqual({ ids: ['4', '5'], _token: 'tok' });
        expect(toasts.at(-1).type).toBe('success');
    });

    it('does not post when the dialog is cancelled', async () => {
        global.fetch = vi.fn();
        window.faConfirm = vi.fn(() => Promise.resolve(false));
        const { element } = await mount('bulk-actions', BulkActions, FIXTURE);

        element.querySelector('#review').click();
        await flush();

        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('warns on partial success and errors when nothing was confirmed', async () => {
        global.fetch = vi.fn()
            .mockResolvedValueOnce(reply(200, { ok: true, changed: 1, rejected: [{ id: 5, reason: 'not_reviewable' }] }))
            .mockResolvedValueOnce(reply(400, { ok: true, changed: 0, rejected: [{ id: 4, reason: 'not_reviewable' }, { id: 5, reason: 'not_reviewable' }] }));
        window.faConfirm = vi.fn(() => Promise.resolve(true));
        const { element } = await mount('bulk-actions', BulkActions, FIXTURE);

        element.querySelector('#review').click();
        await flush();
        expect(toasts.at(-1).type).toBe('warning');

        element.querySelector('#review').click();
        await flush();
        expect(toasts.at(-1).type).toBe('error');
    });
});
