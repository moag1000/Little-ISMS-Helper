import { Controller } from '@hotwired/stimulus';

/**
 * Drives the Alva-Fee hint card:
 * - on connect, broadcasts the card's mood to the global Alva bus so
 *   the mascot can react (e.g. switch to "warning" when a tier-1
 *   regulatory hint shows)
 * - on dismiss, hides the card and persists the dismissal server-side
 *   (cross-device) plus emits a "happy" mood pulse as feedback
 */
export default class extends Controller {
    static values = {
        hintKey: String,
        entityType: String,
        entityId: Number,
        csrfToken: String,
        endpoint: String,
    };

    connect() {
        const mood = this.element.dataset.alvaMood;
        if (mood && window.alvaBus && typeof window.alvaBus.emit === 'function') {
            window.alvaBus.emit({
                mood,
                reason: `alva-hint:${this.hintKeyValue}`,
                ttlMs: 8000,
            });
        }
    }

    async dismiss(event) {
        event.preventDefault();
        const card = this.element;
        card.hidden = true;

        try {
            const body = new FormData();
            body.append('hint_key', this.hintKeyValue);
            body.append('entity_type', this.entityTypeValue || '');
            body.append('entity_id', String(this.entityIdValue || 0));
            body.append('_token', this.csrfTokenValue);

            const response = await fetch(this.endpointValue, {
                method: 'POST',
                credentials: 'same-origin',
                body,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });

            if (!response.ok) {
                // Bring the card back so the user can retry
                card.hidden = false;
                return;
            }
            if (window.alvaBus && typeof window.alvaBus.emit === 'function') {
                window.alvaBus.emit({
                    mood: 'happy',
                    reason: `alva-hint:dismissed:${this.hintKeyValue}`,
                    ttlMs: 3000,
                });
            }
        } catch (err) {
            card.hidden = false;
        }
    }
}
