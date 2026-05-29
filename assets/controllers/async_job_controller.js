import { Controller } from '@hotwired/stimulus';

/**
 * AsyncJob Controller — polls the /admin/jobs/{id}/status endpoint and
 * updates a progress card rendered by _async_job_progress.html.twig.
 *
 * Terminal states (succeeded / failed) stop polling and show a "Back" link.
 * Emits Alva mood events on completion and failure.
 *
 * Targets:
 *   statusBadge    — span showing current status text + entity-badge class
 *   statusMessage  — span with free-text status message
 *   progressWrapper — div wrapping progress bar (hidden until total > 0)
 *   progressBar    — div.progress-bar (width %)
 *   progressNumbers — span showing "current / total"
 *   progressLabel  — span with label text (updated from message field)
 *   errorBox       — div.alert-danger (hidden until failure)
 *   errorTrace     — pre inside errorBox for stack trace
 *   successBox     — div.alert-success (hidden until success)
 *   successMessage — span inside successBox
 *   backLink       — link shown after terminal state
 *
 * Values:
 *   statusUrl     (String) — /admin/jobs/{uuid}/status
 *   pollInterval  (Number) — polling interval in ms (default 3000)
 *   jobId         (String) — UUID of the job (for reference)
 *   cancelUrl     (String) — optional URL for "back" navigation on done
 *   redirectUrl   (String) — optional; navigate here on terminal success
 *   messageRunning (String) — optional running-state text
 *   messageSuccess (String) — optional success-state text
 *
 * Two usage modes:
 *   1. Display-only (admin jobs page): controller is attached to a non-form
 *      element and polls statusUrl immediately on connect().
 *   2. Submit-driven (setup wizard long-running steps): controller is attached
 *      to a <form> with `data-action="submit->async-job#start"`. start() posts
 *      the form via fetch (so the browser never blocks/times out on the
 *      long-running request), then polls statusUrl and — if redirectUrl is set
 *      — navigates to the next step once the job reports success.
 */
export default class extends Controller {
    static targets = [
        'statusBadge', 'statusMessage',
        'progressWrapper', 'progressBar', 'progressNumbers', 'progressLabel',
        'errorBox', 'errorTrace',
        'successBox', 'successMessage',
        'backLink',
        // Optional — rendered with structured payload via _renderPayloadDetails.
        // Jobs that emit `repair_summary` / `restore_result` / `apply_failure` /
        // `reconcile_failure` via JobContext::updatePayload populate this.
        'resultDetails',
    ];

    static values = {
        statusUrl: String,
        pollInterval: { type: Number, default: 3000 },
        jobId: String,
        cancelUrl: { type: String, default: '' },
        redirectUrl: { type: String, default: '' },
        messageRunning: { type: String, default: '' },
        messageSuccess: { type: String, default: '' },
    };

    connect() {
        this._timer = null;
        this._terminal = false;

        // Submit-driven forms start the job on submit — don't poll before the
        // job exists. Display-only usages (non-form) begin polling immediately.
        if (this.element.tagName === 'FORM') {
            return;
        }

        window.alvaBus?.emit({ mood: 'scanning', reason: 'async-job-pending' });
        this._startPolling();
    }

    disconnect() {
        this._stopPolling();
    }

    // ── Actions ─────────────────────────────────────────────────────────────

