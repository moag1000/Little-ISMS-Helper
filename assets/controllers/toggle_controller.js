import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggleable'];
    static classes = ['hidden'];

    toggle() {
        this.toggleableTargets.forEach(target => {
            target.classList.toggle(this.hiddenClass);
        });
    }

    show() {
        this.toggleableTargets.forEach(target => {
            target.classList.remove(this.hiddenClass);
        });
    }

    hide() {
        this.toggleableTargets.forEach(target => {
            target.classList.add(this.hiddenClass);
        });
    }
}
