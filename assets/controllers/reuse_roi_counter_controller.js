// fa-reuse-roi-counter Stimulus controller (Aurora v4 Welle 3, 2026-05-27)
// Animates counter 0 → target value on viewport-enter via IntersectionObserver.
// Respects prefers-reduced-motion. Runs once per page load.
import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
    static targets = ['number']
    static values  = { target: Number }

    connect () {
        this._animated = false
        this._observer = new IntersectionObserver(entries => {
            if (entries[0].isIntersecting && !this._animated) {
                this._animated = true
                this._animate()
            }
        }, { threshold: 0.5 })
        this._observer.observe(this.element)
    }

    disconnect () {
        this._observer?.disconnect()
    }

    _animate () {
        const el     = this.numberTarget
        const target = this.targetValue

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            el.textContent = target.toLocaleString('de-DE')
            el.classList.add('is-complete')
            return
        }

        const duration = 1200
        const start    = performance.now()

        const tick = (now) => {
            const elapsed  = now - start
            const progress = Math.min(elapsed / duration, 1)
            const eased    = 1 - Math.pow(1 - progress, 3)  // ease-out-cubic
            el.textContent = Math.round(eased * target).toLocaleString('de-DE')

            if (progress < 1) {
                requestAnimationFrame(tick)
            } else {
                el.textContent = target.toLocaleString('de-DE')
                el.classList.add('is-complete')
                // Announce final value to screen reader
                el.setAttribute('aria-label', target.toLocaleString('de-DE'))
            }
        }

        requestAnimationFrame(tick)
    }
}
