import { Controller } from '@hotwired/stimulus';

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
        saveUrl: String
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
            const response = await fetch('/de/report-builder/api/widget-data', {
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

            const data = await response.json();
            this.renderWidgetContent(contentEl, widgetType, data);
        } catch (error) {
            console.error('Failed to load widget data:', error);
            contentEl.innerHTML = `<div class="alert alert-danger">Failed to load widget</div>`;
        }
    }

    renderWidgetContent(container, widgetType, data) {
        if (data.error) {
            container.innerHTML = `<div class="alert alert-warning">${data.error}</div>`;
            return;
        }

        // KPI Widgets
        if (widgetType.startsWith('kpi_')) {
            container.innerHTML = `
                <div class="text-center py-3">
                    <div class="display-5 fw-bold text-${data.color || 'primary'}">${data.value}</div>
                    <div class="text-muted small">${data.label}</div>
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
                    <i class="bi bi-circle-fill fs-1 text-${statusClass}"></i>
                    <div class="mt-2 fw-bold">${data.label}</div>
                </div>
            `;
            return;
        }

        // Table Widgets
        if (widgetType.startsWith('table_')) {
            if (data.rows && data.rows.length > 0) {
                let html = `<div class="table-responsive"><table class="table table-sm table-hover"><thead><tr>`;
                data.columns.forEach(col => {
                    html += `<th>${col}</th>`;
                });
                html += `</tr></thead><tbody>`;
                data.rows.slice(0, 5).forEach(row => {
                    html += `<tr>`;
                    Object.entries(row).forEach(([key, value]) => {
                        if (key !== 'id') {
                            html += `<td>${value || ''}</td>`;
                        }
                    });
                    html += `</tr>`;
                });
                html += `</tbody></table></div>`;
                container.innerHTML = html;
            } else {
                container.innerHTML = `<div class="text-center text-muted py-3">No data</div>`;
            }
            return;
        }

        // Chart Widgets (placeholder in designer)
        if (widgetType.startsWith('chart_')) {
            container.innerHTML = `
                <div class="text-center py-4 bg-light rounded">
                    <i class="bi bi-bar-chart-fill fs-1 text-primary opacity-50"></i>
                    <div class="small text-muted mt-2">${widgetType.replace('chart_', '').replace(/_/g, ' ')}</div>
                </div>
            `;
            return;
        }

        // Text Widgets
        if (widgetType.startsWith('text_')) {
            container.innerHTML = `<div class="p-2">${data.text || 'Text content'}</div>`;
            return;
        }

        // Default
        container.innerHTML = `<div class="text-muted text-center py-3">${widgetType}</div>`;
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
                <span class="widget-title small text-muted">${widgetType.replace(/_/g, ' ')}</span>
                <div class="widget-actions">
                    <button type="button" class="btn btn-sm btn-link p-0 text-muted"
                            data-action="click->report-designer#configureWidget">
                        <i class="bi bi-gear"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link p-0 text-danger"
                            data-action="click->report-designer#removeWidget">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            </div>
            <div class="widget-content">
                <div class="text-center py-4 text-muted">
                    <i class="bi bi-hourglass-split"></i> Loading...
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
                    <i class="bi bi-plus-circle display-4 text-muted"></i>
                    <p class="mt-3 text-muted">Drag widgets here to build your report</p>
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

        let formHtml = `<input type="hidden" id="config-widget-id" value="${widgetId}">`;

        // Title input for all widgets
        formHtml += `
            <div class="mb-3">
                <label class="form-label">Title</label>
                <input type="text" class="form-control" id="config-title" value="${config.title || ''}">
            </div>
        `;

        // Widget-specific config
        if (widgetType.startsWith('table_')) {
            formHtml += `
                <div class="mb-3">
                    <label class="form-label">Max Rows</label>
                    <input type="number" class="form-control" id="config-limit" value="${config.limit || 10}" min="1" max="50">
                </div>
            `;
        }

        if (widgetType.startsWith('text_')) {
            formHtml += `
                <div class="mb-3">
                    <label class="form-label">Content</label>
                    <textarea class="form-control" id="config-text" rows="4">${config.text || ''}</textarea>
                </div>
            `;
        }

        form.innerHTML = formHtml;

        // Show modal
        const modal = document.getElementById('widgetConfigModal');
        if (modal && window.bootstrap) {
            const bsModal = new window.bootstrap.Modal(modal);
            bsModal.show();
        }
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

        // Close modal
        const modal = document.getElementById('widgetConfigModal');
        if (modal && window.bootstrap) {
            const bsModal = window.bootstrap.Modal.getInstance(modal);
            if (bsModal) bsModal.hide();
        }
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
                this.showNotification('Report saved successfully', 'success');
            } else {
                this.showNotification(result.error || 'Failed to save', 'danger');
            }
        } catch (error) {
            console.error('Save error:', error);
            this.showNotification('Failed to save report', 'danger');
        }
    }

    async saveSharing() {
        // Get selected users from share modal
        const selectedUsers = [];
        document.querySelectorAll('#share-users-list input[type="checkbox"]:checked').forEach(cb => {
            selectedUsers.push(parseInt(cb.value));
        });

        try {
            const response = await fetch(`/de/report-builder/${this.reportIdValue}/share`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ user_ids: selectedUsers })
            });

            const result = await response.json();

            if (result.success) {
                this.showNotification('Sharing settings saved', 'success');
                const modal = document.getElementById('shareModal');
                if (modal && window.bootstrap) {
                    const bsModal = window.bootstrap.Modal.getInstance(modal);
                    if (bsModal) bsModal.hide();
                }
            }
        } catch (error) {
            console.error('Share error:', error);
            this.showNotification('Failed to save sharing settings', 'danger');
        }
    }

    showNotification(message, type = 'info') {
        // Create toast notification
        const toast = document.createElement('div');
        toast.className = `alert alert-${type} alert-dismissible fade show position-fixed`;
        toast.style.cssText = 'top: 80px; right: 20px; z-index: 9999; min-width: 300px;';
        toast.innerHTML = `
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        `;

        document.body.appendChild(toast);

        // Auto-remove after 3 seconds
        setTimeout(() => {
            toast.remove();
        }, 3000);
    }
}
