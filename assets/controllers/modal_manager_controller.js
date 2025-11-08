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

        // Debug: Log all potential modal elements
        console.log('[ModalManager] Searching for open modals...');

        // Command Palette
        const commandPalette = document.querySelector('.command-palette-modal');
        console.log('[ModalManager] Command Palette found:', commandPalette, 'Classes:', commandPalette?.className);
        if (commandPalette && commandPalette.classList.contains('command-palette-open')) {
            modals.push(commandPalette);
        }

        // Keyboard Shortcuts
        const keyboardShortcuts = document.querySelector('.keyboard-shortcuts-modal');
        console.log('[ModalManager] Keyboard Shortcuts found:', keyboardShortcuts, 'Classes:', keyboardShortcuts?.className);
        if (keyboardShortcuts && keyboardShortcuts.classList.contains('keyboard-shortcuts-open')) {
            modals.push(keyboardShortcuts);
        }

        // Global Search
        const globalSearch = document.querySelector('.global-search-modal');
        console.log('[ModalManager] Global Search found:', globalSearch, 'Classes:', globalSearch?.className);
        if (globalSearch && !globalSearch.classList.contains('d-none')) {
            modals.push(globalSearch);
        }

        // Preferences
        const preferences = document.querySelector('.preferences-modal');
        console.log('[ModalManager] Preferences found:', preferences, 'Classes:', preferences?.className);
        if (preferences && !preferences.classList.contains('d-none')) {
            modals.push(preferences);
        }

        // Quick View
        const quickView = document.querySelector('.quick-view-modal');
        console.log('[ModalManager] Quick View found:', quickView, 'Classes:', quickView?.className);
        if (quickView && !quickView.classList.contains('d-none')) {
            modals.push(quickView);
        }

        // Notification Panel
        const notificationPanel = document.querySelector('.notification-panel');
        console.log('[ModalManager] Notification Panel found:', notificationPanel, 'Classes:', notificationPanel?.className);
        if (notificationPanel && !notificationPanel.classList.contains('d-none')) {
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

        // Bootstrap modals
        if (modal.classList.contains('modal')) {
            const bsModal = bootstrap?.Modal?.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
                return;
            }
            // Fallback
            modal.classList.remove('show');
            modal.style.display = 'none';
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
