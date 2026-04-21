import { Controller } from '@hotwired/stimulus';

/**
 * Guided Tour Controller (Sprint 13 / S13-1).
 *
 * Kein externes Library-Dep — eigener Stimulus-Controller für volle
 * Kontrolle über Styling, A11y, Turbo-Integration.
 *
 * Usage in Template (typischerweise einmal in base.html.twig gemountet):
 *
 *     <div data-controller="guided-tour"
 *          data-guided-tour-steps-url-value="{{ path('app_guided_tour_steps', {role: active_role}) }}"
 *          data-guided-tour-complete-url-value="{{ path('app_guided_tour_complete', {role: active_role}) }}"
 *          data-guided-tour-csrf-value="{{ csrf_token('guided_tour_complete') }}"
 *          data-guided-tour-auto-start-value="false">
 *     </div>
 *
 * Action-Trigger (z. B. Banner-Button, Menü-Link):
 *
 *     <button data-action="click->guided-tour#start"
 *             data-guided-tour-role-param="junior">Start Tour</button>
 *
 * Features:
 *  - Schritt-Popover mit Backdrop, Highlight-Overlay
 *  - Keyboard: ←/→ Schritte, ESC beenden, Tab-Focus-Trap im Popover
 *  - aria-live-Announce bei Step-Wechsel
 *  - prefers-reduced-motion respektieren
 *  - LocalStorage-Persistenz des aktuellen Schritts (resumable)
 *  - Mobile < 768 px: Fallback-Info statt Tour
 *  - Markiert bei Ende via CSRF-POST als completed
 */
export default class extends Controller {
    static values = {
        stepsUrl: String,
        completeUrl: String,
        csrf: String,
        autoStart: { type: Boolean, default: false },
        storagePrefix: { type: String, default: 'lih.tour.' },
        mobileBreakpoint: { type: Number, default: 768 },
    };

    steps = [];
    currentIndex = 0;
    tourId = null;
    popoverEl = null;
    backdropEl = null;
    highlightEl = null;
    liveRegionEl = null;
    previouslyFocusedElement = null;
    reducedMotion = false;

    connect() {
        this.reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches ?? false;

        this.boundHandleKeydown = this.handleKeydown.bind(this);
        this.boundHandleResize = this.handleResize.bind(this);

        // Resume-Check: wenn eine Tour vor einer Turbo-Navigation in localStorage
        // gespeichert wurde, nehmen wir sie auf dieser Seite direkt wieder auf
        // (ohne neuen Fetch — Steps liegen komplett im Resume-Blob).
        if (this.tryResumeAfterNavigation()) {
            return;
        }

        if (this.autoStartValue && !this.isMobile()) {
            this.start({ params: { role: this.extractRoleFromUrl() } });
        }
    }

    tryResumeAfterNavigation() {
        if (this.isMobile()) return false;
        const key = this.storagePrefixValue + 'resume';
        const raw = localStorage.getItem(key);
        if (!raw) return false;
        let payload;
        try {
            payload = JSON.parse(raw);
        } catch (e) {
            localStorage.removeItem(key);
            return false;
        }
        // Resume-Blob nur kurzzeitig gültig (5 Minuten) damit verwaiste
        // Blobs keine unerwartete Tour auf späteren Seiten triggern.
        if (!payload?.steps || !Array.isArray(payload.steps) || (Date.now() - (payload.at ?? 0)) > 5 * 60 * 1000) {
            localStorage.removeItem(key);
            return false;
        }
        localStorage.removeItem(key);

        this.steps = payload.steps;
        this.currentIndex = Math.min(payload.index ?? 0, this.steps.length - 1);
        this.tourId = payload.tour_id ?? this.extractRoleFromUrl();

        this.previouslyFocusedElement = document.activeElement;
        document.addEventListener('keydown', this.boundHandleKeydown);
        window.addEventListener('resize', this.boundHandleResize);

        this.buildUi();
        this.renderCurrentStep();
        return true;
    }

    disconnect() {
        this.cleanup();
    }

