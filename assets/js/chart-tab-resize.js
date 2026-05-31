/**
 * chart-tab-resize — fix blank/clipped charts inside Bootstrap tab panes.
 *
 * The analytics dashboards build every Chart.js chart up-front (one inline
 * `turbo:load` script per page), but the non-active tab panes start at
 * `display:none`. A chart created inside a hidden element measures its canvas
 * as 0×0, so when the user later opens that tab the chart is blank or clipped
 * and never recovers on its own.
 *
 * Bootstrap fires `shown.bs.tab` on the tab trigger once the target pane is
 * visible; at that point we resize every Chart instance inside the revealed
 * pane so it picks up the now-real dimensions. This is the concrete piece of
 * H-12 the per-dashboard Stimulus controllers exist for.
 */

/** Resize every Chart.js instance whose <canvas> lives inside `container`. */
export function resizeChartsIn(container) {
    if (!container || !window.Chart || !window.Chart.getChart) {
        return;
    }
    container.querySelectorAll('canvas').forEach((canvas) => {
        const chart = window.Chart.getChart(canvas);
        if (chart) {
            chart.resize();
        }
    });
}

/**
 * Wire `shown.bs.tab` on `element` so charts in a newly-revealed pane resize.
 * Returns an unbind function for the Stimulus controller's disconnect().
 */
export function wireTabResize(element) {
    const handler = (event) => {
        const selector = event.target && event.target.getAttribute('data-bs-target');
        const pane = selector ? document.querySelector(selector) : null;
        // Wait one frame so the pane's `display` has actually flipped to block
        // before Chart.js measures it.
        requestAnimationFrame(() => resizeChartsIn(pane));
    };
    element.addEventListener('shown.bs.tab', handler);
    return () => element.removeEventListener('shown.bs.tab', handler);
}
