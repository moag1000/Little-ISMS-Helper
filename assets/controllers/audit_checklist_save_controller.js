import { Controller } from '@hotwired/stimulus';

/**
 * Audit Checklist Save Controller
 *
 * Collects all checklist-item state from the page (status-selects + textareas
 * marked with data-item-id + data-field) and POSTs as JSON to the save-route.
 *
 * Targets: button (the save trigger).
 *
 * Values:
 *   - url: POST endpoint URL
 *   - csrfToken: CSRF token for the save action
 */
export default class extends Controller {
    static targets = ['button'];
    static values = {
        url: String,
        csrfToken: String,
    };

    async save(event) {
        event.preventDefault();
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = true;
            this.originalText = this.buttonTarget.textContent;
            this.buttonTarget.textContent = '⏳ ' + (this.buttonTarget.dataset.savingText || 'Saving...');
        }

        const items = this.collectItems();

        try {
            const response = await fetch(this.urlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ _token: this.csrfTokenValue, items }),
            });
            const payload = await response.json();
            if (response.ok) {
                this.flash(payload.message || 'Saved.', 'success');
            } else {
                this.flash(payload.error || 'Save failed.', 'danger');
            }
        } catch (e) {
            this.flash('Network error: ' + e.message, 'danger');
        } finally {
            if (this.hasButtonTarget) {
                this.buttonTarget.disabled = false;
                this.buttonTarget.textContent = this.originalText;
            }
        }
    }

    collectItems() {
        const itemsMap = new Map();

        // Status selects
        this.element.querySelectorAll('.status-select[data-item-id]').forEach((sel) => {
            const id = sel.dataset.itemId;
            this.ensureItem(itemsMap, id);
            itemsMap.get(id).verificationStatus = sel.value;
        });

        // Textareas with data-field
        this.element.querySelectorAll('textarea[data-item-id][data-field]').forEach((ta) => {
            const id = ta.dataset.itemId;
            const field = ta.dataset.field;
            this.ensureItem(itemsMap, id);
            itemsMap.get(id)[field] = ta.value;
        });

        // Compliance-score sliders (range inputs with id pattern compliance-score-{id})
        this.element.querySelectorAll('input[type="range"][id^="compliance-score-"]').forEach((sl) => {
            const id = sl.id.replace('compliance-score-', '');
            this.ensureItem(itemsMap, id);
            itemsMap.get(id).complianceScore = parseInt(sl.value, 10);
        });

        return Array.from(itemsMap.values());
    }

    ensureItem(map, id) {
        if (!map.has(id)) {
            map.set(id, { id: parseInt(id, 10) });
        }
    }

    flash(message, variant) {
        // Canonical: dispatch on window for fa_toast_controller (V4-FP-1).
        // Also dispatch on document for any legacy listeners.
        const detail = { message, variant, tone: variant, type: variant };
        window.dispatchEvent(new CustomEvent('fa-toast:show', { detail }));
        document.dispatchEvent(new CustomEvent('toast:show', { detail }));
        // Fallback: simple alert if no toast handler is mounted.
        if (!document.querySelector('.fa-toast-stack') && !document.querySelector('.toast-container')) {
            alert(message);
        }
    }
}
