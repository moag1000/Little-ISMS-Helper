import { Controller } from '@hotwired/stimulus';

/**
 * SSO Wizard Stimulus controller.
 *
 * Handles:
 * - Preset card selection highlight (step 1)
 * - On-blur discovery URL validation via AJAX (step 2)
 * - Copy-to-clipboard for callback URL (step 2/3)
 */
export default class extends Controller {
    static targets = ['presetCard', 'discoveryUrl', 'discoveryResult', 'callbackUrl'];
    static values = { validateUrl: String };

    connect() {
        this._highlightSelectedPreset();
    }

    /** Step 1: highlight the selected preset card */
    selectPreset(event) {
        this._highlightSelectedPreset();
    }

    _highlightSelectedPreset() {
        if (!this.hasPresetCardTarget) return;
        const selectedInput = this.element.querySelector('input[type="radio"]:checked');
        const selectedPreset = selectedInput ? selectedInput.value : null;
        for (const card of this.presetCardTargets) {
            const isSelected = card.dataset.preset === selectedPreset;
            card.classList.toggle('is-selected', isSelected);
            card.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
        }
    }

    /** Step 2: validate discovery URL on blur */
    async validateDiscovery() {
        if (!this.hasDiscoveryUrlTarget || !this.hasDiscoveryResultTarget) return;
        const url = this.discoveryUrlTarget.value.trim();
        if (!url || !this.validateUrlValue) return;

        this.discoveryResultTarget.innerHTML = '<span class="text-muted">Validating…</span>';

        try {
            const response = await fetch(this.validateUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ discoveryUrl: url }),
            });
            const data = await response.json();

            if (data.ok) {
                const issuer = data.issuer ? `<code>${this._escHtml(data.issuer)}</code>` : '';
                this.discoveryResultTarget.innerHTML =
                    `<span class="text-success"><i class="fa-icon fa-icon--ui-check" aria-hidden="true"></i> Discovery OK${issuer ? ' — Issuer: ' + issuer : ''}</span>`;
            } else {
                this.discoveryResultTarget.innerHTML =
                    `<span class="text-danger"><i class="fa-icon fa-icon--status-warning" aria-hidden="true"></i> ${this._escHtml(data.error || 'Validation failed')}</span>`;
            }
        } catch {
            this.discoveryResultTarget.innerHTML =
                '<span class="text-danger">Network error — could not validate.</span>';
        }
    }

    /** Copy callback URL to clipboard */
    async copyCallback() {
        if (!this.hasCallbackUrlTarget) return;
        const text = this.callbackUrlTarget.value || this.callbackUrlTarget.textContent.trim();
        try {
            await navigator.clipboard.writeText(text);
            const btn = this.element.querySelector('[data-action*="copyCallback"]');
            if (btn) {
                const original = btn.innerHTML;
                btn.innerHTML = '<i class="fa-icon fa-icon--ui-check" aria-hidden="true"></i> Copied!';
                setTimeout(() => { btn.innerHTML = original; }, 2000);
            }
        } catch {
            // clipboard not available (non-HTTPS or browser restriction)
        }
    }

    _escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }
}
