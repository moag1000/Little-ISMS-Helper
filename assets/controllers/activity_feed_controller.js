import { Controller } from '@hotwired/stimulus';

/**
 * activity-feed — structural hook for the .isms-trail activity feed widget.
 *
 * Currently purely presentational (CSS-driven). The Stimulus hook exists for
 * future "load more" infinite-scroll / live-update via Turbo Streams.
 *
 * @todo H-12 — wire pagination / live updates.
 */
export default class extends Controller {
    connect() {
        // No-op.
    }
}
