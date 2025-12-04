import { Controller } from '@hotwired/stimulus';

/**
 * Tabs Controller - Simple tab switching
 *
 * Usage:
 * <div data-controller="tabs" data-tabs-active-class="active">
 *     <button data-tabs-target="button" data-action="click->tabs#switch" data-tab="overview">Overview</button>
 *     <button data-tabs-target="button" data-action="click->tabs#switch" data-tab="details">Details</button>
 *
 *     <div data-tabs-target="panel" data-tab="overview">Overview content</div>
 *     <div data-tabs-target="panel" data-tab="details">Details content</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['button', 'panel'];
    static values = {
        activeClass: { type: String, default: 'active' }
    };

    connect() {
        // Ensure first tab is active if none specified
        if (!this.hasActiveTab()) {
            const firstButton = this.buttonTargets[0];
            if (firstButton) {
                this.activateTab(firstButton.dataset.tab);
            }
        }
    }

    switch(event) {
        event.preventDefault();
        const tabId = event.currentTarget.dataset.tab;
        this.activateTab(tabId);
    }

    activateTab(tabId) {
        // Update buttons
        this.buttonTargets.forEach(button => {
            if (button.dataset.tab === tabId) {
                button.classList.add(this.activeClassValue);
            } else {
                button.classList.remove(this.activeClassValue);
            }
        });

        // Update panels
        this.panelTargets.forEach(panel => {
            if (panel.dataset.tab === tabId) {
                panel.classList.add(this.activeClassValue);
                panel.style.display = '';
            } else {
                panel.classList.remove(this.activeClassValue);
                panel.style.display = 'none';
            }
        });
    }

    hasActiveTab() {
        return this.buttonTargets.some(button =>
            button.classList.contains(this.activeClassValue)
        );
    }
}
