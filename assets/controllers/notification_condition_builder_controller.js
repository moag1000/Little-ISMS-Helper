import { Controller } from '@hotwired/stimulus';

/**
 * notification-condition-builder — add/remove chip rows in ConditionBuilderType.
 *
 * The collection container must carry:
 *   data-controller="notification-condition-builder"
 *   data-notification-condition-builder-prototype-value="__condition_index__"
 *
 * Symfony sets `data-prototype` on the <ul>/<div> element that wraps the
 * CollectionType. We read it, replace the placeholder index, and append.
 *
 * The "Add condition" button uses data-action="click->notification-condition-builder#addRow".
 * Each row prototype should include a remove button wired to removeRow.
 */
export default class extends Controller {
    static values = {
        prototype: { type: String, default: '__condition_index__' },
    };

    get index() {
        return this.element.querySelectorAll('.condition-row').length;
    }

    addRow(event) {
        event.preventDefault();

        const prototype = this.element.dataset.prototype;
        if (!prototype) {
            return;
        }

        const newIndex = this.index;
        const html     = prototype.replace(
            new RegExp(this.escapeRegExp(this.prototypeValue), 'g'),
            String(newIndex),
        );

        const wrapper = document.createElement('div');
        wrapper.className = 'condition-row d-flex gap-2 align-items-start mb-2';
        wrapper.innerHTML = html;

        const removeBtn = document.createElement('button');
        removeBtn.type      = 'button';
        removeBtn.className = 'btn btn-sm btn-outline-danger mt-1';
        removeBtn.setAttribute('aria-label', 'Remove condition');
        removeBtn.innerHTML = '<i class="fa-icon fa-icon--trash" aria-hidden="true"></i>';
        removeBtn.addEventListener('click', () => wrapper.remove());
        wrapper.appendChild(removeBtn);

        // Insert before the "add" button if it's inside the container, else append
        const addBtn = this.element.querySelector('[data-action*="addRow"]');
        if (addBtn && addBtn.parentNode === this.element) {
            this.element.insertBefore(wrapper, addBtn);
        } else {
            this.element.appendChild(wrapper);
        }
    }

    connect() {
        // Wrap existing rows (pre-filled from entity) with the condition-row class + remove button
        this.element.querySelectorAll('[data-form-collection-entry]').forEach(entry => {
            if (!entry.classList.contains('condition-row')) {
                entry.classList.add('condition-row', 'd-flex', 'gap-2', 'align-items-start', 'mb-2');
                const removeBtn = document.createElement('button');
                removeBtn.type      = 'button';
                removeBtn.className = 'btn btn-sm btn-outline-danger mt-1';
                removeBtn.setAttribute('aria-label', 'Remove condition');
                removeBtn.innerHTML = '<i class="fa-icon fa-icon--trash" aria-hidden="true"></i>';
                removeBtn.addEventListener('click', () => entry.remove());
                entry.appendChild(removeBtn);
            }
        });
    }

    escapeRegExp(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }
}
