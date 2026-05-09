import { Controller } from '@hotwired/stimulus';

/**
 * Toggles the visibility of the merge-/split-topic pickers based on the
 * chosen action in Step 0 (Bestandsaufnahme). Junior-ISB-friendly UX:
 * only show the topic-picker that is actually relevant for the selected
 * action.
 */
export default class extends Controller {
    static targets = ['actionSelect', 'mergeBlock', 'splitBlock'];

    connect() {
        this.updateVisibility();
    }

    updateVisibility() {
        const action = this.hasActionSelectTarget ? this.actionSelectTarget.value : '';

        if (this.hasMergeBlockTarget) {
            this.mergeBlockTarget.hidden = action !== 'merge_into_topic';
        }
        if (this.hasSplitBlockTarget) {
            this.splitBlockTarget.hidden = action !== 'split_to_topics';
        }
    }
}
