import { Controller } from '@hotwired/stimulus';

/**
 * Policy-Wizard Bestandsaufnahme — MUST #1: PDF preview drawer.
 *
 * Lazy-loads a PDF into a right-hand offcanvas (`<iframe>`) when the user
 * clicks the document title. Avoids fetching all (potentially 30+) PDFs
 * upfront — the iframe `src` is only set when the offcanvas opens.
 *
 * Markup contract (set up in `_bestandsaufnahme.html.twig`):
 *   <button data-action="bestandsaufnahme-drawer#open"
 *           data-bestandsaufnahme-drawer-doc-id-param="42"
 *           data-bestandsaufnahme-drawer-doc-title-param="ISMS-Leitlinie"
 *           data-bestandsaufnahme-drawer-pdf-url-param="/de/document/42/download"
 *           data-bestandsaufnahme-drawer-show-url-param="/de/document/42"
 *           data-bestandsaufnahme-drawer-mime-param="application/pdf">
 *
 *   <div class="offcanvas offcanvas-end" data-bestandsaufnahme-drawer-target="offcanvas">
 *     <div data-bestandsaufnahme-drawer-target="title"></div>
 *     <iframe data-bestandsaufnahme-drawer-target="iframe" hidden></iframe>
 *     <div data-bestandsaufnahme-drawer-target="fallback" hidden>...non-PDF hint...</div>
 *     <a data-bestandsaufnahme-drawer-target="downloadLink"></a>
 *     <a data-bestandsaufnahme-drawer-target="showLink"></a>
 *   </div>
 *
 * The offcanvas is bound by `id` so multiple triggers (one per row) can
 * share the same drawer instance.
 */
export default class extends Controller {
    static targets = ['offcanvas', 'title', 'iframe', 'fallback', 'downloadLink', 'showLink', 'mimeNote'];

    open(event) {
        const params = event.params || {};
        const title = params.docTitle || '';
        const pdfUrl = params.pdfUrl || '';
        const showUrl = params.showUrl || '';
        const mime = (params.mime || '').toLowerCase();

        if (this.hasTitleTarget) {
            this.titleTarget.textContent = title;
        }
        if (this.hasDownloadLinkTarget && pdfUrl) {
            this.downloadLinkTarget.setAttribute('href', pdfUrl);
        }
        if (this.hasShowLinkTarget && showUrl) {
            this.showLinkTarget.setAttribute('href', showUrl);
        }

        const isPdf = mime === 'application/pdf';

        if (this.hasIframeTarget && this.hasFallbackTarget) {
            if (isPdf && pdfUrl) {
                // Lazy-load: only set src when opening, clear on close to free memory.
                this.iframeTarget.setAttribute('src', pdfUrl);
                this.iframeTarget.hidden = false;
                this.fallbackTarget.hidden = true;
            } else {
                this.iframeTarget.removeAttribute('src');
                this.iframeTarget.hidden = true;
                this.fallbackTarget.hidden = false;
                if (this.hasMimeNoteTarget) {
                    this.mimeNoteTarget.textContent = mime || 'unknown';
                }
            }
        }

        this._show();
    }

    _show() {
        if (!this.hasOffcanvasTarget) {
            return;
        }
        const el = this.offcanvasTarget;
        // Bootstrap is loaded asynchronously — guard for availability before
        // calling into the global. Falls back to manual class toggle.
        if (window.bootstrap && window.bootstrap.Offcanvas) {
            const instance = window.bootstrap.Offcanvas.getOrCreateInstance(el);
            instance.show();
            // On close, drop the iframe `src` so subsequent opens re-lazy-load.
            el.addEventListener('hidden.bs.offcanvas', () => {
                if (this.hasIframeTarget) {
                    this.iframeTarget.removeAttribute('src');
                }
            }, { once: true });
        } else {
            el.classList.add('show');
            el.style.visibility = 'visible';
        }
    }
}
