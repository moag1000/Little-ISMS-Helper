import { Controller } from '@hotwired/stimulus';

/**
 * Wave 5 — Sidebar Mega-Menu Controller
 * Manages the sidebar L1 triggers + flyout-panel interactions.
 *
 * App-shell layout:
 *   .app__sidebar  — sticky sidebar containing sb-link trigger buttons
 *   .flyout        — fixed panels rendered at body level, keyed by data-category
 *
 * CSS state classes:
 *   trigger: [open]          — sb-link button while its flyout is open
 *   panel:   .is-open        — flyout panel visible (opacity/transform via CSS)
 *
 * Stimulus targets (declared on the sidebar element via data-controller="mega-menu"):
 *   trigger  — sb-link <button> elements (one per L1 category)
 *   panel    — .flyout <div> elements (rendered at body level)
 *
 * WCAG 2.2 AA (Phase 4.1):
 *   - focus-visible rings: 3px solid outline + glow shadow on triggers and fly-items (CSS)
 *   - role="menubar" aria-orientation="vertical" on nav container (template)
 *   - role="menuitem" aria-haspopup="menu" aria-expanded on trigger buttons (template)
 *   - role="menu" / role="menuitem" on panels (template)
 *   - ESC key closes open flyout and returns focus to trigger (Phase 4.1)
 *   - ArrowDown on trigger opens panel and focuses first item (Phase 4.1)
 *   - ArrowRight/Left between L1 triggers cycles focus + opens panel (Phase 4.1)
 *   - ArrowUp/Down within open panel navigates fly-items (Phase 4.1)
 *   - Tab within open panel cycles focusable items; Shift+Tab on first item returns to trigger
 *   - Focus trapped inside open flyout (Tab cycles within)
 */
export default class extends Controller {
    static targets = ['trigger', 'panel'];

    connect() {
        // Keyboard navigation on sidebar triggers (ArrowUp/Down/Left/Right, Home, End, Enter, Space)
        this.element.addEventListener('keydown', this.handleKeyboard.bind(this));

        // Global ESC key + outside-click to close
        document.addEventListener('keydown', this.handleGlobalKeydown.bind(this));
        document.addEventListener('click', this.handleOutsideClick.bind(this));

        // Phase 4.1: Keyboard navigation inside open flyout panels (ArrowUp/Down within sub-panel)
        this._handlePanelKeyboardBound = this.handlePanelKeyboard.bind(this);
        document.addEventListener('keydown', this._handlePanelKeyboardBound);

        // Hover: open flyout on mouseenter over L1 trigger
        this.triggerTargets.forEach(trigger => {
            trigger.addEventListener('mouseenter', this.handleMouseEnter.bind(this));
        });

        // Hover: close when leaving the sidebar
        const sidebar = document.querySelector('.app__sidebar, .app-sidebar');
        if (sidebar) {
            sidebar.addEventListener('mouseleave', this.handleMouseLeave.bind(this));
        }

        // Hover: also handle mouseleave on individual flyout panels
        this.panelTargets.forEach(panel => {
            panel.addEventListener('mouseleave', this.handlePanelMouseLeave.bind(this));
        });

        // Restore density from localStorage (initial render — density-toggle-controller also does this)
        this._restoreDensity();
    }

    disconnect() {
        document.removeEventListener('keydown', this.handleGlobalKeydown.bind(this));
        document.removeEventListener('click', this.handleOutsideClick.bind(this));
        if (this._handlePanelKeyboardBound) {
            document.removeEventListener('keydown', this._handlePanelKeyboardBound);
        }
    }

    // ─────────────── Public Stimulus actions ───────────────

    /**
     * Toggle a flyout panel when clicking its L1 trigger button.
     * data-action="click->mega-menu#toggle"
     */
    toggle(event) {
        const trigger = event.currentTarget;
        const category = trigger.dataset.category;

        // Clicking the already-open trigger closes the flyout
        if (trigger.hasAttribute('open')) {
            this.closePanel();
            return;
        }

        this.activatePanel(category);
    }

    /**
     * Close the current flyout.
     * Also used as data-action="click->mega-menu#closePanel" on .flyout__close button.
     */
    closePanel() {
        // Deactivate all triggers
        this.triggerTargets.forEach(trigger => {
            trigger.removeAttribute('open');
            trigger.setAttribute('aria-expanded', 'false');
        });

        // Hide all flyout panels
        this.panelTargets.forEach(panel => {
            panel.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
        });
    }

    // ─────────────── Internal helpers ───────────────

