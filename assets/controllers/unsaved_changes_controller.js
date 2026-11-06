import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';
import { faConfirm } from '../js/fa-alerts.js';

/**
 * Global unsaved-changes guard (UI wave F), mounted once on <body>.
 *
 * Watches every Symfony entity form on the page (a POST form that carries a
 * `…[_token]` field) and remembers when the user typed or changed something.
 * Leaving the page with such a form — a Turbo link, back/forward or closing the
 * tab — asks first. Submitting the form clears the flag.
 *
 * Not guarded: GET filter forms, bulk-action and delete button forms (no
 * Symfony token field), forms inside modals and drawers (they have their own
 * guard), and any form with `data-unsaved-guard="off"`.
 */
export default class extends Controller {
    connect() {
        this.dirty = new Set();
        this.element.addEventListener('input', this.#onEdit, true);
        this.element.addEventListener('change', this.#onEdit, true);
        this.element.addEventListener('submit', this.#onSubmit, true);
        document.addEventListener('turbo:before-visit', this.#onVisit);
        document.addEventListener('turbo:load', this.#reset);
        window.addEventListener('beforeunload', this.#onUnload);
    }

    disconnect() {
        this.element.removeEventListener('input', this.#onEdit, true);
        this.element.removeEventListener('change', this.#onEdit, true);
        this.element.removeEventListener('submit', this.#onSubmit, true);
        document.removeEventListener('turbo:before-visit', this.#onVisit);
        document.removeEventListener('turbo:load', this.#reset);
        window.removeEventListener('beforeunload', this.#onUnload);
    }

    static guarded(form) {
        if (!(form instanceof HTMLFormElement)) return false;
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return false;
        if (form.dataset.unsavedGuard === 'off') return false;
        if (form.closest('.fa-modal, .modal, .fa-drawer, [data-controller~="fa-drawer"]')) return false;

        return form.querySelector('input[name$="[_token]"]') !== null;
    }

    #onEdit = (event) => {
        const field = event.target;
        if (!field || field.type === 'hidden' || !field.form) return;
        if (this.constructor.guarded(field.form)) {
            this.dirty.add(field.form);
        }
    };

    #onSubmit = (event) => {
        this.dirty.delete(event.target);
    };

    #reset = () => {
        this.dirty = new Set([...this.dirty].filter((form) => form.isConnected));
    };

    #hasChanges() {
        this.#reset();

        return this.dirty.size > 0;
    }

    #onUnload = (event) => {
        if (!this.#hasChanges()) return;
        event.preventDefault();
        event.returnValue = '';
    };

    #onVisit = (event) => {
        if (!this.#hasChanges()) return;
        event.preventDefault();
        const url = event.detail.url;
        faConfirm(faT('unsaved_changes.message'), {
            title: faT('unsaved_changes.title'),
            confirmLabel: faT('unsaved_changes.leave'),
            cancelLabel: faT('unsaved_changes.stay'),
        }).then((leave) => {
            if (!leave) return;
            this.dirty.clear();
            if (window.Turbo && typeof window.Turbo.visit === 'function') {
                window.Turbo.visit(url);
            } else {
                window.location.href = url;
            }
        });
    };
}