    /**
     * Submit handler: POST the form via fetch instead of a full-page navigation,
     * so a long-running backup/schema job cannot make the browser hang or render
     * the endpoint's raw JSON. The endpoint returns {status:'started'} at once
     * (work continues in the background); we then poll statusUrl for the result.
     */
    async start(event) {
        event.preventDefault();

        const form = this.element.tagName === 'FORM'
            ? this.element
            : this.element.closest('form');
        if (!form) return;

        this._form = form;
        this._terminal = false;

        // Busy state must be driven here, not by a DOMContentLoaded handler:
        // under Turbo navigation DOMContentLoaded does not fire on step changes,
        // so a page-level inline script would never bind and the spinner would
        // never appear. Stimulus reconnects on every Turbo render, so this does.
        this._setFormBusy(true);

        if (this.hasStatusMessageTarget && this.messageRunningValue) {
            this.statusMessageTarget.textContent = this.messageRunningValue;
        }
        window.alvaBus?.emit({ mood: 'scanning', reason: 'async-job-started' });

        try {
            const resp = await fetch(form.getAttribute('action') || window.location.href, {
                method: (form.getAttribute('method') || 'POST').toUpperCase(),
                body: new FormData(form),
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!resp.ok) {
                // Kickoff rejected (CSRF / validation). Surface server message.
                let msg = `Request failed (${resp.status}).`;
                try {
                    const j = await resp.json();
                    if (j && j.message) msg = j.message;
                } catch (_e) { /* non-JSON body */ }
                this._applyTerminalFailure(msg);
                return;
            }

            // Kickoff accepted ({status:'started'}) — poll for the real result.
            this._startPolling();
        } catch (_e) {
            this._applyTerminalFailure('Network error while starting the job.');
        }
    }

    // ── Private helpers ────────────────────────────────────────────────────

    // Toggle the submit button's text/spinner spans and disabled state.
    // Mirrors the markup convention `.btn-text` / `.btn-loading.d-none`.
    _setFormBusy(busy) {
        const form = this._form ?? (this.element.tagName === 'FORM' ? this.element : null);
        if (!form) return;
        const button = form.querySelector('button[type="submit"]');
        if (!button) return;

        const btnText = button.querySelector('.btn-text');
        const btnLoading = button.querySelector('.btn-loading');
        if (btnText && btnLoading) {
            btnText.classList.toggle('d-none', busy);
            btnLoading.classList.toggle('d-none', !busy);
        }
        button.disabled = busy;
    }

    _startPolling() {
        // Poll immediately, then on interval
        this._poll();
        this._timer = setInterval(() => this._poll(), this.pollIntervalValue);
    }

    _stopPolling() {
        if (this._timer !== null) {
            clearInterval(this._timer);
            this._timer = null;
        }
    }

    async _poll() {
        if (this._terminal || !this.statusUrlValue) return;

        try {
            const resp = await fetch(this.statusUrlValue, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!resp.ok) {
                // 404 → job file deleted; treat as terminal failure
                if (resp.status === 404) {
                    this._applyTerminalFailure('Job not found (deleted or expired).');
                }
                return; // other transient errors — keep polling
            }

            const data = await resp.json();
            this._applyData(data);

        } catch (_e) {
            // Network error — keep polling silently
        }
    }

    _applyData(data) {
        const status = data.status ?? 'unknown';

        // Update status badge
        if (this.hasStatusBadgeTarget) {
            const badge = this.statusBadgeTarget;
            [...badge.classList]
                .filter((c) => c.startsWith('fa-entity-badge--'))
                .forEach((c) => badge.classList.remove(c));

            const cls = {
                pending:   'fa-entity-badge--audit',
                running:   'fa-entity-badge--training',
                succeeded: 'fa-entity-badge--control',
                failed:    'fa-entity-badge--finding',
            }[status] ?? 'fa-entity-badge--audit';

            badge.classList.add(cls);
            badge.textContent = status;
        }

        // Update free-text message
        if (this.hasStatusMessageTarget && data.message) {
            this.statusMessageTarget.textContent = data.message;
        }

        // Progress bar
        const current = data.progress_current ?? 0;
        const total = data.progress_total ?? 0;
        if (total > 0 && this.hasProgressWrapperTarget) {
            this.progressWrapperTarget.style.display = '';
            const pct = Math.min(100, Math.round((current / total) * 100));
            if (this.hasProgressBarTarget) {
                this.progressBarTarget.style.width = pct + '%';
                this.progressBarTarget.setAttribute('aria-valuenow', pct);
            }
            if (this.hasProgressNumbersTarget) {
                this.progressNumbersTarget.textContent = current + ' / ' + total;
            }
            if (this.hasProgressLabelTarget && data.message) {
                this.progressLabelTarget.textContent = data.message;
            }
        }

        // Terminal states. The admin jobs endpoint emits succeeded/failed;
        // the setup-wizard endpoints emit success/failed/error — accept both.
        if (status === 'succeeded' || status === 'success') {
            this._applyTerminalSuccess(data.message ?? this.messageSuccessValue, data.payload);
        } else if (status === 'failed' || status === 'error') {
            this._applyTerminalFailure(data.message, data.error_trace);
        }
    }

    _applyTerminalSuccess(message, payload) {
        this._terminal = true;
        this._stopPolling();

        if (this.hasProgressBarTarget) {
            this.progressBarTarget.classList.remove('progress-bar-animated', 'progress-bar-striped');
            this.progressBarTarget.style.width = '100%';
        }

        if (this.hasSuccessBoxTarget) {
            this.successBoxTarget.classList.remove('d-none');
        }
        if (this.hasSuccessMessageTarget && message) {
            this.successMessageTarget.textContent = message;
        }

        // Optional: render structured payload (repair_summary, restore_result,
        // …) into a definition-list inside the resultDetails target. Jobs
        // write these via JobContext::updatePayload — see RestoreBackupJob /
        // QuickFixRepairAllJob.
        this._renderPayloadDetails(payload);

        this._showBackLink();
        window.alvaBus?.emit({ mood: 'celebrating', reason: 'async-job-succeeded', ttlMs: 5000 });

        // Submit-driven steps continue the wizard once the job succeeds. The
        // poll that observed success already let the status endpoint lift its
        // payload (e.g. setup_schema_created) into the session, so the next
        // step sees the side effects.
        if (this.redirectUrlValue) {
            window.location.assign(this.redirectUrlValue);
        }
    }

    _renderPayloadDetails(payload) {
        if (!this.hasResultDetailsTarget || !payload || typeof payload !== 'object') return;
        // Whitelisted payload keys that carry post-completion structured
        // result data. `_label` / `_subtitle` are UI-metadata, skip them.
        const keys = ['repair_summary', 'restore_result', 'apply_failure', 'reconcile_failure'];
        const fragments = [];
        for (const key of keys) {
            const block = payload[key];
            if (!block || typeof block !== 'object') continue;
            fragments.push(this._renderPayloadBlock(key, block));
        }
        if (fragments.length === 0) return;
        this.resultDetailsTarget.innerHTML = fragments.join('');
        this.resultDetailsTarget.classList.remove('d-none');
    }

    _renderPayloadBlock(key, block) {
        const rows = [];
        for (const [k, v] of Object.entries(block)) {
            const label = k.replace(/_/g, ' ');
            let value;
            if (Array.isArray(v)) {
                value = v.length === 0 ? '—' : `${v.length} item(s)`;
            } else if (v && typeof v === 'object') {
                value = `<code>${this._escapeHtml(JSON.stringify(v))}</code>`;
            } else {
                value = this._escapeHtml(String(v));
            }
            rows.push(`<dt class="col-sm-4">${this._escapeHtml(label)}</dt><dd class="col-sm-8">${value}</dd>`);
        }
        return `<div class="async-job-result-block mb-2">
            <h6 class="async-job-result-block__title">${this._escapeHtml(key.replace(/_/g, ' '))}</h6>
            <dl class="row mb-0 small">${rows.join('')}</dl>
        </div>`;
    }

    _escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    _applyTerminalFailure(message, trace) {
        this._terminal = true;
        this._stopPolling();
        // Re-enable the submit button so the user can correct input and retry.
        this._setFormBusy(false);

        if (this.hasErrorBoxTarget) {
            this.errorBoxTarget.classList.remove('d-none');
        }
        if (this.hasErrorTraceTarget && trace) {
            this.errorTraceTarget.textContent = trace;
        } else if (this.hasErrorTraceTarget && message) {
            this.errorTraceTarget.textContent = message;
        }

        // Append `?failed_job_id=<id>` to the back-link so the destination
        // page can pick up structured failure payload via JobStatusService
        // (jobs cannot write Session — see JobContext::updatePayload).
        this._appendFailedJobIdToBackLink();
        this._showBackLink();
        window.alvaBus?.emit({ mood: 'warning', reason: 'async-job-failed' });
    }

    _appendFailedJobIdToBackLink() {
        if (!this.hasBackLinkTarget) return;
        const jobId = this._extractJobIdFromStatusUrl();
        if (!jobId) return;
        const href = this.backLinkTarget.getAttribute('href');
        if (!href) return;
        try {
            const url = new URL(href, window.location.origin);
            url.searchParams.set('failed_job_id', jobId);
            this.backLinkTarget.setAttribute('href', url.pathname + url.search + url.hash);
        } catch (_) {
            // Bad href — leave it untouched rather than navigate to about:blank.
        }
    }

    _extractJobIdFromStatusUrl() {
        if (!this.statusUrlValue) return null;
        // statusUrl is shaped like `/admin/jobs/{uuid}/status` (or quick-fix variant).
        const m = /\/([0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\//.exec(this.statusUrlValue);
        return m ? m[1] : null;
    }

    _showBackLink() {
        if (this.hasBackLinkTarget) {
            this.backLinkTarget.style.display = '';
        }
    }
}
