import { Controller } from '@hotwired/stimulus';

/**
 * Modal Controller with Focus Trap
 * WCAG 2.1 AA compliant modal dialogs with keyboard accessibility
 *
 * Features:
 * - Focus trap: Tab cycles only within modal
 * - Auto-focus first focusable element on open
 * - Returns focus to trigger element on close
 * - ESC key closes modal
 * - Backdrop click closes modal
 * - Prevents body scroll when open
 * - ARIA attributes for screen readers
 *
 * Usage:
 * <div data-controller="modal">
 *     <div data-modal-target="backdrop" data-action="click->modal#closeOnBackdrop"></div>
 *     <div data-modal-target="container" role="dialog" aria-modal="true" aria-labelledby="modal-title">
 *         <h2 id="modal-title">Modal Title</h2>
 *         <!-- modal content -->
 *         <button data-action="click->modal#close">Close</button>
 *     </div>
 * </div>
 */
export default class extends Controller {
    static targets = ['container', 'backdrop'];

    connect() {
        // Store reference to element that opened the modal
        this.previouslyFocusedElement = null;

        // Bind event handlers
        this.boundHandleEscape = this.handleEscape.bind(this);
        this.boundHandleTab = this.handleTab.bind(this);

        document.addEventListener('keydown', this.boundHandleEscape);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleEscape);
        document.removeEventListener('keydown', this.boundHandleTab);
    }

    open(event) {
        // Store the element that triggered the modal
        if (event && event.currentTarget) {
            this.previouslyFocusedElement = event.currentTarget;
        } else {
            this.previouslyFocusedElement = document.activeElement;
        }

        // Show modal
        this.containerTarget.classList.add('modal-open');
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.add('modal-backdrop-show');
        }

        // Prevent body scroll
        document.body.style.overflow = 'hidden';

        // Set ARIA attributes
        this.containerTarget.setAttribute('aria-hidden', 'false');

        // Enable focus trap
        document.addEventListener('keydown', this.boundHandleTab);

        // Focus first focusable element
        requestAnimationFrame(() => {
            this.focusFirstElement();
        });
    }

    close() {
        // Hide modal
        this.containerTarget.classList.remove('modal-open');
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.remove('modal-backdrop-show');
        }

        // Restore body scroll
        document.body.style.overflow = '';

        // Set ARIA attributes
        this.containerTarget.setAttribute('aria-hidden', 'true');

        // Disable focus trap
        document.removeEventListener('keydown', this.boundHandleTab);

        // Return focus to trigger element
        if (this.previouslyFocusedElement && this.previouslyFocusedElement.focus) {
            this.previouslyFocusedElement.focus();
            this.previouslyFocusedElement = null;
        }
    }

    handleEscape(event) {
        if (event.key === 'Escape' && this.containerTarget.classList.contains('modal-open')) {
            event.preventDefault();
            this.close();
        }
    }

    handleTab(event) {
        // Only trap focus if modal is open
        if (!this.containerTarget.classList.contains('modal-open')) {
            return;
        }

        if (event.key !== 'Tab') {
            return;
        }

        const focusableElements = this.getFocusableElements();

        if (focusableElements.length === 0) {
            event.preventDefault();
            return;
        }

        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        // Shift + Tab: if on first element, move to last
        if (event.shiftKey && document.activeElement === firstElement) {
            event.preventDefault();
            lastElement.focus();
        }
        // Tab: if on last element, move to first
        else if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault();
            firstElement.focus();
        }
    }

    closeOnBackdrop(event) {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }

    /**
     * Get all focusable elements within the modal
     */
    getFocusableElements() {
        const focusableSelectors = [
            'a[href]',
            'area[href]',
            'input:not([disabled]):not([type="hidden"])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            'button:not([disabled])',
            '[tabindex]:not([tabindex="-1"])',
            '[contenteditable]'
        ].join(', ');

        const elements = Array.from(this.containerTarget.querySelectorAll(focusableSelectors));

        // Filter out elements that are not visible
        return elements.filter(element => {
            return element.offsetWidth > 0 &&
                   element.offsetHeight > 0 &&
                   !element.hasAttribute('hidden') &&
                   getComputedStyle(element).visibility !== 'hidden';
        });
    }

    /**
     * Focus the first focusable element in the modal
     */
    focusFirstElement() {
        const focusableElements = this.getFocusableElements();

        if (focusableElements.length > 0) {
            // Prefer buttons with autofocus attribute or close buttons
            const autofocusElement = focusableElements.find(el => el.hasAttribute('autofocus'));
            const firstButton = focusableElements.find(el => el.tagName === 'BUTTON');

            const elementToFocus = autofocusElement || firstButton || focusableElements[0];
            elementToFocus.focus();
        } else {
            // If no focusable elements, focus the modal container itself
            this.containerTarget.setAttribute('tabindex', '-1');
            this.containerTarget.focus();
        }
    }
}
