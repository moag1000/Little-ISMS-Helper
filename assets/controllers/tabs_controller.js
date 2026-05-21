import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora v4 — Tabs Controller
 *
 * Responsibilities:
 *   1. Activate tab on click: swap .is-active on nav-item + panel; update ARIA
 *   2. Keyboard nav: Left/Right arrows move focus + activate; Home/End jump
 *   3. URL hash sync (if enabled): update location.hash on switch; restore on load
 *   4. Error indicator: setTabError(tabId, count) flips .has-error + badge text
 *   5. Focus management: focus first focusable element in panel on keyboard activation
 *
 * Stimulus targets:
 *   navItem      — <button role="tab"> elements  (data-tab-id required)
 *   panel        — <div role="tabpanel"> elements (data-tab-id required)
 *   errorBadge   — <span> badge inside nav-item   (data-tab-id required)
 *
 * Stimulus values:
 *   activeTab     (String)  — id of current active tab
 *   urlHashSync   (Boolean) — sync active tab to URL #hash (default true)
 *
 * Usage:
 *   <div data-controller="tabs"
 *        data-tabs-active-tab-value="general"
 *        data-tabs-url-hash-sync-value="true">
 *
 *     <ul role="tablist">
 *       <li role="presentation">
 *         <button role="tab" data-tabs-target="navItem" data-tab-id="general"
 *                 data-action="click->tabs#activateTab keydown->tabs#onKeydown"
 *                 aria-selected="true" aria-controls="tenant-settings-panel-general">
 *           Allgemein
 *         </button>
 *       </li>
 *     </ul>
 *
 *     <div role="tabpanel" id="tenant-settings-panel-general"
 *          data-tabs-target="panel" data-tab-id="general"
 *          class="fa-tabs__panel is-active">
 *       ...content...
 *     </div>
 *   </div>
 *
 * Backward-compat note: the old simple controller used `data-tabs-target="button"` and
 * `data-tab` attributes. Those targets are no longer declared here. Any legacy call-sites
 * using the old API (`switch`, `activateTab` with raw tabId) should migrate to the new
 * `navItem` target + `activateTab(event)` pattern.
 */
export default class extends Controller {
    static targets = ['navItem', 'panel', 'errorBadge'];

    static values = {
        activeTab:   { type: String,  default: '' },
        urlHashSync: { type: Boolean, default: true },
    };

    /* ── Lifecycle ──────────────────────────────────────────────────── */

    connect() {
        // Restore from URL hash first (if sync is enabled and hash matches a tab)
        if (this.urlHashSyncValue && location.hash) {
            const hashId = location.hash.slice(1);
            const matchingItem = this.navItemTargets.find(el => el.dataset.tabId === hashId);
            if (matchingItem && !matchingItem.classList.contains('is-disabled')) {
                this._activateTabById(hashId, false);
                return;
            }
        }

        // Activate from Stimulus value, or fall back to first non-disabled tab
        const initial = this.activeTabValue
            || this.navItemTargets.find(el => !el.classList.contains('is-disabled'))?.dataset.tabId
            || '';

        if (initial) {
            this._activateTabById(initial, false);
        }
    }

    /* ── Actions ────────────────────────────────────────────────────── */

    activateTab(event) {
        const tabId = event.currentTarget.dataset.tabId;
        if (!tabId) { return; }
        this._activateTabById(tabId, false);
    }

    onKeydown(event) {
        const items = this._enabledNavItems();
        const currentIndex = items.indexOf(event.currentTarget);

        let targetIndex = null;

        switch (event.key) {
            case 'ArrowLeft':
            case 'ArrowUp':
                event.preventDefault();
                targetIndex = currentIndex > 0 ? currentIndex - 1 : items.length - 1;
                break;

            case 'ArrowRight':
            case 'ArrowDown':
                event.preventDefault();
                targetIndex = currentIndex < items.length - 1 ? currentIndex + 1 : 0;
                break;

            case 'Home':
                event.preventDefault();
                targetIndex = 0;
                break;

            case 'End':
                event.preventDefault();
                targetIndex = items.length - 1;
                break;

            default:
                return;
        }

        if (targetIndex !== null && items[targetIndex]) {
            const targetItem = items[targetIndex];
            const tabId = targetItem.dataset.tabId;
            this._activateTabById(tabId, true);
            targetItem.focus();
        }
    }

    /* ── Public API ─────────────────────────────────────────────────── */

    /**
     * Programmatically set error count on a tab.
     *   count > 0 → adds .has-error, updates badge text
     *   count === 0 → removes .has-error, hides badge
     *
     * @param {string} tabId
     * @param {number} count
     */
    setTabError(tabId, count) {
        const navItem = this.navItemTargets.find(el => el.dataset.tabId === tabId);
        if (!navItem) { return; }

        if (count > 0) {
            navItem.classList.add('has-error');
        } else {
            navItem.classList.remove('has-error');
        }

        const badge = this.errorBadgeTargets.find(el => el.dataset.tabId === tabId);
        if (badge) {
            badge.textContent = count > 0 ? String(count) : '';
            badge.style.display = count > 0 ? '' : 'none';
        }
    }

    /* ── Private ────────────────────────────────────────────────────── */

    _activateTabById(tabId, moveFocus) {
        // Update nav items
        this.navItemTargets.forEach(item => {
            const isTarget = item.dataset.tabId === tabId;
            item.classList.toggle('is-active', isTarget);
            item.setAttribute('aria-selected', isTarget ? 'true' : 'false');
            item.setAttribute('tabindex', isTarget ? '0' : '-1');
        });

        // Update panels
        let targetPanel = null;
        this.panelTargets.forEach(panel => {
            const isTarget = panel.dataset.tabId === tabId;
            panel.classList.toggle('is-active', isTarget);
            if (isTarget) { targetPanel = panel; }
        });

        // Update Stimulus value
        this.activeTabValue = tabId;

        // URL hash sync
        if (this.urlHashSyncValue && typeof history !== 'undefined') {
            history.replaceState(null, '', '#' + tabId);
        }

        // Focus first focusable element in panel on keyboard-triggered activation
        if (moveFocus && targetPanel) {
            const focusable = targetPanel.querySelector(
                'a[href], button:not([disabled]), input:not([disabled]), ' +
                'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
            );
            if (focusable) {
                focusable.focus();
            } else {
                // Panel itself has tabindex="0" from the macro — fall back to it
                targetPanel.focus();
            }
        }
    }

    _enabledNavItems() {
        return this.navItemTargets.filter(el => !el.classList.contains('is-disabled'));
    }
}
