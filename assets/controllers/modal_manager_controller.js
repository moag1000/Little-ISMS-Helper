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

        // Centralized ESC handler - highest priority
        this.boundHandleEscape = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.boundHandleEscape, true); // Use capture phase
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

        // Command Palette
        const commandPalette = document.querySelector('.command-palette-modal.command-palette-open');
        if (commandPalette) modals.push(commandPalette);

        // Keyboard Shortcuts
        const keyboardShortcuts = document.querySelector('.keyboard-shortcuts-modal.keyboard-shortcuts-open');
        if (keyboardShortcuts) modals.push(keyboardShortcuts);

        // Global Search
        const globalSearch = document.querySelector('.global-search-modal:not(.d-none)');
        if (globalSearch && globalSearch.classList.contains('show')) modals.push(globalSearch);

        // Preferences
        const preferences = document.querySelector('.preferences-modal:not(.d-none)');
        if (preferences && preferences.classList.contains('show')) modals.push(preferences);

        // Quick View
        const quickView = document.querySelector('.quick-view-modal:not(.d-none)');
        if (quickView && quickView.classList.contains('show')) modals.push(quickView);

        // Notification Panel (lower priority, don't auto-close with ESC)
        // const notificationPanel = document.querySelector('.notification-panel.show');
        // if (notificationPanel) modals.push(notificationPanel);

        return modals;
    }

    closeModal(modal) {
        console.log('[ModalManager] Closing modal:', modal.className);

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
