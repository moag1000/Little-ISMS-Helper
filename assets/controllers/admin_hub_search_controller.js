import { Controller } from '@hotwired/stimulus';

/**
 * Client-side filter for the admin hub search box.
 *
 * Filters the visible Hub-Cards by matching the user's query against
 * each card's `data-hub-card-search` attribute (lower-cased title +
 * description). Empty groups are hidden so the layout doesn't show
 * floating section headers.
 */
export default class extends Controller {
    static targets = ['input', 'group', 'grid', 'empty'];

    filter() {
        const query = (this.inputTarget.value || '').trim().toLowerCase();
        let totalVisible = 0;

        this.groupTargets.forEach((group) => {
            const cards = group.querySelectorAll('[data-hub-card-search]');
            let visibleInGroup = 0;
            cards.forEach((card) => {
                const haystack = card.getAttribute('data-hub-card-search') || '';
                const matches = query === '' || haystack.includes(query);
                card.hidden = !matches;
                if (matches) {
                    visibleInGroup += 1;
                }
            });
            group.hidden = visibleInGroup === 0;
            totalVisible += visibleInGroup;
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = totalVisible !== 0 || query === '';
        }
    }
}
