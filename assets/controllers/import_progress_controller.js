import { Controller } from '@hotwired/stimulus';

/**
 * ImportProgress Controller — Bulk-Import Wizard Step 4 (F2.9 + F2.12)
 *
 * Polls the batch status endpoint every N ms while status = 'committing'.
 * Updates status badge, counters, and error list in-place from JSON.
 * Emits Alva mood events on completion or failure.
 *
 * Targets:
 *   statusBadge — element showing the current status text
 *   counters    — element wrapping the KPI-tile row (hidden until loaded)
 *   errorList   — element wrapping the error table (shown/updated on errors)
 *
 * Values:
 *   batchUrl     (String) — URL that returns JSON batch status when Accept: application/json
 *   pollInterval (Number) — polling interval in ms (default 3000)
 *   status       (String) — initial status from server render ('committing'|'completed'|'failed'|…)
 *
 * Usage:
 *   <div data-controller="import-progress"
 *        data-import-progress-batch-url-value="{{ batchJsonUrl }}"
 *        data-import-progress-poll-interval-value="3000"
 *        data-import-progress-status-value="{{ batch.status }}">
 *     <span data-import-progress-target="statusBadge">…</span>
 *     <div data-import-progress-target="counters">…</div>
 *     <div data-import-progress-target="errorList">…</div>
 *   </div>
 */
export default class extends Controller {
    static targets = ['statusBadge', 'counters', 'errorList'];
    static values = {
        batchUrl: String,
        pollInterval: { type: Number, default: 3000 },
        status: { type: String, default: '' },
    };

    connect() {
        console.log('[import-progress] controller connected, status:', this.statusValue);
        this._timer = null;

        if (this.statusValue === 'committing') {
            // Emit scanning while work is running
            window.alvaBus?.emit({ mood: 'scanning', reason: 'import-committing' });
            this._startPolling();
        } else if (this.statusValue === 'completed') {
            window.alvaBus?.emit({ mood: 'celebrating', reason: 'import-completed', ttlMs: 5000 });
        } else if (this.statusValue === 'failed') {
            window.alvaBus?.emit({ mood: 'warning', reason: 'import-failed' });
        }
    }

    disconnect() {
        this._stopPolling();
    }

    // ── Private helpers ────────────────────────────────────────────────────

    _startPolling() {
        this._timer = setInterval(() => this._poll(), this.pollIntervalValue);
    }

    _stopPolling() {
        if (this._timer !== null) {
            clearInterval(this._timer);
            this._timer = null;
        }
    }

    async _poll() {
        if (!this.batchUrlValue) return;

        try {
            const resp = await fetch(this.batchUrlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!resp.ok) return; // transient — keep polling

            const data = await resp.json();
            this._applyData(data);

            if (data.status === 'completed') {
                this._stopPolling();
                window.alvaBus?.emit({ mood: 'celebrating', reason: 'import-completed', ttlMs: 5000 });
            } else if (data.status === 'failed') {
                this._stopPolling();
                window.alvaBus?.emit({ mood: 'warning', reason: 'import-failed' });
            }
        } catch (_e) {
            // Network error — keep polling silently
        }
    }

    _applyData(data) {
        // Update status badge text + class if present
        if (this.hasStatusBadgeTarget && data.status) {
            const badge = this.statusBadgeTarget;
            // Remove all fa-entity-badge--* status classes
            [...badge.classList].filter((c) => c.startsWith('fa-entity-badge--')).forEach((c) => badge.classList.remove(c));

            const statusClass = {
                completed: 'fa-entity-badge--control',
                failed: 'fa-entity-badge--finding',
                committing: 'fa-entity-badge--training',
            }[data.status] || 'fa-entity-badge--audit';

            badge.classList.add(statusClass);
            badge.textContent = data.status;
        }

        // On completed/failed → reload the page to get server-rendered content
        // (JSON-only endpoint may not include full error row HTML)
        if (data.status === 'completed' || data.status === 'failed') {
            setTimeout(() => window.location.reload(), 800);
        }
    }
}
