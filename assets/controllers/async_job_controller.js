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
 */
export default class extends Controller {
    static targets = [
        'statusBadge', 'statusMessage',
        'progressWrapper', 'progressBar', 'progressNumbers', 'progressLabel',
        'errorBox', 'errorTrace',
        'successBox', 'successMessage',
        'backLink',
    ];

    static values = {
        statusUrl: String,
        pollInterval: { type: Number, default: 3000 },
        jobId: String,
        cancelUrl: { type: String, default: '' },
    };

    connect() {
        this._timer = null;
        this._terminal = false;
        window.alvaBus?.emit({ mood: 'scanning', reason: 'async-job-pending' });
        this._startPolling();
    }

    disconnect() {
        this._stopPolling();
    }

    // ── Private helpers ────────────────────────────────────────────────────

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

        // Terminal states
        if (status === 'succeeded') {
            this._applyTerminalSuccess(data.message);
        } else if (status === 'failed') {
            this._applyTerminalFailure(data.message, data.error_trace);
        }
    }

    _applyTerminalSuccess(message) {
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

        this._showBackLink();
        window.alvaBus?.emit({ mood: 'celebrating', reason: 'async-job-succeeded', ttlMs: 5000 });
    }

    _applyTerminalFailure(message, trace) {
        this._terminal = true;
        this._stopPolling();

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