    /**
     * Show the flyout for the given category, hide all others.
     * @param {string} category
     */
    activatePanel(category) {
        // Deactivate all
        this.triggerTargets.forEach(trigger => {
            trigger.removeAttribute('open');
            trigger.setAttribute('aria-expanded', 'false');
        });

        this.panelTargets.forEach(panel => {
            panel.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
        });

        // Activate the matching trigger + panel
        const activeTrigger = this.triggerTargets.find(
            t => t.dataset.category === category
        );
        const activePanel = this.panelTargets.find(
            p => p.dataset.category === category
        );

        if (activeTrigger && activePanel) {
            activeTrigger.setAttribute('open', '');
            activeTrigger.setAttribute('aria-expanded', 'true');

            activePanel.classList.add('is-open');
            activePanel.setAttribute('aria-hidden', 'false');
            activePanel.scrollTop = 0;
        }
    }

    /**
     * Return the currently open category identifier, or null if none.
     * @returns {string|null}
     */
    getActiveCategory() {
        const activeTrigger = this.triggerTargets.find(t => t.hasAttribute('open'));
        return activeTrigger ? activeTrigger.dataset.category : null;
    }

    /**
     * Restore density setting from localStorage so body attribute is set
     * before first paint of flyout items.
     */
    _restoreDensity() {
        const saved = localStorage.getItem('isms-density');
        if (saved && ['basic', 'standard', 'expert'].includes(saved)) {
            document.body.dataset.density = saved;
        } else {
            document.body.dataset.density = 'standard';
        }
    }

    // ─────────────── Keyboard handlers ───────────────

    /**
     * Phase 4.1 — Arrow-key / Home / End navigation within the sidebar trigger list.
     * WAI-ARIA Authoring Practices §3.15 (Menubar):
     *   - ArrowRight: move focus to next menuitem (wrap), open panel
     *   - ArrowLeft: move focus to previous menuitem (wrap), open panel
     *   - ArrowDown: open submenu and focus first item
     *   - ArrowUp: move focus to previous menuitem
     *   - Home: focus first trigger
     *   - End: focus last trigger
     *   - Enter / Space: open panel and move focus to first item
     * @param {KeyboardEvent} event
     */
    handleKeyboard(event) {
        // Only handle when focus is on a sidebar trigger button
        if (!event.target.classList.contains('sb-link')) {
            return;
        }

        const currentIndex = this.triggerTargets.indexOf(event.target);
        let nextIndex = -1;

        switch (event.key) {
            case 'ArrowRight':
                // WAI-ARIA §3.15: ArrowRight on vertical menubar moves to next menuitem
                event.preventDefault();
                nextIndex = (currentIndex + 1) % this.triggerTargets.length;
                break;

            case 'ArrowLeft':
                // WAI-ARIA §3.15: ArrowLeft on vertical menubar moves to previous menuitem
                event.preventDefault();
                nextIndex = currentIndex - 1;
                if (nextIndex < 0) {
                    nextIndex = this.triggerTargets.length - 1;
                }
                break;

            case 'ArrowDown':
                // ArrowDown on a trigger: open its submenu and focus first item
                event.preventDefault();
                this.activatePanel(event.target.dataset.category);
                this._focusFirstFlyoutItem(event.target.dataset.category);
                return;

            case 'ArrowUp':
                // ArrowUp on trigger: move to previous trigger
                event.preventDefault();
                nextIndex = currentIndex - 1;
                if (nextIndex < 0) {
                    nextIndex = this.triggerTargets.length - 1;
                }
                break;

            case 'Home':
                event.preventDefault();
                nextIndex = 0;
                break;

            case 'End':
                event.preventDefault();
                nextIndex = this.triggerTargets.length - 1;
                break;

            case 'Enter':
            case ' ':
                event.preventDefault();
                this.activatePanel(event.target.dataset.category);
                // Phase 4.1: Move focus into the open flyout's first item
                this._focusFirstFlyoutItem(event.target.dataset.category);
                return;
        }

        if (nextIndex !== -1 && this.triggerTargets[nextIndex]) {
            this.triggerTargets[nextIndex].focus();
            this.activatePanel(this.triggerTargets[nextIndex].dataset.category);
        }
    }

