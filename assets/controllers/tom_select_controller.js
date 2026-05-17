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
        create: { type: Boolean, default: false },
        delimiter: { type: String, default: ',' },
    }

    connect() {
        const isMultiple = this.element.multiple === true
        const plugins = []
        if (isMultiple && this.removeButtonValue) {
            plugins.push('remove_button')
        }

        const opts = {
            plugins,
            maxOptions: this.maxOptionsValue,
            placeholder: this.placeholderValue || this.element.getAttribute('placeholder') || '',
            closeAfterSelect: !isMultiple,
            hidePlaceholder: false,
            allowEmptyOption: true,
        }

        // Free-tag input: accept new values typed by the user (Enter / comma).
        // Used by JsonTagsType-backed fields where the underlying entity column
        // is a JSON array of strings (competencies, objectives, references, …).
        if (this.createValue) {
            opts.create = true
            opts.createOnBlur = true
            opts.persist = true
            opts.delimiter = this.delimiterValue
            opts.render = {
                option_create: (data, escape) =>
                    `<div class="create"><strong>${escape(data.input)}</strong> &nbsp;⏎</div>`,
            }
        }

        this.tomSelect = new TomSelect(this.element, opts)

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
