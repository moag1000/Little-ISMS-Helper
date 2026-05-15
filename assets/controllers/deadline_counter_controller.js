import { Controller } from '@hotwired/stimulus';

/**
 * Aurora `fa-deadline-counter` live-tick controller.
 *
 * Re-renders the visible "Xh remaining" / "Xh overdue" label every 60s so
 * an operator looking at an Incident show-page sees the GDPR Art. 33 72h
 * window count down without a Turbo refresh. The colour tone change
 * (success → warning → danger) still happens on full page-load only —
 * mirroring the audit-trail invariant "tone-state is captured by the
 * server" rather than client-side computed.
 *
 * Values:
 *   deadline (string)              — ISO-8601 deadline timestamp
 *   overdueTemplate (string)       — i18n template, `%hours%` placeholder
 *   remainingTemplate (string)     — i18n template, `%hours%` placeholder
 *
 * Targets:
 *   display — the <span> whose textContent we update each minute
 */
export default class extends Controller {
    static values = {
        deadline: String,
        overdueTemplate: String,
        remainingTemplate: String,
    };

    static targets = ['display'];

    connect() {
        this.update();
        // 60s cadence is enough — the pill is precision-to-hour.
        this.timer = window.setInterval(() => this.update(), 60_000);
    }

    disconnect() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    update() {
        if (!this.hasDisplayTarget || !this.deadlineValue) {
            return;
        }
        const deadlineMs = Date.parse(this.deadlineValue);
        if (Number.isNaN(deadlineMs)) {
            return;
        }
        const remainingMs = deadlineMs - Date.now();
        const overdue = remainingMs <= 0;
        const hours = Math.floor(Math.abs(remainingMs) / 3_600_000);
        const template = overdue
            ? this.overdueTemplateValue
            : this.remainingTemplateValue;
        if (!template) {
            return;
        }
        this.displayTarget.textContent = template.replace('%hours%', String(hours));
    }
}
