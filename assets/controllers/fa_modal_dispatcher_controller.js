import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora v4.0 — fa-modal dispatcher controller
 *
 * Click-trigger that opens a fa-modal by id. Drop-in replacement for
 *   <button data-bs-toggle="modal" data-bs-target="#some-modal">
 *
 * Usage:
 *   <button data-controller="fa-modal-dispatcher"
 *           data-action="fa-modal-dispatcher#open"
 *           data-fa-modal-dispatcher-target-id-value="delete-risk">
 *       Risiko löschen
 *   </button>
 *
 * Or via Stimulus shorthand on a wrapper:
 *   <div data-controller="fa-modal-dispatcher">
 *     <button data-action="fa-modal-dispatcher#open"
 *             data-fa-modal-id="delete-risk">…</button>
 *   </div>
 *
 * For programmatic open from another controller (e.g. replacing
 * `new bootstrap.Modal(el).show()` in legacy code), dispatch directly:
 *   this.dispatch('fa-modal:request-open', { detail: { id: 'delete-risk' } });
 */
export default class extends Controller {
    static values = {
        targetId: String,
    };

    open(event) {
        event?.preventDefault();
        const id = this.#resolveTargetId(event);
        if (!id) {
            console.warn('[fa-modal-dispatcher] no target id resolved');
            return;
        }
        document.dispatchEvent(
            new CustomEvent('fa-modal:request-open', {
                bubbles: true,
                detail: { id, trigger: event?.currentTarget ?? null },
            }),
        );
    }

    #resolveTargetId(event) {
        // 1. Explicit Stimulus value on controller element
        if (this.targetIdValue) return this.targetIdValue;

        // 2. data-fa-modal-id on the click target
        const trigger = event?.currentTarget;
        if (trigger?.dataset?.faModalId) return trigger.dataset.faModalId;

        // 3. href="#modal-id" pattern
        if (trigger?.tagName === 'A' && trigger.hash?.startsWith('#')) {
            return trigger.hash.slice(1);
        }

        return null;
    }
}
