import { Controller } from '@hotwired/stimulus';

/**
 * Heat Map Controller - Risk Heat Map Visualization
 *
 * Junior-ISB-Audit-2026-05-22 Polish: Aurora-rework Risk-Heatmap
 *
 * Renders an Aurora .isms-risk-matrix 5x5 grid populated from
 * `/analytics/api/heat-map`. Cell colors driven by data-level (low/medium/high/
 * critical) via Aurora-tokens — NO inline hex. Cell click opens fa-drawer with
 * the risk-list for that cell.
 *
 * Features:
 * - 5x5 Matrix rendering with Aurora data-level cell tokens
 * - Native HTML title= tooltip (no JS-driven overlay)
 * - Cell click -> fa-drawer (right) with risk list
 * - Keyboard navigation (arrow keys + Enter)
 * - Filter chip-row + refresh + export
 */
export default class extends Controller {
    static targets = [
        'loading',
        'container',
        'matrix',
        'grid',
        'totalLabel',
        'empty',
        'filters',
        'statusFilter',
        'categoryFilter',
        'timeFilter',
        'drawer',
        'drawerBackdrop',
        'drawerTitle',
        'drawerBody',
    ];
    static values = {
        url: String,
        locale: { type: String, default: 'de' },
    };

    connect() {
        this.boundHandleKeyboard = this.handleKeyboard.bind(this);
        this.currentFilters = { status: '', category: '', time: '' };
        this.focusedCellIndex = -1;
        document.addEventListener('keydown', this.boundHandleKeyboard);
        this.loadData();
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleKeyboard);
    }

    async loadData() {
        this.showLoading();

        try {
            const url = new URL(this.urlValue, window.location.origin);
            Object.entries(this.currentFilters).forEach(([key, value]) => {
                if (value) url.searchParams.append(key, value);
            });

            const response = await fetch(url);
            if (!response.ok) {
                const msg = response.status === 403
                    ? 'Keine Berechtigung'
                    : `Fehler ${response.status}`;
                if (typeof window.faToast === 'function') {
                    window.faToast(msg, 'danger');
                }
                this.showError();
                return;
            }

            const data = await response.json();
            this.renderHeatMap(data.matrix || [], data.total_risks || 0);
            this.hideLoading();
        } catch (_e) {
            this.showError();
        }
    }

    renderHeatMap(matrix, totalRisks) {
        if (!this.hasGridTarget) return;

        const totalCount = matrix.reduce((sum, c) => sum + (c.count || 0), 0);
        const isEmpty = totalCount === 0;

        if (this.hasEmptyTarget) {
            this.emptyTarget.classList.toggle('d-none', !isEmpty);
        }
        if (this.hasMatrixTarget) {
            this.matrixTarget.classList.toggle('d-none', isEmpty);
        }

        // Build the column-header row (corner + Probability 1..5)
        let html = '<div></div>';
        for (let p = 1; p <= 5; p++) {
            html += `<div class="isms-risk-matrix__hd">${p}<span>P${p}</span></div>`;
        }

        // Rows: Impact 5..1 (highest on top) × Probability 1..5
        for (let impact = 5; impact >= 1; impact--) {
            html += `<div class="isms-risk-matrix__hd-y">${impact}</div>`;
            for (let probability = 1; probability <= 5; probability++) {
                const cell = matrix.find(c => c.x === probability && c.y === impact);
                if (!cell) {
                    html += '<div class="isms-risk-cell" data-level="2" aria-hidden="true"></div>';
                    continue;
                }

                const score = cell.score;
                const band = cell.band || this.bandFromScore(score);
                const level = this.levelFromBand(band);
                const count = cell.count || 0;
                const bandLabel = this.getBandLabel(band);
                const tooltipKey = count === 1 ? 'cell_one' : 'cell';
                const tooltip = this.formatTooltip(tooltipKey, {
                    count,
                    band: bandLabel,
                    prob: probability,
                    impact,
                    score,
                });
                const ariaLabel = `${count} ${count === 1 ? 'Risiko' : 'Risiken'} · P${probability} × I${impact}`;
                const risksJson = this.escapeAttr(JSON.stringify(cell.risks || []));

                html += `
                    <div class="isms-risk-cell${count > 0 ? ' isms-risk-cell--has-items' : ''}"
                         data-level="${level}"
                         data-band="${band}"
                         data-x="${probability}"
                         data-y="${impact}"
                         data-count="${count}"
                         data-score="${score}"
                         data-risks='${risksJson}'
                         title="${this.escapeAttr(tooltip)}"
                         role="button"
                         tabindex="0"
                         aria-label="${this.escapeAttr(ariaLabel)}"
                         data-action="click->heat-map#showDetails keydown.enter->heat-map#showDetails keydown.space->heat-map#showDetails">
                        ${count > 0 ? count : ''}
                    </div>
                `;
            }
        }

        this.gridTarget.innerHTML = html;

        if (this.hasTotalLabelTarget) {
            this.totalLabelTarget.textContent = `${totalRisks} ${totalRisks === 1 ? 'Risiko' : 'Risiken'}`;
        }
    }

    showDetails(event) {
        const cell = event.currentTarget;
        const count = parseInt(cell.dataset.count, 10);
        if (count === 0) return;

        // Mark active cell visually
        this.gridTarget.querySelectorAll('.isms-risk-cell--active')
            .forEach(el => el.classList.remove('isms-risk-cell--active'));
        cell.classList.add('isms-risk-cell--active');

        const x = cell.dataset.x;
        const y = cell.dataset.y;
        const score = cell.dataset.score;
        const band = cell.dataset.band;
        let risks = [];
        try { risks = JSON.parse(cell.dataset.risks); } catch (_e) { risks = []; }

        if (this.hasDrawerTitleTarget) {
            this.drawerTitleTarget.textContent = `${count} · ${this.getBandLabel(band)} (P${x} × I${y} = ${score})`;
        }

        const locale = this.localeValue || 'de';
        const openLabel = this.localeValue === 'en' ? 'Open risk' : 'Risiko öffnen';

        let body = '';
        if (risks.length === 0) {
            body = `<div class="fa-drawer__desc">${this.localeValue === 'en' ? 'No risks in this cell' : 'Keine Risiken in dieser Zelle'}</div>`;
        } else {
            body += '<ul class="fa-drawer__list">';
            risks.forEach(risk => {
                const bandSlug = this.levelToBand(risk.level);
                const link = `/${locale}/risk/${risk.id}`;
                body += `
                    <li>
                        <span class="isms-risk-legend__chip" data-level="${this.levelFromBand(bandSlug)}" style="margin-right:8px;">${this.getBandLabel(bandSlug)}</span>
                        <a href="${link}" class="fa-drawer__link" title="${openLabel}">
                            <strong>${this.escapeHtml(risk.title || '')}</strong>
                        </a>
                    </li>
                `;
            });
            body += '</ul>';
        }

        if (this.hasDrawerBodyTarget) {
            this.drawerBodyTarget.innerHTML = body;
        }

        this.openDrawer();
    }

    openDrawer() {
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.add('is-open');
            this.drawerTarget.setAttribute('aria-hidden', 'false');
        }
        if (this.hasDrawerBackdropTarget) {
            this.drawerBackdropTarget.classList.add('is-open');
        }
    }

    closeDetails() {
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.remove('is-open');
            this.drawerTarget.setAttribute('aria-hidden', 'true');
        }
        if (this.hasDrawerBackdropTarget) {
            this.drawerBackdropTarget.classList.remove('is-open');
        }
        if (this.hasGridTarget) {
            this.gridTarget.querySelectorAll('.isms-risk-cell--active')
                .forEach(el => el.classList.remove('isms-risk-cell--active'));
        }
    }

    refresh() {
        this.loadData();
    }

    exportImage() {
        window.print();
    }

    showLoading() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.remove('d-none');
        }
        if (this.hasContainerTarget) {
            this.containerTarget.style.opacity = '0.4';
        }
    }

    hideLoading() {
        if (this.hasLoadingTarget) {
            this.loadingTarget.classList.add('d-none');
        }
        if (this.hasContainerTarget) {
            this.containerTarget.style.opacity = '1';
        }
    }

    showError() {
        this.hideLoading();
        if (this.hasGridTarget) {
            const msg = this.localeValue === 'en'
                ? 'Failed to load heat map data. Please try again.'
                : 'Heatmap konnte nicht geladen werden. Bitte erneut versuchen.';
            this.gridTarget.innerHTML = `
                <div class="alert alert-danger" style="grid-column:1 / -1;">
                    <i class="fa-icon fa-icon--status-warning" aria-hidden="true"></i> ${msg}
                </div>
            `;
        }
    }

    // ========== Filter Functionality ==========

    toggleFilters() {
        if (this.hasFiltersTarget) {
            this.filtersTarget.classList.toggle('d-none');
        }
    }

    applyFilters() {
        if (!this.hasStatusFilterTarget) return;
        this.currentFilters = {
            status: this.statusFilterTarget.value,
            category: this.categoryFilterTarget.value,
            time: this.timeFilterTarget.value,
        };
        this.loadData();
    }

    clearFilters() {
        if (this.hasStatusFilterTarget) this.statusFilterTarget.value = '';
        if (this.hasCategoryFilterTarget) this.categoryFilterTarget.value = '';
        if (this.hasTimeFilterTarget) this.timeFilterTarget.value = '';
        this.currentFilters = { status: '', category: '', time: '' };
        this.loadData();
    }

    // ========== Keyboard Navigation ==========

    handleKeyboard(event) {
        // Esc closes the drawer if open
        if (event.key === 'Escape' && this.hasDrawerTarget && this.drawerTarget.classList.contains('is-open')) {
            event.preventDefault();
            this.closeDetails();
            return;
        }
        if (!this.hasGridTarget) return;
        const cells = Array.from(this.gridTarget.querySelectorAll('.isms-risk-cell--has-items'));
        if (cells.length === 0) return;

        switch (event.key) {
            case 'ArrowRight': event.preventDefault(); this.navigateCells(cells, 1); break;
            case 'ArrowLeft':  event.preventDefault(); this.navigateCells(cells, -1); break;
            case 'ArrowDown':  event.preventDefault(); this.navigateCells(cells, 5); break;
            case 'ArrowUp':    event.preventDefault(); this.navigateCells(cells, -5); break;
            default: break;
        }
    }

    navigateCells(cells, direction) {
        if (this.focusedCellIndex === -1) {
            this.focusedCellIndex = 0;
        } else {
            this.focusedCellIndex += direction;
        }
        if (this.focusedCellIndex < 0) this.focusedCellIndex = cells.length - 1;
        else if (this.focusedCellIndex >= cells.length) this.focusedCellIndex = 0;

        cells[this.focusedCellIndex].focus();
        cells[this.focusedCellIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // ========== Helpers ==========

    // Aurora .isms-risk-cell[data-level] maps:
    //   level=2 -> low      (cell-2, success tint)
    //   level=3 -> medium   (cell-3, warning tint)
    //   level=4 -> high     (cell-4, warning strong)
    //   level=5 -> critical (cell-5, danger tint)
    levelFromBand(band) {
        switch (band) {
            case 'critical': return 5;
            case 'high':     return 4;
            case 'medium':   return 3;
            default:         return 2; // low
        }
    }

    // Map per-risk inherent level (numeric score 1..25) into a band slug.
    levelToBand(level) {
        const n = parseInt(level, 10) || 0;
        return this.bandFromScore(n);
    }

    bandFromScore(score) {
        if (score >= 20) return 'critical';
        if (score >= 12) return 'high';
        if (score >= 6)  return 'medium';
        return 'low';
    }

    getBandLabel(band) {
        const labels = this.localeValue === 'en'
            ? { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' }
            : { low: 'Niedrig', medium: 'Mittel', high: 'Hoch', critical: 'Kritisch' };
        return labels[band] || band;
    }

    formatTooltip(key, params) {
        const tplDe = {
            cell:     `{count} Risiken — {band} ({prob}×{impact} = {score})`,
            cell_one: `1 Risiko — {band} ({prob}×{impact} = {score})`,
        };
        const tplEn = {
            cell:     `{count} risks — {band} ({prob}×{impact} = {score})`,
            cell_one: `1 risk — {band} ({prob}×{impact} = {score})`,
        };
        const tpl = (this.localeValue === 'en' ? tplEn : tplDe)[key] || '';
        return tpl
            .replace('{count}', params.count)
            .replace('{band}', params.band)
            .replace('{prob}', params.prob)
            .replace('{impact}', params.impact)
            .replace('{score}', params.score);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text == null ? '' : String(text);
        return div.innerHTML;
    }

    escapeAttr(text) {
        return this.escapeHtml(text).replace(/'/g, '&#39;').replace(/"/g, '&quot;');
    }
}