    /**
     * Phase 4.1 — ArrowUp/Down navigation within an open flyout sub-panel.
     * WAI-ARIA Authoring Practices §3.15 (Menu):
     *   - ArrowDown: focus next focusable fly-item
     *   - ArrowUp: focus previous fly-item (on first: return focus to trigger)
     *   - Tab: cycle forward within panel (Tab on last → first)
     *   - Shift+Tab on first item: return focus to trigger
     * @param {KeyboardEvent} event
     */
    handlePanelKeyboard(event) {
        const openPanel = this.panelTargets.find(p => p.classList.contains('is-open'));
        if (!openPanel) return;

        const focusableSelector = '.fly-item, .flyout__close, .flyout__view-all, a[href], button:not([disabled])';
        const items = Array.from(openPanel.querySelectorAll(focusableSelector)).filter(
            el => !el.closest('[hidden]') && el.offsetParent !== null
        );
        if (items.length === 0) return;

        const focusedInPanel = openPanel.contains(document.activeElement);
        if (!focusedInPanel) return;

        const currentIdx = items.indexOf(document.activeElement);

        switch (event.key) {
            case 'ArrowDown': {
                event.preventDefault();
                const nextIdx = (currentIdx + 1) % items.length;
                items[nextIdx].focus();
                break;
            }
            case 'ArrowUp': {
                event.preventDefault();
                if (currentIdx <= 0) {
                    // Return focus to the triggering L1 button
                    const activeCategory = this.getActiveCategory();
                    const trigger = this.triggerTargets.find(t => t.dataset.category === activeCategory);
                    if (trigger) trigger.focus();
                } else {
                    items[currentIdx - 1].focus();
                }
                break;
            }
            case 'Tab': {
                // Tab on last item wraps to first; Shift+Tab on first returns to trigger
                if (!event.shiftKey && currentIdx === items.length - 1) {
                    event.preventDefault();
                    items[0].focus();
                } else if (event.shiftKey && currentIdx === 0) {
                    event.preventDefault();
                    const activeCategory = this.getActiveCategory();
                    const trigger = this.triggerTargets.find(t => t.dataset.category === activeCategory);
                    if (trigger) trigger.focus();
                }
                break;
            }
        }
    }

    /**
     * Global ESC key handler — closes open flyout and returns focus to its trigger.
     * @param {KeyboardEvent} event
     */
    handleGlobalKeydown(event) {
        if (event.key !== 'Escape') return;
        const activeCategory = this.getActiveCategory();
        if (!activeCategory) return;

        this.closePanel();

        // Return focus to the trigger that opened this flyout
        const trigger = this.triggerTargets.find(t => t.dataset.category === activeCategory);
        if (trigger) {
            trigger.focus();
        }
    }

    // ─────────────── Mouse handlers ───────────────

    /**
     * Open panel on hover over L1 trigger.
     * @param {MouseEvent} event
     */
    handleMouseEnter(event) {
        if (this.closeTimeout) {
            clearTimeout(this.closeTimeout);
            this.closeTimeout = null;
        }
        this.activatePanel(event.currentTarget.dataset.category);
    }

    /**
     * Close panel when mouse leaves the sidebar (unless entering a flyout).
     * @param {MouseEvent} event
     */
    handleMouseLeave(event) {
        const relatedTarget = event.relatedTarget;

        // Don't close if moving into an open flyout panel
        const openPanel = this.panelTargets.find(p => p.classList.contains('is-open'));
        if (openPanel && openPanel.contains(relatedTarget)) {
            return;
        }

        this.closeTimeout = setTimeout(() => this.closePanel(), 200);
    }

    /**
     * Close panel when mouse leaves a flyout (unless returning to sidebar).
     * @param {MouseEvent} event
     */
    handlePanelMouseLeave(event) {
        const relatedTarget = event.relatedTarget;
        const sidebar = document.querySelector('.app__sidebar, .app-sidebar');

        if (sidebar && sidebar.contains(relatedTarget)) {
            return;
        }

        this.closeTimeout = setTimeout(() => this.closePanel(), 200);
    }

    /**
     * Close flyout on click outside both sidebar and flyout.
     * @param {MouseEvent} event
     */
    handleOutsideClick(event) {
        const sidebar = document.querySelector('.app__sidebar, .app-sidebar');
        const openPanel = this.panelTargets.find(p => p.classList.contains('is-open'));

        if (!openPanel) return;

        const insideSidebar = sidebar && sidebar.contains(event.target);
        const insidePanel = openPanel.contains(event.target);

        if (!insideSidebar && !insidePanel) {
            this.closePanel();
        }
    }

    // ─────────────── Focus trap helper ───────────────

    /**
     * Move focus to the first focusable fly-item in the named flyout.
     * @param {string} category
     */
    _focusFirstFlyoutItem(category) {
        const panel = this.panelTargets.find(p => p.dataset.category === category);
        if (!panel) return;

        const firstItem = panel.querySelector('.fly-item, .flyout__close, .flyout__view-all');
        if (firstItem) {
            firstItem.focus();
        }
    }

    // ─────────────── Programmatic API (used by guided tour, shortcuts) ───────────────

    navigateTo(category) {
        this.activatePanel(category);
        const trigger = this.triggerTargets.find(t => t.dataset.category === category);
        if (trigger) trigger.focus();
    }

    getCategories() {
        return this.triggerTargets.map(t => t.dataset.category);
    }

    nextCategory() {
        const categories = this.getCategories();
        const current = this.getActiveCategory();
        const idx = categories.indexOf(current);
        this.navigateTo(categories[(idx + 1) % categories.length]);
    }

    previousCategory() {
        const categories = this.getCategories();
        const current = this.getActiveCategory();
        let idx = categories.indexOf(current) - 1;
        if (idx < 0) idx = categories.length - 1;
        this.navigateTo(categories[idx]);
    }
}
