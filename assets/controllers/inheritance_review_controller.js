import { Controller } from '@hotwired/stimulus';

/**
 * WS-1 review panel: live character counter, submit gating,
 * toggle for four-eyes approver select.
 */
export default class extends Controller {
    static targets = ['comment', 'counter', 'confirmBtn', 'implementCheckbox', 'approverWrapper', 'confirmForm'];

    static values = {
        minLength: { type: Number, default: 20 },
    };

    connect() {
        this.updateCounter();
    }

    updateCounter() {
        if (!this.hasCommentTarget || !this.hasCounterTarget) return;
        const length = this.commentTarget.value.trim().length;
        const remaining = Math.max(0, this.minLengthValue - length);
        this.counterTarget.textContent = length >= this.minLengthValue
            ? `${length} / ${this.minLengthValue}+ ✓`
            : `${length} / ${this.minLengthValue} — noch ${remaining}`;
        if (this.hasConfirmBtnTarget) {
            this.confirmBtnTarget.disabled = length < this.minLengthValue;
        }
    }

    toggleApproverSelect(event) {
        if (!this.hasApproverWrapperTarget) return;
        const enabled = event.target.checked;
        this.approverWrapperTarget.classList.toggle('d-none', !enabled);
        const select = this.approverWrapperTarget.querySelector('select');
        if (select) select.required = enabled;
    }
}
