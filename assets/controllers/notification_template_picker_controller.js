import { Controller } from '@hotwired/stimulus';

/**
 * notification-template-picker — handles "Apply Template" form submission.
 *
 * Attaches to the <form> wrapping each Apply button on the template gallery.
 * Sets a loading state on the button during submit to prevent double-clicks.
 *
 * The form submits normally (no fetch); Turbo handles the redirect response
 * from NotificationTemplateController::apply → admin_notification_rule_edit.
 */
export default class extends Controller {
    static targets = ['button'];

    submit(event) {
        if (this.hasButtonTarget) {
            this.buttonTarget.disabled = true;
            this.buttonTarget.classList.add('fa-bulk-btn', 'is-loading');
        }
        // Allow the native form submission to proceed
    }

    connect() {
        this.element.addEventListener('submit', this.submit.bind(this));
    }

    disconnect() {
        this.element.removeEventListener('submit', this.submit.bind(this));
    }
}