    isMobile() {
        return window.innerWidth < this.mobileBreakpointValue;
    }

    extractRoleFromUrl() {
        const match = this.stepsUrlValue.match(/\/tour\/steps\/([a-z_]+)/);
        return match ? match[1] : 'junior';
    }

    async start(event) {
        const role = event?.params?.role ?? this.extractRoleFromUrl();

        if (this.isMobile()) {
            this.showMobileHint();
            return;
        }

        try {
            const url = this.stepsUrlValue.replace(/\/[a-z_]+$/, `/${role}`);
            const response = await fetch(url, { headers: { Accept: 'application/json' } });
            if (!response.ok) throw new Error(`HTTP ${response.status}`);
            const data = await response.json();
            this.steps = data.steps ?? [];
            this.tourId = data.tour_id;
        } catch (error) {
            console.error('[guided-tour] Failed to load steps:', error);
            return;
        }

        if (this.steps.length === 0) return;

        // Resume from persisted step if available
        const savedStep = parseInt(localStorage.getItem(this.storageKey()), 10);
        this.currentIndex = Number.isFinite(savedStep) && savedStep < this.steps.length ? savedStep : 0;

        this.previouslyFocusedElement = document.activeElement;
        document.addEventListener('keydown', this.boundHandleKeydown);
        window.addEventListener('resize', this.boundHandleResize);

        this.buildUi();
        this.renderCurrentStep();
    }

    storageKey() {
        return `${this.storagePrefixValue}${this.tourId ?? 'unknown'}.step`;
    }

    buildUi() {
        // Backdrop
        this.backdropEl = document.createElement('div');
        this.backdropEl.className = 'guided-tour-backdrop';
        this.backdropEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(this.backdropEl);

        // Highlight (cut-out around target)
        this.highlightEl = document.createElement('div');
        this.highlightEl.className = 'guided-tour-highlight';
        this.highlightEl.setAttribute('aria-hidden', 'true');
        document.body.appendChild(this.highlightEl);

        // Popover (the step card)
        this.popoverEl = document.createElement('div');
        this.popoverEl.className = 'guided-tour-popover';
        this.popoverEl.setAttribute('role', 'dialog');
        this.popoverEl.setAttribute('aria-modal', 'true');
        this.popoverEl.setAttribute('aria-labelledby', 'guided-tour-title');
        this.popoverEl.setAttribute('aria-describedby', 'guided-tour-body');
        document.body.appendChild(this.popoverEl);

        // Live region for screen reader announcements on step change
        this.liveRegionEl = document.createElement('div');
        this.liveRegionEl.className = 'visually-hidden';
        this.liveRegionEl.setAttribute('aria-live', 'polite');
        this.liveRegionEl.setAttribute('role', 'status');
        document.body.appendChild(this.liveRegionEl);
    }

