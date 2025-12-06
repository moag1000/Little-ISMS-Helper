import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['toggleable', 'content', 'expandIcon', 'filterPanel'];
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

    // Toggle content (for expandable sections)
    toggleContent() {
        if (this.hasContentTarget) {
            const isHidden = this.contentTarget.style.display === 'none';
            this.contentTarget.style.display = isHidden ? 'block' : 'none';

            // Rotate expand icon if present
            if (this.hasExpandIconTarget) {
                this.element.classList.toggle('expanded');
            }
        }
    }

    // Switch tabs
    switchTab(event) {
        const targetTab = event.currentTarget.dataset.tab;

        // Update tab buttons
        this.element.querySelectorAll('.tab-button').forEach(button => {
            button.classList.remove('active');
        });
        event.currentTarget.classList.add('active');

        // Update tab content
        this.element.querySelectorAll('.tab-content').forEach(content => {
            content.classList.remove('active');
        });
        const activeContent = this.element.querySelector(`[data-tab-content="${targetTab}"]`);
        if (activeContent) {
            activeContent.classList.add('active');
        }
    }

    // Toggle filter panel
    toggleFilter() {
        if (this.hasFilterPanelTarget) {
            const isHidden = this.filterPanelTarget.style.display === 'none';
            this.filterPanelTarget.style.display = isHidden ? 'block' : 'none';
        }
    }

    // Stop event propagation (for nested interactive elements)
    stopPropagation(event) {
        event.stopPropagation();
    }
}
