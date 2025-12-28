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

        // Skip conversion if container has data-skip-toast attribute
        if (flashContainer.hasAttribute('data-skip-toast')) return;

        const flashes = flashContainer.querySelectorAll('.alert');
        flashes.forEach(flash => {
            const type = this.getTypeFromClass(flash.className);
            const message = flash.textContent.trim();

            if (message) {
                // Show debug messages (starting with "DEBUG:") for longer (30s)
                // Regular messages disappear after 5s
                const duration = message.startsWith('DEBUG:') ? 30000 : 5000;
                this.show(message, type, duration);
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

        // Set progress bar animation duration dynamically
        if (duration > 0) {
            const progressBar = toast.querySelector('.toast-progress');
            if (progressBar) {
                progressBar.style.animationDuration = `${duration}ms`;
            }
        }

        // Trigger animation
        requestAnimationFrame(() => {
            toast.classList.add('toast-visible', 'show');
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
        // Add fairy styling to success toasts for subtle magic effect
        const fairyClass = type === 'success' ? ' fairy-toast' : '';
        toast.className = `toast toast-${type}${fairyClass}`;
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
            <div class="toast-progress"></div>
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
        toast.classList.remove('toast-visible', 'show');
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
