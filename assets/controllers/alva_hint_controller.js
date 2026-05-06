import { Controller } from '@hotwired/stimulus';

/**
 * Hides the surrounding Alva-Fee hint card and persists the dismissal
 * server-side so the same user never sees it again, even on a different
 * device. The card stays in the DOM for accessibility, but `hidden` is
 * applied immediately so the action feels instant.
 */
export default class extends Controller {
    static values = {
        hintKey: String,
        entityType: String,
        entityId: Number,
        csrfToken: String,
        endpoint: String,
    };

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
            }
        } catch (err) {
            card.hidden = false;
        }
    }
}
