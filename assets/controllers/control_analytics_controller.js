import { Controller } from '@hotwired/stimulus';
import { wireTabResize } from '../js/chart-tab-resize.js';

/**
 * control-analytics — Control-Effectiveness dashboard interactivity.
 *
 * Charts render via the page's inline Chart.js script (window.Chart from
 * app.js). The overview / performance / aging / orphans tabs start hidden, so
 * their charts build at 0×0; this controller resizes them when their tab is
 * revealed (see js/chart-tab-resize.js).
 *
 * @todo H-12 (remaining) — effectiveness filter + per-control drilldown need a
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
