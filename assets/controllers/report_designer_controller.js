import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

/**
 * Report Designer Controller
 *
 * Phase 7C: Handles drag & drop widget placement, saving, and configuration
 * for the custom report builder.
 */
export default class extends Controller {
    static targets = [
        'canvas',
        'placeholder',
        'nameInput',
        'reportTitle',
        'categorySelect',
        'layoutSelect',
        'dateRangeSelect',
        'primaryColor',
        'showLogo',
        'showDate',
        'orientation',
        'widgetContent'
    ];

    static values = {
        reportId: Number,
        saveUrl: String,
        widgetDataUrl: String,
        shareUrl: String
    };

    connect() {
        this.widgets = [];
        this.draggedWidget = null;
        this.isDirty = false;
        this.loadExistingWidgets();

        // Auto-save on input changes
        if (this.hasNameInputTarget) {
            this.nameInputTarget.addEventListener('input', () => {
                this.isDirty = true;
                if (this.hasReportTitleTarget) {
                    this.reportTitleTarget.textContent = this.nameInputTarget.value;
                }
            });
        }

        // Warn before leaving with unsaved changes
        window.addEventListener('beforeunload', (e) => {
            if (this.isDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    loadExistingWidgets() {
        if (this.hasCanvasTarget) {
            const containers = this.canvasTarget.querySelectorAll('.widget-container');
            containers.forEach(container => {
                const widgetId = container.dataset.widgetId;
                const widgetType = container.dataset.widgetType;
                const widgetConfig = JSON.parse(container.dataset.widgetConfig || '{}');

                this.widgets.push({
                    id: widgetId,
                    type: widgetType,
                    config: widgetConfig
                });

                // Load widget data
                this.loadWidgetData(container, widgetType, widgetConfig);
            });
        }
    }

    async loadWidgetData(container, widgetType, config) {
        const contentEl = container.querySelector('.widget-content');
        if (!contentEl) return;

        try {
            const response = await fetch(this.widgetDataUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    type: widgetType,
                    config: config,
                    filters: this.getFilters()
                })
            });

            if (!response.ok) {
                const msg = response.status === 403
                    ? faT('forbidden')
                    : faT('error_status', { '%status%': response.status });
                this.renderMessage(contentEl, msg, 'danger');
                window.faToast(msg, 'danger');
                return;
            }

            const data = await response.json();
            this.renderWidgetContent(contentEl, widgetType, data);
        } catch (error) {
            console.error('Failed to load widget data:', error);
            this.renderMessage(contentEl, faT('report_designer.widget_load_failed'), 'danger');
        }
    }

    renderWidgetContent(container, widgetType, data) {
        const esc = (v) => this.escapeHtml(v);
        if (data.error) {
            this.renderMessage(container, data.error, 'warning');
            return;
        }

        // KPI Widgets
        if (widgetType.startsWith('kpi_')) {
            const tone = ['primary', 'success', 'warning', 'danger', 'info'].includes(data.color) ? data.color : 'primary';
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="display-5 fw-bold text-${tone}">${esc(data.value)}</div>
                    <div class="text-muted small">${esc(data.label)}</div>
                </div>
            `;
            return;
        }

        // Status Widget
        if (widgetType === 'status_rag') {
            const statusClass = data.status === 'green' ? 'success' :
                               data.status === 'amber' ? 'warning' : 'danger';
            container.innerHTML = `
                <div class="text-center py-3">
                    <i class="fa-icon fa-icon--status-ok fs-1 text-${statusClass}" aria-hidden="true"></i>
                    <div class="mt-2 fw-bold">${esc(data.label)}</div>
                </div>
            `;
            return;
        }

        // Table Widgets — Aurora fa-table
        if (widgetType.startsWith('table_')) {
            if (data.rows && data.rows.length > 0) {
                let html = '<div class="fa-table-wrap"><table class="fa-table fa-table--compact fa-table--hover">'
                    + '<thead class="fa-table__head"><tr>';
                (data.columns || []).forEach(col => {
                    html += `<th class="fa-table__th" scope="col">${esc(col)}</th>`;
                });
                html += '</tr></thead><tbody class="fa-table__body">';
                data.rows.slice(0, 5).forEach(row => {
                    html += '<tr class="fa-table__row">';
                    Object.entries(row).forEach(([key, value]) => {
                        if (key !== 'id') {
                            html += `<td class="fa-table__cell">${esc(value ?? '')}</td>`;
                        }
                    });
                    html += '</tr>';
                });
                html += '</tbody></table></div>';
                container.innerHTML = html;
            } else {
                this.renderMessage(container, faT('report_designer.no_data'), 'muted');
            }
            return;
        }

        // Chart Widgets (placeholder in designer)
        if (widgetType.startsWith('chart_')) {
            container.innerHTML = `
                <div class="text-center py-4 rounded">
                    <i class="fa-icon fa-icon--nav-bar-chart fs-1 text-primary opacity-50" aria-hidden="true"></i>
                    <div class="small text-muted mt-2">${esc(widgetType.replace('chart_', '').replace(/_/g, ' '))}</div>
                </div>
            `;
            return;
        }

        // Text Widgets
        if (widgetType.startsWith('text_')) {
            container.innerHTML = `<div class="p-2">${esc(data.text || faT('report_designer.text_placeholder'))}</div>`;
            return;
        }

        // Default
        this.renderMessage(container, widgetType, 'muted');
    }

    /** Plain-text message inside a widget (Aurora alert for warning/danger). */
    renderMessage(container, message, tone) {
        const el = document.createElement('div');
        if (tone === 'muted') {
            el.className = 'text-center text-muted py-3';
        } else {
            el.className = `fa-alert fa-alert--${tone}`;
            el.setAttribute('role', 'alert');
        }
        el.textContent = message;
        container.replaceChildren(el);
    }

    escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    getFilters() {
        const filters = {};

        if (this.hasDateRangeSelectTarget) {
            filters.dateRange = this.dateRangeSelectTarget.value;
        }

        return filters;
    }

    dragStart(event) {
        const widgetItem = event.target.closest('.widget-item');
        if (widgetItem) {
            this.draggedWidget = {
                type: widgetItem.dataset.widgetType,
                isNew: true
            };
            widgetItem.classList.add('dragging');
            event.dataTransfer.effectAllowed = 'copy';
            event.dataTransfer.setData('text/plain', widgetItem.dataset.widgetType);
        }
    }

    dragOver(event) {
        event.preventDefault();
        event.dataTransfer.dropEffect = 'copy';

        if (this.hasCanvasTarget) {
            this.canvasTarget.classList.add('drag-over');
        }
    }

    drop(event) {
        event.preventDefault();

        if (this.hasCanvasTarget) {
            this.canvasTarget.classList.remove('drag-over');
        }

        const widgetType = event.dataTransfer.getData('text/plain');
        if (widgetType) {
            this.addWidget(widgetType);
        }

        // Reset drag state
        document.querySelectorAll('.widget-item.dragging').forEach(el => {
            el.classList.remove('dragging');
        });
        this.draggedWidget = null;
    }

    addWidget(widgetType, config = {}) {
        const widgetId = 'widget_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);

        const widget = {
            id: widgetId,
            type: widgetType,
            config: config
        };

        this.widgets.push(widget);
        this.isDirty = true;

        // Remove placeholder if exists
        if (this.hasPlaceholderTarget) {
            this.placeholderTarget.remove();
        }

        // Create widget container
        const container = document.createElement('div');
        container.className = 'widget-container';
        container.dataset.widgetId = widgetId;
        container.dataset.widgetType = widgetType;
        container.dataset.widgetConfig = JSON.stringify(config);

        container.innerHTML = `
            <div class="widget-header d-flex justify-content-between align-items-center mb-2">
                <span class="widget-title small text-muted">${this.escapeHtml(widgetType.replace(/_/g, ' '))}</span>
                <div class="widget-actions">
                    <button type="button" class="fa-cyber-btn fa-cyber-btn--ghost fa-cyber-btn--sm"
                            aria-label="${this.escapeHtml(faT('report_designer.configure'))}"
                            data-action="click->report-designer#configureWidget">
                        <i class="fa-icon fa-icon--ui-settings" aria-hidden="true"></i>
                    </button>
                    <button type="button" class="fa-cyber-btn fa-cyber-btn--ghost fa-cyber-btn--sm fa-cyber-btn--danger"
                            aria-label="${this.escapeHtml(faT('report_designer.remove'))}"
                            data-action="click->report-designer#removeWidget">
                        <i class="fa-icon fa-icon--ui-trash" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="widget-content">
                <div class="text-center py-4 text-muted">
                    <i class="fa-icon fa-icon--ui-hourglass" aria-hidden="true"></i> ${this.escapeHtml(faT('loading'))}
                </div>
            </div>
        `;

        this.canvasTarget.appendChild(container);

        // Load widget data
        this.loadWidgetData(container, widgetType, config);
    }

    removeWidget(event) {
        const container = event.target.closest('.widget-container');
        if (container) {
            const widgetId = container.dataset.widgetId;
            this.widgets = this.widgets.filter(w => w.id !== widgetId);
            container.remove();
            this.isDirty = true;

            // Show placeholder if no widgets
            if (this.widgets.length === 0 && this.hasCanvasTarget) {
                const placeholder = document.createElement('div');
                placeholder.className = 'drop-placeholder text-center py-5';
                placeholder.dataset.reportDesignerTarget = 'placeholder';
                placeholder.innerHTML = `
                    <i class="fa-icon fa-icon--ui-plus display-4 text-muted" aria-hidden="true"></i>
                    <p class="mt-3 text-muted">${this.escapeHtml(faT('report_designer.drop_here'))}</p>
                `;
                this.canvasTarget.appendChild(placeholder);
            }
        }
    }

    configureWidget(event) {
        const container = event.target.closest('.widget-container');
        if (!container) return;

        const widgetId = container.dataset.widgetId;
        const widgetType = container.dataset.widgetType;
        const config = JSON.parse(container.dataset.widgetConfig || '{}');

        // Build config form based on widget type
        const form = document.getElementById('widget-config-form');
        if (!form) return;

        const esc = (v) => this.escapeHtml(v);
        let formHtml = `<input type="hidden" id="config-widget-id" value="${esc(widgetId)}">`;

        // Title input for all widgets
        formHtml += `
            <div class="mb-3">
                <label class="form-label" for="config-title">${esc(faT('report_designer.config_title'))}</label>
                <input type="text" class="form-control" id="config-title" value="${esc(config.title || '')}">
            </div>
        `;

        // Widget-specific config
        if (widgetType.startsWith('table_')) {
            formHtml += `
                <div class="mb-3">
                    <label class="form-label" for="config-limit">${esc(faT('report_designer.config_limit'))}</label>
                    <input type="number" class="form-control" id="config-limit" value="${esc(config.limit || 10)}" min="1" max="50">
                </div>
            `;
        }

        if (widgetType.startsWith('text_')) {
            formHtml += `
                <div class="mb-3">
                    <label class="form-label" for="config-text">${esc(faT('report_designer.config_text'))}</label>
                    <textarea class="form-control" id="config-text" rows="4">${esc(config.text || '')}</textarea>
                </div>
            `;
        }

        form.innerHTML = formHtml;

        // Open fa-modal shell
        document.dispatchEvent(new CustomEvent('fa-modal:request-open', {
            bubbles: true,
            detail: { id: 'widgetConfigModal' },
        }));
    }

    applyWidgetConfig() {
        const widgetId = document.getElementById('config-widget-id')?.value;
        if (!widgetId) return;

        const container = this.canvasTarget.querySelector(`[data-widget-id="${widgetId}"]`);
        if (!container) return;

        const widget = this.widgets.find(w => w.id === widgetId);
        if (!widget) return;

        // Gather config from form
        const titleInput = document.getElementById('config-title');
        const limitInput = document.getElementById('config-limit');
        const textInput = document.getElementById('config-text');

        if (titleInput) widget.config.title = titleInput.value;
        if (limitInput) widget.config.limit = parseInt(limitInput.value);
        if (textInput) widget.config.text = textInput.value;

        // Update container data
        container.dataset.widgetConfig = JSON.stringify(widget.config);

        // Update title display
        const titleEl = container.querySelector('.widget-title');
        if (titleEl && widget.config.title) {
            titleEl.textContent = widget.config.title;
        }

        // Reload widget data
        this.loadWidgetData(container, widget.type, widget.config);

        this.isDirty = true;

        // Close fa-modal shell
        const modal = document.getElementById('widgetConfigModal');
        const faModal = modal ? this.application.getControllerForElementAndIdentifier(modal, 'fa-modal') : null;
        faModal?.close();
    }

    changeLayout() {
        if (this.hasLayoutSelectTarget && this.hasCanvasTarget) {
            this.canvasTarget.dataset.layout = this.layoutSelectTarget.value;
            this.isDirty = true;
        }
    }

    async save() {
        const data = {
            name: this.hasNameInputTarget ? this.nameInputTarget.value : null,
            category: this.hasCategorySelectTarget ? this.categorySelectTarget.value : null,
            layout: this.hasLayoutSelectTarget ? this.layoutSelectTarget.value : null,
            widgets: this.widgets.map(w => ({
                id: w.id,
                type: w.type,
                config: w.config || {}
            })),
            filters: this.getFilters(),
            styles: {
                primaryColor: this.hasPrimaryColorTarget ? this.primaryColorTarget.value : '#0d6efd',
                headerLogo: this.hasShowLogoTarget ? this.showLogoTarget.checked : true,
                showGeneratedDate: this.hasShowDateTarget ? this.showDateTarget.checked : true,
                pageOrientation: this.hasOrientationTarget ? this.orientationTarget.value : 'portrait'
            }
        };

        try {
            const response = await fetch(this.saveUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(data)
            });

            const result = await response.json();

            if (result.success) {
                this.isDirty = false;
                this.showNotification(faT('report_designer.saved'), 'success');
            } else {
                this.showNotification(result.error || faT('report_designer.save_failed'), 'danger');
            }
        } catch (error) {
            console.error('Save error:', error);
            this.showNotification(faT('report_designer.save_failed'), 'danger');
        }
    }

    async saveSharing() {
        // Get selected users from share modal
        const selectedUsers = [];
        document.querySelectorAll('#share-users-list input[type="checkbox"]:checked').forEach(cb => {
            selectedUsers.push(parseInt(cb.value));
        });

        try {
            const response = await fetch(this.shareUrlValue, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ user_ids: selectedUsers })
            });

            if (!response.ok) {
                const msg = response.status === 403
                    ? faT('forbidden')
                    : faT('error_status', { '%status%': response.status });
                window.faToast(msg, 'danger');
                return;
            }

            const result = await response.json();

            if (result.success) {
                this.showNotification(faT('report_designer.sharing_saved'), 'success');
                const modal = document.getElementById('shareModal');
                const faModal = modal ? this.application.getControllerForElementAndIdentifier(modal, 'fa-modal') : null;
                faModal?.close();
            } else {
                this.showNotification(result.error || faT('report_designer.sharing_failed'), 'danger');
            }
        } catch (error) {
            console.error('Share error:', error);
            this.showNotification(faT('report_designer.sharing_failed'), 'danger');
        }
    }

    showNotification(message, type = 'info') {
        // Aurora toast stack (fa-alerts.js)
        if (typeof window.faToast === 'function') {
            window.faToast(message, type);
        }
    }
}