    renderCurrentStep() {
        const step = this.steps[this.currentIndex];
        if (!step) return;

        // FairyAurora: Seite navigieren wenn Step eine eigene URL hat und
        // wir gerade nicht schon dort sind. Erlaubt dass die Tour durch
        // mehrere Seiten führt und die besprochene Ansicht im Hintergrund zeigt.
        if (step.url && window.location.pathname !== step.url) {
            // Save tour state vor Navigation damit wir im resume-check
            // auf der neuen Seite weiter machen können.
            try {
                localStorage.setItem(this.storagePrefixValue + 'resume', JSON.stringify({
                    index: this.currentIndex,
                    steps: this.steps,
                    tour_id: this.tourId,
                    at: Date.now(),
                }));
            } catch (e) { /* quota */ }

            if (window.Turbo && typeof window.Turbo.visit === 'function') {
                window.Turbo.visit(step.url);
            } else {
                window.location.href = step.url;
            }
            return;
        }

        const targetEl = step.target ? document.querySelector(step.target) : null;
        this.positionHighlight(targetEl);
        this.positionPopover(targetEl, step.placement);

        const isLast = this.currentIndex === this.steps.length - 1;
        const progress = `${this.currentIndex + 1} / ${this.steps.length}`;

        this.popoverEl.innerHTML = `
            <div class="guided-tour-popover-header">
                <span class="guided-tour-progress">${progress}</span>
                <button type="button" class="guided-tour-close" aria-label="Close"
                        data-guided-tour-action="close">&times;</button>
            </div>
            <h3 id="guided-tour-title" class="guided-tour-title">
                ${step.icon ? `<i class="${this.escapeHtml(step.icon)}" aria-hidden="true"></i> ` : ''}${this.escapeHtml(step.title)}
            </h3>
            <div id="guided-tour-body" class="guided-tour-body">${this.escapeHtml(step.body)}</div>
            <div class="guided-tour-footer">
                <button type="button" class="btn btn-link btn-sm"
                        data-guided-tour-action="skip">
                    ${this.escapeHtml(this.translations('skip'))}
                </button>
                <div class="guided-tour-nav">
                    <button type="button" class="btn btn-outline-secondary btn-sm"
                            data-guided-tour-action="prev"
                            ${this.currentIndex === 0 ? 'disabled' : ''}>
                        ← ${this.escapeHtml(this.translations('previous'))}
                    </button>
                    <button type="button" class="btn btn-primary btn-sm"
                            data-guided-tour-action="${isLast ? 'finish' : 'next'}">
                        ${isLast ? this.escapeHtml(this.translations('finish')) : this.escapeHtml(this.translations('next')) + ' →'}
                    </button>
                </div>
            </div>
        `;

        // Wire up clicks via delegation (per-popover)
        this.popoverEl.querySelectorAll('[data-guided-tour-action]').forEach((el) => {
            el.addEventListener('click', (e) => {
                const action = e.currentTarget.dataset.guidedTourAction;
                switch (action) {
                    case 'next': this.next(); break;
                    case 'prev': this.previous(); break;
                    case 'finish': this.finish(); break;
                    case 'skip': this.finish(); break;
                    case 'close': this.finish(); break;
                }
            });
        });

        // Focus trap — first focusable in popover
        const firstBtn = this.popoverEl.querySelector('button:not([disabled])');
        firstBtn?.focus();

        // Persist current step
        localStorage.setItem(this.storageKey(), String(this.currentIndex));

        // Announce to screen readers
        this.liveRegionEl.textContent = `${progress} — ${step.title}`;
    }

    positionHighlight(targetEl) {
        if (!targetEl) {
            this.highlightEl.style.display = 'none';
            return;
        }
        const rect = targetEl.getBoundingClientRect();
        const padding = 6;
        this.highlightEl.style.display = 'block';
        this.highlightEl.style.top = `${rect.top - padding + window.scrollY}px`;
        this.highlightEl.style.left = `${rect.left - padding + window.scrollX}px`;
        this.highlightEl.style.width = `${rect.width + padding * 2}px`;
        this.highlightEl.style.height = `${rect.height + padding * 2}px`;

        // Scroll target into view (unless reduced motion)
        if (!this.reducedMotion) {
            targetEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            targetEl.scrollIntoView({ block: 'center' });
        }
    }

    positionPopover(targetEl, placement = 'center') {
        const popover = this.popoverEl;
        if (!targetEl || placement === 'center') {
            // Center viewport
            popover.style.top = '50%';
            popover.style.left = '50%';
            popover.style.transform = 'translate(-50%, -50%)';
            return;
        }

        popover.style.transform = 'none';
        const rect = targetEl.getBoundingClientRect();
        const gap = 16;

        switch (placement) {
            case 'bottom':
                popover.style.top = `${rect.bottom + gap + window.scrollY}px`;
                popover.style.left = `${rect.left + window.scrollX}px`;
                break;
            case 'top':
                popover.style.top = `${rect.top - gap - popover.offsetHeight + window.scrollY}px`;
                popover.style.left = `${rect.left + window.scrollX}px`;
                break;
            case 'right':
                popover.style.top = `${rect.top + window.scrollY}px`;
                popover.style.left = `${rect.right + gap + window.scrollX}px`;
                break;
            case 'left':
                popover.style.top = `${rect.top + window.scrollY}px`;
                popover.style.left = `${rect.left - gap - popover.offsetWidth + window.scrollX}px`;
                break;
            default:
                popover.style.top = '50%';
                popover.style.left = '50%';
                popover.style.transform = 'translate(-50%, -50%)';
        }
    }

