import { Controller } from '@hotwired/stimulus'
import TomSelect from 'tom-select'
import 'tom-select/dist/css/tom-select.default.min.css'

/**
 * Policy-Wizard — TomSelect-backed picker for AuditFinding references in
 * Mode 2 Step 2 (TargetedFindingReferenceStep).
 *
 * Renders a single-select dropdown over the tenant's open AuditFindings
 * passed in as `<option>` children (rendered server-side). Allows free
 * text typing so external references (paper finding, another GRC system)
 * can still be linked — the persisted value is whatever the user picks
 * or types.
 *
 * Usage:
 *   <select data-controller="ts-finding-picker"
 *           data-ts-finding-picker-placeholder-value="..."
 *           name="finding_reference">
 *     ...options...
 *   </select>
 */
export default class extends Controller {
    static values = {
        placeholder: { type: String, default: '' },
    }

    connect() {
        this.tomSelect = new TomSelect(this.element, {
            create: true,
            createOnBlur: true,
            selectOnTab: true,
            allowEmptyOption: true,
            maxOptions: 200,
            placeholder: this.placeholderValue || this.element.getAttribute('placeholder') || '',
            persist: false,
            // Single-select — no remove_button plugin needed.
        })

        this.boundReinit = () => {
            try { this.tomSelect?.sync() } catch (_) { /* ignore */ }
        }
        document.addEventListener('turbo:before-cache', this.boundReinit)
    }

    disconnect() {
        try { this.tomSelect?.destroy() } catch (_) { /* ignore */ }
        if (this.boundReinit) {
            document.removeEventListener('turbo:before-cache', this.boundReinit)
        }
    }
}
