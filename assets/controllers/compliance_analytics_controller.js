import { Controller } from '@hotwired/stimulus';
import { wireTabResize } from '../js/chart-tab-resize.js';

/**
 * compliance-analytics — Compliance-Frameworks dashboard interactivity.
 *
 * Charts render via the page's inline Chart.js script (window.Chart from
 * app.js). The comparison / overlap / roadmap tabs start hidden, so their
 * charts build at 0×0; this controller resizes them when their tab is revealed
 * (see js/chart-tab-resize.js).
 *
 * @todo H-12 (remaining) — framework filter + per-framework drilldown need a
 *       backend params contract first; tracked separately.
 */
export default class extends Controller {
    connect() {
        this._unwireTabResize = wireTabResize(this.element);
    }

    disconnect() {
        if (this._unwireTabResize) {
            this._unwireTabResize();
        }
    }
}
