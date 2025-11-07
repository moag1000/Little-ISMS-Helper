import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        autoDismiss: { type: Boolean, default: true },
        duration: { type: Number, default: 5000 }
    };

    connect() {
        this.show();

        if (this.autoDismissValue) {
            this.timeoutId = setTimeout(() => {
                this.dismiss();
            }, this.durationValue);
        }
    }

    disconnect() {
        if (this.timeoutId) {
            clearTimeout(this.timeoutId);
        }
    }

    show() {
        this.element.classList.add('notification-enter');
        requestAnimationFrame(() => {
            this.element.classList.add('notification-visible');
        });
    }

    dismiss() {
        this.element.classList.remove('notification-visible');
        this.element.classList.add('notification-exit');

        setTimeout(() => {
            this.element.remove();
        }, 300);
    }
}
