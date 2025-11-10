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
        // Close any modals that are accidentally open on page load
        setTimeout(() => {
            this.closeAllModals();
        }, 100);

        // Centralized ESC handler - highest priority
        this.boundHandleEscape = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.boundHandleEscape, true); // Use capture phase
    }

    closeAllModals() {
        // Force hide ALL modal elements regardless of their class state
        const allModalElements = [
            ...document.querySelectorAll('.command-palette-modal'),
            ...document.querySelectorAll('.keyboard-shortcuts-modal'),
            ...document.querySelectorAll('.global-search-modal'),
            ...document.querySelectorAll('.preferences-modal'),
            ...document.querySelectorAll('.quick-view-modal'),
            ...document.querySelectorAll('.notification-panel')
        ];

        allModalElements.forEach(modal => {
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

        // Find all open modals
        const openModals = this.findOpenModals();

        if (openModals.length > 0) {
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

        // Helper function to check if modal is actually visible
        const isVisible = (element) => {
            if (!element) return false;
            const style = window.getComputedStyle(element);
            return style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0';
        };

        // Command Palette
        const commandPalette = document.querySelector('.command-palette-modal');
        if (commandPalette && isVisible(commandPalette)) {
            modals.push(commandPalette);
        }

        // Keyboard Shortcuts
        const keyboardShortcuts = document.querySelector('.keyboard-shortcuts-modal');
        if (keyboardShortcuts && isVisible(keyboardShortcuts)) {
            modals.push(keyboardShortcuts);
        }

        // Global Search
        const globalSearch = document.querySelector('.global-search-modal');
        if (globalSearch && isVisible(globalSearch)) {
            modals.push(globalSearch);
        }

        // Preferences
        const preferences = document.querySelector('.preferences-modal');
        if (preferences && isVisible(preferences)) {
            modals.push(preferences);
        }

        // Quick View
        const quickView = document.querySelector('.quick-view-modal');
        if (quickView && isVisible(quickView)) {
            modals.push(quickView);
        }

        // Notification Panel
        const notificationPanel = document.querySelector('.notification-panel');
        if (notificationPanel && isVisible(notificationPanel)) {
            modals.push(notificationPanel);
        }

        // Check for ANY Bootstrap modals
        const bootstrapModals = document.querySelectorAll('.modal.show');
        bootstrapModals.forEach(m => modals.push(m));

        return modals;
    }

    closeModal(modal) {
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
