import { Controller } from '@hotwired/stimulus';

/**
 * Live-preview for the per-tenant Report-Doc Style configurator.
 *
 * Listens for changes inside the form and POSTs the current
 * style-config snapshot to /admin/report-style/preview, then injects
 * the returned HTML fragment into the preview frame. Debounced ~300 ms
 * to avoid spamming the backend during slider drags or audience-toggle
 * cascades.
 *
 * Tenant isolation: the backend always re-resolves the tenant from
 * TenantContext, so no tenant_id is included in the payload.
 *
 * Targets:
 *   - frame          : container that receives the rendered HTML
 *   - opacitySlider  : range-input 0–100 driving the float field
 *   - opacity        : NumberType field (hidden) holding 0.0–1.0
 *   - opacityLabel   : <span> displaying the current %
 *   - chartScheme    : the chart-color-scheme select (used for audience-aware logging only)
 *
 * Values:
 *   - previewUrl : URL to POST style-config to
 *   - csrf       : CSRF token for the preview endpoint
 */
export default class extends Controller {
    static targets = ['frame', 'opacitySlider', 'opacity', 'opacityLabel', 'chartScheme'];
    static values = {
        previewUrl: String,
        csrf: String,
    };

    connect() {
        this.refreshTimer = null;
    }

    disconnect() {
        if (this.refreshTimer) {
            clearTimeout(this.refreshTimer);
        }
    }

    /**
     * Range-slider 0–100 → float 0–1 + label %.
     */
    syncOpacity(event) {
        const pct = parseInt(event.target.value, 10);
        if (this.hasOpacityTarget) {
            this.opacityTarget.value = (pct / 100).toFixed(2);
        }
        if (this.hasOpacityLabelTarget) {
            this.opacityLabelTarget.textContent = `${pct}%`;
        }
        this.refresh();
    }

    /**
     * Debounced refresh of the preview pane.
     */
    refresh() {
        if (!this.hasPreviewUrlValue) {
            return;
        }
        if (this.refreshTimer) {
            clearTimeout(this.refreshTimer);
        }
        this.refreshTimer = setTimeout(() => this._renderPreview(), 300);
    }

    async _renderPreview() {
        const payload = this._collectPayload();
        try {
            const resp = await fetch(this.previewUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': this.csrfValue,
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'application/json',
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ...payload, _preview_token: this.csrfValue }),
            });
            if (!resp.ok) {
                return;
            }
            const data = await resp.json();
            if (data && typeof data.html === 'string' && this.hasFrameTarget) {
                this.frameTarget.innerHTML = data.html;
            }
        } catch (e) {
            // Silent fail — UI stays on last good preview.
            // eslint-disable-next-line no-console
            console.warn('[report-style-preview] preview refresh failed', e);
        }
    }

    /**
     * Map form field names to the canonical style_config keys the
     * backend macro consumes.
     */
    _collectPayload() {
        const map = {
            'tenant_report_style[reportDocCoverPattern]': 'cover_pattern',
            'tenant_report_style[reportDocDefaultAudience]': 'default_audience',
            'tenant_report_style[reportDocFontFamily]': 'font_family',
            'tenant_report_style[reportDocPageOrientation]': 'page_orientation',
            'tenant_report_style[reportDocChartColorScheme]': 'chart_color_scheme',
            'tenant_report_style[reportDocWatermarkEnabled]': 'watermark_enabled',
            'tenant_report_style[reportDocWatermarkOpacity]': 'watermark_opacity',
            'tenant_report_style[reportDocShowExecSummary]': 'show_exec_summary',
            'tenant_report_style[reportDocShowAppendix]': 'show_appendix',
            'tenant_report_style[reportDocShowDistributionList]': 'show_distribution_list',
            'tenant_report_style[reportDocFooterDisclaimer]': 'footer_disclaimer',
        };

        const out = {};
        const root = this.element;

        Object.entries(map).forEach(([fieldName, configKey]) => {
            const el = root.querySelector(`[name="${fieldName}"]`);
            if (!el) return;
            if (el.type === 'checkbox') {
                out[configKey] = el.checked;
            } else if (el.type === 'number' || el.type === 'range') {
                const num = parseFloat(el.value);
                out[configKey] = Number.isFinite(num) ? num : 0;
            } else {
                out[configKey] = el.value;
            }
        });

        // Watermark opacity may also be set via the slider when the
        // hidden NumberType field hasn't fired yet.
        if (this.hasOpacitySliderTarget) {
            const pct = parseInt(this.opacitySliderTarget.value, 10);
            if (!Number.isNaN(pct)) {
                out.watermark_opacity = pct / 100;
            }
        }

        return out;
    }
}
