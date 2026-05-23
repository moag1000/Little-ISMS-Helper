/**
 * Junior-ISB-Audit-2026-05-22 S14 #17: BP-Tooltips Drawer Pattern — UX-Polish
 *
 * help-drawer — Aurora fa-drawer side-sheet for verbose form-help-text.
 *
 * Compresses 5-min-Lesezeit Bootstrap-tooltips into a 30-sec summary + a
 * "Mehr Info..." link that slides in the full reference text on demand.
 *
 * Usage (single drawer-element per page, multiple triggers):
 *
 *   <div data-controller="help-drawer">
 *     {# Triggers next to form-fields #}
 *     <button type="button"
 *             data-action="click->help-drawer#open"
 *             data-help-drawer-title-param="RTO — Recovery Time Objective"
 *             data-help-drawer-body-param="<strong>...</strong><br>verbose HTML">
 *       Mehr Info...
 *     </button>
 *
 *     {# The single drawer-element + backdrop #}
 *     <div class="fa-drawer-backdrop"
 *          data-help-drawer-target="backdrop"
 *          data-action="click->help-drawer#close"></div>
 *     <aside class="fa-drawer fa-drawer--right"
 *            data-help-drawer-target="drawer"
 *            role="dialog" aria-modal="true" aria-hidden="true">
 *       <header class="fa-drawer__header">
 *         <div class="fa-drawer__header-text">
 *           <span class="fa-drawer__eyebrow">Info</span>
 *           <h2 class="fa-drawer__title" data-help-drawer-target="title"></h2>
 *         </div>
 *         <button type="button" class="fa-drawer__close"
 *                 data-action="click->help-drawer#close"
 *                 aria-label="Close">×</button>
 *       </header>
 *       <div class="fa-drawer__body" data-help-drawer-target="body"></div>
 *     </aside>
 *   </div>
 *
 * Body content is treated as HTML (the verbose tooltip values already contain
 * <strong>/<br>/<table> tags from the YAML translation files).
 */
import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['drawer', 'backdrop', 'title', 'body'];

    connect() {
        // Keep ESC closing the drawer
        this._onKeydown = (event) => {
            if (event.key === 'Escape' && this.hasDrawerTarget && this.drawerTarget.classList.contains('is-open')) {
                this.close();
            }
        };
        document.addEventListener('keydown', this._onKeydown);
    }

    disconnect() {
        document.removeEventListener('keydown', this._onKeydown);
    }

    open(event) {
        event?.preventDefault();
        if (!this.hasDrawerTarget) {
            return;
        }

        const trigger = event?.currentTarget;
        const title = trigger?.dataset.helpDrawerTitleParam ?? '';
        const body = trigger?.dataset.helpDrawerBodyParam ?? '';

        if (this.hasTitleTarget) {
            this.titleTarget.textContent = title;
        }
        if (this.hasBodyTarget) {
            // The verbose tooltip values are HTML-safe (curated translation
            // keys) — render as HTML so <strong>/<table>/<br> work.
            this.bodyTarget.innerHTML = body;
        }

        this.drawerTarget.classList.add('is-open');
        this.drawerTarget.setAttribute('aria-hidden', 'false');
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.add('is-open');
        }
    }

    close() {
        if (this.hasDrawerTarget) {
            this.drawerTarget.classList.remove('is-open');
            this.drawerTarget.setAttribute('aria-hidden', 'true');
        }
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.remove('is-open');
        }
    }
}
