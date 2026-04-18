import { Controller } from '@hotwired/stimulus'
import TomSelect from 'tom-select'
import 'tom-select/dist/css/tom-select.default.min.css'

/**
 * Usage:
 *   <select data-controller="tom-select" multiple> ... </select>
 *
 * Options (via data attrs):
 *   data-tom-select-max-options-value="500"
 *   data-tom-select-placeholder-value="Pick some..."
 *   data-tom-select-remove-button-value="true"  (default: true when multiple)
 */
export default class extends Controller {
    static values = {
        maxOptions: { type: Number, default: 500 },
        placeholder: { type: String, default: '' },
        removeButton: { type: Boolean, default: true },
    }

    connect() {
        const isMultiple = this.element.multiple === true
        const plugins = []
        if (isMultiple && this.removeButtonValue) {
            plugins.push('remove_button')
        }

        this.tomSelect = new TomSelect(this.element, {
            plugins,
            maxOptions: this.maxOptionsValue,
            placeholder: this.placeholderValue || this.element.getAttribute('placeholder') || '',
            closeAfterSelect: !isMultiple,
            hidePlaceholder: false,
            allowEmptyOption: true,
        })

        // Re-initialise after Turbo frame swaps so the enhanced control is not
        // left orphaned from a cached frame.
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
