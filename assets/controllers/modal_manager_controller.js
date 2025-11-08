import { Controller } from '@hotwired/stimulus';

/**
 * Modal Manager Controller
 * Centralized modal and keyboard event management
 *
 * This controller manages all modal interactions globally to prevent conflicts:
 * - ESC key closes the topmost modal
 * - Prevents multiple modals from being open simultaneously (except notification panel)
 * - Manages keyboard shortcuts priority
 */
export default class extends Controller {
    connect() {
        console.log('[ModalManager] Connected');

        // Close any modals that are accidentally open on page load
        setTimeout(() => {
            this.closeAllModals();
        }, 100);

        // Centralized ESC handler - highest priority
        this.boundHandleEscape = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.boundHandleEscape, true); // Use capture phase
    }

    closeAllModals() {
        console.log('[ModalManager] Checking for modals to close on page load...');

        // Force hide ALL modal elements regardless of their class state
        const allModalElements = [
            ...document.querySelectorAll('.command-palette-modal'),
            ...document.querySelectorAll('.keyboard-shortcuts-modal'),
            ...document.querySelectorAll('.global-search-modal'),
            ...document.querySelectorAll('.preferences-modal'),
            ...document.querySelectorAll('.quick-view-modal'),
            ...document.querySelectorAll('.notification-panel')
        ];

        console.log('[ModalManager] Found', allModalElements.length, 'modal elements total');

        allModalElements.forEach(modal => {
            console.log('[ModalManager] Force hiding:', modal.className);
            // Force hide with inline style (highest priority)
            modal.style.display = 'none';
            // Also add d-none class
            modal.classList.add('d-none');
            // Remove any open classes
            modal.classList.remove('command-palette-open', 'keyboard-shortcuts-open', 'show');
        });

        // Also check the normal way
        const openModals = this.findOpenModals();
        if (openModals.length > 0) {
            console.log('[ModalManager] Found', openModals.length, 'modals that report as open');
            openModals.forEach(modal => this.closeModal(modal));
        }
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleEscape, true);
    }

    handleEscape(event) {
        if (event.key !== 'Escape') {
            return;
        }

        console.log('[ModalManager] ESC pressed');

        // Find all open modals
        const openModals = this.findOpenModals();

        if (openModals.length > 0) {
            console.log('[ModalManager] Found', openModals.length, 'open modals:', openModals.map(m => m.className));

            // Close the topmost modal (last in array has highest z-index)
            const topModal = openModals[openModals.length - 1];
            this.closeModal(topModal);

            // Prevent other handlers from running
            event.preventDefault();
            event.stopPropagation();
            event.stopImmediatePropagation();
        }
    }

    findOpenModals() {
        const modals = [];

        // Debug: Log all potential modal elements
        console.log('[ModalManager] Searching for open modals...');

        // Helper function to check if modal is actually visible
        const isVisible = (element) => {
            if (!element) return false;
            const style = window.getComputedStyle(element);
            return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
        };

        // Command Palette
        const commandPalette = document.querySelector('.command-palette-modal');
        const cpVisible = isVisible(commandPalette);
        console.log('[ModalManager] Command Palette:', commandPalette, 'Visible:', cpVisible, 'Classes:', commandPalette?.className);
        if (commandPalette && cpVisible) {
            modals.push(commandPalette);
        }

        // Keyboard Shortcuts
        const keyboardShortcuts = document.querySelector('.keyboard-shortcuts-modal');
        const ksVisible = isVisible(keyboardShortcuts);
        console.log('[ModalManager] Keyboard Shortcuts:', keyboardShortcuts, 'Visible:', ksVisible, 'Classes:', keyboardShortcuts?.className);
        if (keyboardShortcuts && ksVisible) {
            modals.push(keyboardShortcuts);
        }

        // Global Search
        const globalSearch = document.querySelector('.global-search-modal');
        const gsVisible = isVisible(globalSearch);
        console.log('[ModalManager] Global Search:', globalSearch, 'Visible:', gsVisible, 'Classes:', globalSearch?.className);
        if (globalSearch && gsVisible) {
            modals.push(globalSearch);
        }

        // Preferences
        const preferences = document.querySelector('.preferences-modal');
        const prefVisible = isVisible(preferences);
        console.log('[ModalManager] Preferences:', preferences, 'Visible:', prefVisible, 'Classes:', preferences?.className);
        if (preferences && prefVisible) {
            modals.push(preferences);
        }

        // Quick View
        const quickView = document.querySelector('.quick-view-modal');
        const qvVisible = isVisible(quickView);
        console.log('[ModalManager] Quick View:', quickView, 'Visible:', qvVisible, 'Classes:', quickView?.className);
        if (quickView && qvVisible) {
            modals.push(quickView);
        }

        // Notification Panel
        const notificationPanel = document.querySelector('.notification-panel');
        const npVisible = isVisible(notificationPanel);
        console.log('[ModalManager] Notification Panel:', notificationPanel, 'Visible:', npVisible, 'Classes:', notificationPanel?.className);
        if (notificationPanel && npVisible) {
            modals.push(notificationPanel);
        }

        // Check for ANY Bootstrap modals
        const bootstrapModals = document.querySelectorAll('.modal.show');
        console.log('[ModalManager] Bootstrap modals found:', bootstrapModals.length);
        bootstrapModals.forEach(m => {
            console.log('[ModalManager] Bootstrap modal:', m.className);
            modals.push(m);
        });

        return modals;
    }

    closeModal(modal) {
        console.log('[ModalManager] Closing modal:', modal.className);

        // FORCE hide with inline style (overrides everything)
        modal.style.display = 'none';
        modal.classList.add('d-none');

        // Bootstrap modals
        if (modal.classList.contains('modal')) {
            const bsModal = bootstrap?.Modal?.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
            }
            modal.classList.remove('show');
            document.body.classList.remove('modal-open');
            const backdrop = document.querySelector('.modal-backdrop');
            if (backdrop) backdrop.remove();
            return;
        }

        // Command Palette
        if (modal.classList.contains('command-palette-modal')) {
            modal.classList.remove('command-palette-open');
        }

        // Keyboard Shortcuts
        else if (modal.classList.contains('keyboard-shortcuts-modal')) {
            modal.classList.remove('keyboard-shortcuts-open');
        }

        // Global Search
        else if (modal.classList.contains('global-search-modal')) {
            modal.classList.remove('show');
            modal.classList.add('d-none');
            document.body.style.overflow = '';
        }

        // Preferences
        else if (modal.classList.contains('preferences-modal')) {
            modal.classList.remove('show');
            modal.classList.add('d-none');
            document.body.style.overflow = '';
        }

        // Quick View
        else if (modal.classList.contains('quick-view-modal')) {
            modal.classList.remove('show');
            modal.classList.add('d-none');
            document.body.style.overflow = '';
        }

        // Notification Panel
        else if (modal.classList.contains('notification-panel')) {
            modal.classList.remove('show');
            setTimeout(() => modal.classList.add('d-none'), 300);
        }
    }
}
