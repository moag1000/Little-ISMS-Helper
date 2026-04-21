import { Controller } from '@hotwired/stimulus';

/**
 * FairyAurora Typewriter Controller — Text-Animation mit blinkendem Caret
 *
 * Usage:
 *   <span data-controller="typewriter"
 *         data-typewriter-text-value="Hi, ich bin Alva."
 *         data-typewriter-lang-value="de">
 *     <span data-typewriter-target="text"></span><span data-typewriter-target="caret">▍</span>
 *     <button data-typewriter-target="skip" data-action="click->typewriter#skip" hidden>» Überspringen</button>
 *   </span>
 *
 * Plan § 26:
 *   DE  → 30ms/char
 *   EN  → 35ms/char
 *   Hard cap 2.8s per line
 *   Skip-Button appears after 500ms
 *
 * prefers-reduced-motion: skip-animation, show full text + no caret.
 */
export default class extends Controller {
    static values = {
        text:  { type: String, default: '' },
        lang:  { type: String, default: 'de' },
        speed: { type: Number, default: 0 }
    };
    static targets = ['text', 'caret', 'skip'];

    static MAX_LINE_MS = 2800;
    static SKIP_APPEAR_MS = 500;

    connect() {
        this.run();
    }

    disconnect() {
        this.cancel();
    }

    textValueChanged() {
        if (this.hasTextTarget) this.run();
    }

    run() {
        this.cancel();
        const text = this.textValue;
        if (!text) return;

        // reduced-motion → sofort full
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            this.setDisplay(text);
            this.hideCaret();
            return;
        }

        const base = this.speedValue > 0
            ? this.speedValue
            : (this.langValue === 'de' ? 30 : 35);
        const capPerChar = Math.floor(this.constructor.MAX_LINE_MS / Math.max(text.length, 1));
        const speed = Math.min(base, capPerChar);

        this.setDisplay('');
        this.showCaret();

        let i = 0;
        this.intervalId = window.setInterval(() => {
            i += 1;
            this.setDisplay(text.slice(0, i));
            if (i >= text.length) {
                this.cancel();
                this.hideCaret();
            }
        }, speed);

        if (this.hasSkipTarget) {
            this.skipTimeout = window.setTimeout(() => {
                this.skipTarget.hidden = false;
            }, this.constructor.SKIP_APPEAR_MS);
        }
    }

    skip(event) {
        if (event) event.preventDefault();
        this.cancel();
        this.setDisplay(this.textValue);
        this.hideCaret();
    }

    cancel() {
        if (this.intervalId) { window.clearInterval(this.intervalId); this.intervalId = null; }
        if (this.skipTimeout) { window.clearTimeout(this.skipTimeout); this.skipTimeout = null; }
        if (this.hasSkipTarget) this.skipTarget.hidden = true;
    }

    setDisplay(str) {
        if (this.hasTextTarget) this.textTarget.textContent = str;
    }

    showCaret() {
        if (this.hasCaretTarget) this.caretTarget.hidden = false;
    }

    hideCaret() {
        if (this.hasCaretTarget) this.caretTarget.hidden = true;
    }
}
