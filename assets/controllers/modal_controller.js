import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['container', 'backdrop'];

    connect() {
        // Close modal on escape key
        this.boundHandleEscape = this.handleEscape.bind(this);
        document.addEventListener('keydown', this.boundHandleEscape);
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleEscape);
    }

    open() {
        this.containerTarget.classList.add('modal-open');
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.add('modal-backdrop-show');
        }
        document.body.style.overflow = 'hidden';
    }

    close() {
        this.containerTarget.classList.remove('modal-open');
        if (this.hasBackdropTarget) {
            this.backdropTarget.classList.remove('modal-backdrop-show');
        }
        document.body.style.overflow = '';
    }

    handleEscape(event) {
        if (event.key === 'Escape' && this.containerTarget.classList.contains('modal-open')) {
            this.close();
        }
    }

    closeOnBackdrop(event) {
        if (event.target === this.backdropTarget) {
            this.close();
        }
    }
}
