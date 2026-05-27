// fa-density-toggle Stimulus controller (Aurora v4 Welle 3, 2026-05-27)
// Submits density preference via fetch, updates <body data-density="...">.
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static values = { endpoint: String }

    async submit (event) {
        const radio   = event.target
        const density = radio.value

        // Optimistic UI
        document.body.dataset.density = density
        this.element.querySelectorAll('.fa-density-toggle__opt').forEach(label => {
            label.classList.remove('is-active')
        })
        radio.closest('.fa-density-toggle__opt')?.classList.add('is-active')

        // Persist
        try {
            await fetch(this.endpointValue, {
                method:  'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: new URLSearchParams({ density }),
            })
        } catch {
            // Non-critical — body attribute drives CSS immediately
        }
    }
}
