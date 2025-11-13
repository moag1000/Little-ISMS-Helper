import { Controller } from '@hotwired/stimulus';

/**
 * Turbo Controller
 *
 * Handles Turbo Drive events, Turbo Frame loading states, and Turbo Stream actions.
 * Provides visual feedback for navigation and form submissions.
 */
export default class extends Controller {
    static targets = ['loadingIndicator'];

    connect() {
        // Listen to Turbo events
        this.boundHandleBeforeVisit = this.handleBeforeVisit.bind(this);
        this.boundHandleVisit = this.handleVisit.bind(this);
        this.boundHandleBeforeRender = this.handleBeforeRender.bind(this);
        this.boundHandleRender = this.handleRender.bind(this);
        this.boundHandleBeforeStreamRender = this.handleBeforeStreamRender.bind(this);
        this.boundHandleSubmitStart = this.handleSubmitStart.bind(this);
        this.boundHandleSubmitEnd = this.handleSubmitEnd.bind(this);
        this.boundHandleBeforeFetchRequest = this.handleBeforeFetchRequest.bind(this);
        this.boundHandleBeforeFetchResponse = this.handleBeforeFetchResponse.bind(this);
        this.boundHandleBeforeCache = this.handleBeforeCache.bind(this);
        this.boundHandlePageShow = this.handlePageShow.bind(this);
        this.boundHandlePageHide = this.handlePageHide.bind(this);

        document.addEventListener('turbo:before-visit', this.boundHandleBeforeVisit);
        document.addEventListener('turbo:visit', this.boundHandleVisit);
        document.addEventListener('turbo:before-render', this.boundHandleBeforeRender);
        document.addEventListener('turbo:render', this.boundHandleRender);
        document.addEventListener('turbo:before-stream-render', this.boundHandleBeforeStreamRender);
        document.addEventListener('turbo:submit-start', this.boundHandleSubmitStart);
        document.addEventListener('turbo:submit-end', this.boundHandleSubmitEnd);
        document.addEventListener('turbo:before-fetch-request', this.boundHandleBeforeFetchRequest);
        document.addEventListener('turbo:before-fetch-response', this.boundHandleBeforeFetchResponse);
        document.addEventListener('turbo:before-cache', this.boundHandleBeforeCache);
        window.addEventListener('pageshow', this.boundHandlePageShow);
        window.addEventListener('pagehide', this.boundHandlePageHide);
    }

    disconnect() {
        // Clean up event listeners
        document.removeEventListener('turbo:before-visit', this.boundHandleBeforeVisit);
        document.removeEventListener('turbo:visit', this.boundHandleVisit);
        document.removeEventListener('turbo:before-render', this.boundHandleBeforeRender);
        document.removeEventListener('turbo:render', this.boundHandleRender);
        document.removeEventListener('turbo:before-stream-render', this.boundHandleBeforeStreamRender);
        document.removeEventListener('turbo:submit-start', this.boundHandleSubmitStart);
        document.removeEventListener('turbo:submit-end', this.boundHandleSubmitEnd);
        document.removeEventListener('turbo:before-fetch-request', this.boundHandleBeforeFetchRequest);
        document.removeEventListener('turbo:before-fetch-response', this.boundHandleBeforeFetchResponse);
        document.removeEventListener('turbo:before-cache', this.boundHandleBeforeCache);
        window.removeEventListener('pageshow', this.boundHandlePageShow);
        window.removeEventListener('pagehide', this.boundHandlePageHide);
    }

    handleBeforeVisit(event) {
        // Add loading class to body
        document.body.classList.add('turbo-loading');
    }

    handleVisit(event) {
        // Visit started
    }

    handleBeforeRender(event) {
        // Before render
    }

    handleRender(event) {
        // Remove loading class from body
        document.body.classList.remove('turbo-loading');

        // Auto-dismiss flash messages after 5 seconds
        this.autoDismissNotifications();
    }

    handleBeforeStreamRender(event) {

        // Customize stream rendering if needed
        const { newStream } = event.detail;

        // You can prevent the default rendering and handle it manually
        // event.preventDefault();
    }

    handleSubmitStart(event) {
        const submitter = event.detail.formSubmission.submitter;

        if (submitter) {
            // Disable submit button and show loading state
            submitter.disabled = true;
            submitter.dataset.originalText = submitter.innerHTML;
            submitter.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Wird gespeichert...';
        }
    }

    handleSubmitEnd(event) {
        const submitter = event.detail.formSubmission.submitter;

        if (submitter) {
            // Re-enable submit button and restore original text
            submitter.disabled = false;
            if (submitter.dataset.originalText) {
                submitter.innerHTML = submitter.dataset.originalText;
                delete submitter.dataset.originalText;
            }
        }

        // Auto-dismiss notifications after successful submission
        if (event.detail.success) {
            this.autoDismissNotifications();
        }
    }

    handleBeforeFetchRequest(event) {
        // Add custom headers for Turbo Stream requests
        const { fetchOptions } = event.detail;

        // You can modify fetch options here
    }

    handleBeforeFetchResponse(event) {
        const { fetchResponse } = event.detail;

        // Handle error responses (silently)
        if (!fetchResponse.succeeded) {
            // Could dispatch an event here if needed
        }
    }

    /**
     * Auto-dismiss notifications after 5 seconds
     */
    autoDismissNotifications() {
        setTimeout(() => {
            const notifications = document.querySelectorAll('#notifications .alert');
            notifications.forEach(notification => {
                // Use Bootstrap's fade out
                notification.classList.add('fade');
                notification.classList.remove('show');

                // Remove from DOM after animation
                setTimeout(() => {
                    notification.remove();
                }, 150);
            });
        }, 5000);
    }

    /**
     * Manually trigger a Turbo Stream action
     * Usage: turboController.stream('append', 'target-id', '<div>content</div>')
     */
    stream(action, target, content) {
        const streamElement = document.createElement('turbo-stream');
        streamElement.setAttribute('action', action);
        streamElement.setAttribute('target', target);

        const template = document.createElement('template');
        template.innerHTML = content;
        streamElement.appendChild(template);

        document.body.appendChild(streamElement);

        // Clean up
        setTimeout(() => {
            streamElement.remove();
        }, 100);
    }

    /**
     * Show a notification using Turbo Stream
     */
    notify(message, type = 'info') {
        const html = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                <i class="bi bi-${this.getIconForType(type)}"></i> ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        `;

        this.stream('append', 'notifications', html);
    }

    getIconForType(type) {
        const icons = {
            'success': 'check-circle',
            'danger': 'exclamation-triangle',
            'warning': 'exclamation-circle',
            'info': 'info-circle'
        };

        return icons[type] || 'info-circle';
    }

    handleBeforeCache(event) {
        // Clean up before page is cached
        // This helps prevent extension errors when page is restored from bfcache
        const cleanupEvent = new CustomEvent('app:cleanup-before-cache');
        document.dispatchEvent(cleanupEvent);
    }

    handlePageShow(event) {
        if (event.persisted) {
            // Page was restored from bfcache
            // Re-initialize any necessary components
            document.body.classList.remove('turbo-loading');

            // Dispatch event for other controllers to handle
            const restoreEvent = new CustomEvent('app:page-restored-from-cache');
            document.dispatchEvent(restoreEvent);
        }
    }

    handlePageHide(event) {
        if (event.persisted) {
            // Page is being stored in bfcache
            // Ensure proper cleanup to prevent extension errors
            document.body.classList.remove('turbo-loading');

            // Dispatch cleanup event
            const cacheEvent = new CustomEvent('app:page-entering-cache');
            document.dispatchEvent(cacheEvent);
        }
    }
}
