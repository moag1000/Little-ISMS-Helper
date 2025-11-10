import { Controller } from '@hotwired/stimulus';

/**
 * Toast Notification Controller
 * Modern, unobtrusive notifications
 *
 * Usage:
 * 1. In base.html.twig: <div data-controller="toast" data-toast-target="container"></div>
 * 2. From other controllers: this.application.getControllerForElementAndIdentifier(document.querySelector('[data-controller~="toast"]'), 'toast').show('Message', 'success')
 * 3. From Turbo Streams: dispatch custom event
 */
export default class extends Controller {
    static targets = ['container'];

    connect() {
        // Listen for custom toast events
        document.addEventListener('toast:show', this.handleToastEvent.bind(this));

        // Listen for Turbo Frame errors
        document.addEventListener('turbo:frame-render', this.handleTurboRender.bind(this));

        // Convert flash messages to toasts on page load
        this.convertFlashMessages();
    }

    disconnect() {
        document.removeEventListener('toast:show', this.handleToastEvent.bind(this));
    }

    handleToastEvent(event) {
        const { message, type, duration } = event.detail;
        this.show(message, type, duration);
    }

    handleTurboRender(event) {
        // Show success toast after successful Turbo navigation
        if (event.detail.fetchResponse?.succeeded) {
            // Check for flash message in response
            this.convertFlashMessages();
        }
    }

    convertFlashMessages() {
        // Convert Symfony flash messages to toasts
        const flashContainer = document.querySelector('#flash-messages');
        if (!flashContainer) return;

        const flashes = flashContainer.querySelectorAll('.alert');
        flashes.forEach(flash => {
            const type = this.getTypeFromClass(flash.className);
            const message = flash.textContent.trim();

            if (message) {
                this.show(message, type, 5000);
            }

            flash.remove();
        });

        // Hide container if empty
        if (flashContainer.children.length === 0) {
            flashContainer.style.display = 'none';
        }
    }

    getTypeFromClass(className) {
        if (className.includes('alert-success')) return 'success';
        if (className.includes('alert-danger') || className.includes('alert-error')) return 'error';
        if (className.includes('alert-warning')) return 'warning';
        if (className.includes('alert-info')) return 'info';
        return 'info';
    }

    /**
     * Show a toast notification
     * @param {string} message - The message to display
     * @param {string} type - Type: 'success', 'error', 'warning', 'info'
     * @param {number} duration - Duration in ms (0 = no auto-dismiss)
     */
    show(message, type = 'info', duration = 5000) {
        const toast = this.createToast(message, type);
        this.containerTarget.appendChild(toast);

        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('toast-visible');
        });

        // Auto-dismiss
        if (duration > 0) {
            setTimeout(() => {
                this.dismiss(toast);
            }, duration);
        }
    }

    createToast(message, type) {
        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'polite');

        const icon = this.getIcon(type);

        toast.innerHTML = `
            <div class="toast-content">
                <i class="bi ${icon} toast-icon"></i>
                <div class="toast-message">${this.escapeHtml(message)}</div>
            </div>
            <button class="toast-close" aria-label="Schließen" data-action="click->toast#dismissButton">
                <i class="bi bi-x"></i>
            </button>
        `;

        return toast;
    }

    getIcon(type) {
        const icons = {
            success: 'bi-check-circle-fill',
            error: 'bi-exclamation-circle-fill',
            warning: 'bi-exclamation-triangle-fill',
            info: 'bi-info-circle-fill'
        };
        return icons[type] || icons.info;
    }

    dismissButton(event) {
        const toast = event.target.closest('.toast');
        this.dismiss(toast);
    }

    dismiss(toast) {
        toast.classList.remove('toast-visible');
        toast.classList.add('toast-exit');

        setTimeout(() => {
            toast.remove();
        }, 300);
    }

    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Public API for other controllers
    success(message, duration = 5000) {
        this.show(message, 'success', duration);
    }

    error(message, duration = 7000) {
        this.show(message, 'error', duration);
    }

    warning(message, duration = 6000) {
        this.show(message, 'warning', duration);
    }

    info(message, duration = 5000) {
        this.show(message, 'info', duration);
    }
}