    next() {
        if (this.currentIndex < this.steps.length - 1) {
            this.currentIndex++;
            this.renderCurrentStep();
        }
    }

    previous() {
        if (this.currentIndex > 0) {
            this.currentIndex--;
            this.renderCurrentStep();
        }
    }

    async finish() {
        const tourId = this.tourId;
        this.cleanup();

        if (!tourId) return;

        // POST completion (fire-and-forget — UI already closed)
        try {
            const formData = new FormData();
            formData.append('_token', this.csrfValue);
            await fetch(this.completeUrlValue.replace(/\/[a-z_]+$/, `/${tourId}`), {
                method: 'POST',
                body: formData,
                headers: { 'X-CSRF-Token': this.csrfValue },
            });
            localStorage.removeItem(this.storageKey());
        } catch (error) {
            console.warn('[guided-tour] Failed to mark complete:', error);
        }
    }

    cleanup() {
        document.removeEventListener('keydown', this.boundHandleKeydown);
        window.removeEventListener('resize', this.boundHandleResize);
        this.backdropEl?.remove();
        this.highlightEl?.remove();
        this.popoverEl?.remove();
        this.liveRegionEl?.remove();
        this.backdropEl = this.highlightEl = this.popoverEl = this.liveRegionEl = null;
        if (this.previouslyFocusedElement?.focus) {
            this.previouslyFocusedElement.focus();
        }
    }

    handleKeydown(event) {
        if (!this.popoverEl) return;
        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                this.finish();
                break;
            case 'ArrowRight':
                event.preventDefault();
                this.next();
                break;
            case 'ArrowLeft':
                event.preventDefault();
                this.previous();
                break;
            case 'Tab':
                this.trapFocus(event);
                break;
        }
    }

    handleResize() {
        if (!this.popoverEl) return;
        if (this.isMobile()) {
            this.finish();
            this.showMobileHint();
            return;
        }
        const step = this.steps[this.currentIndex];
        const targetEl = step?.target ? document.querySelector(step.target) : null;
        this.positionHighlight(targetEl);
        this.positionPopover(targetEl, step?.placement ?? 'center');
    }

    trapFocus(event) {
        const focusable = Array.from(
            this.popoverEl.querySelectorAll('button:not([disabled]), a[href], [tabindex]:not([tabindex="-1"])')
        );
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    showMobileHint() {
        const existing = document.querySelector('.guided-tour-mobile-hint');
        if (existing) return;
        const hint = document.createElement('div');
        hint.className = 'guided-tour-mobile-hint alert alert-info alert-dismissible fade show';
        hint.setAttribute('role', 'status');
        hint.innerHTML = `
            <i class="bi bi-phone" aria-hidden="true"></i>
            <strong>${this.escapeHtml(this.translations('mobile_title'))}</strong>
            ${this.escapeHtml(this.translations('mobile_body'))}
            <button type="button" class="btn-close" aria-label="Close"
                    onclick="this.closest('.guided-tour-mobile-hint').remove()"></button>
        `;
        document.body.appendChild(hint);
    }

    translations(key) {
        // Labels are passed as data-values from the template via a shared `i18n` stash.
        // Minimal fallback — production uses twig-set dataset on the mount point.
        const t = this.data.get(`i18n-${key}`);
        if (t) return t;
        return {
            next: 'Weiter',
            previous: 'Zurück',
            finish: 'Fertig',
            skip: 'Überspringen',
            mobile_title: 'Tour am Desktop',
            mobile_body: 'Die geführte Tour ist für Desktop optimiert. Bitte öffnen Sie das Tool auf einem größeren Bildschirm für die volle Erfahrung.',
        }[key] ?? key;
    }

    escapeHtml(str) {
        if (str == null) return '';
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
}
