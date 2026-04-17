import { Controller } from '@hotwired/stimulus';

/**
 * Polls the pending-review count and updates the navigation badge.
 * Cheap GET, cached server-side, 60s interval.
 */
export default class extends Controller {
    static values = {
        count: Number,
        pollUrl: { type: String, default: '/en/compliance/inheritance/pending-count' },
        interval: { type: Number, default: 60000 },
    };

    connect() {
        this.updateBadge(this.countValue);
        this.timer = setInterval(() => this.poll(), this.intervalValue);
    }

    disconnect() {
        if (this.timer) clearInterval(this.timer);
    }

    async poll() {
        try {
            const response = await fetch(this.pollUrlValue, { headers: { Accept: 'application/json' } });
            if (!response.ok) return;
            const json = await response.json();
            this.updateBadge(json.count ?? 0);
        } catch (_) {
            /* swallow transient errors */
        }
    }

    updateBadge(count) {
        const badge = document.querySelector('[data-role="inheritance-pending-badge"]');
        if (!badge) return;
        if (count > 0) {
            badge.textContent = count;
            badge.classList.remove('d-none');
            badge.setAttribute('aria-label', `${count} Ableitungsvorschläge offen`);
        } else {
            badge.classList.add('d-none');
        }
    }
}
