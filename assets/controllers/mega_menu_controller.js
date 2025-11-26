import { Controller } from '@hotwired/stimulus';

/**
 * Two-Level Mega Menu Controller
 * Manages interactions for the mega menu navigation component
 *
 * Usage:
 * <div data-controller="mega-menu">
 *   <button data-action="click->mega-menu#toggle" data-category="dashboard">Dashboard</button>
 *   <div data-category="dashboard">Panel content</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['trigger', 'panel'];

    connect() {
        // Start with panels closed
        // User can click a category to open

        // Handle keyboard navigation
        this.element.addEventListener('keydown', this.handleKeyboard.bind(this));

        // Handle outside clicks to close panel
        document.addEventListener('click', this.handleOutsideClick.bind(this));
    }

    disconnect() {
        document.removeEventListener('click', this.handleOutsideClick.bind(this));
    }

    /**
     * Toggle a panel when clicking on a trigger button
     * @param {Event} event
     */
    toggle(event) {
        const trigger = event.currentTarget;
        const category = trigger.dataset.category;

        // If clicking the active category, close the panel
        if (trigger.classList.contains('active')) {
            this.closePanel();
            return;
        }

        this.activatePanel(category);
    }

    /**
     * Close the panel
     */
    closePanel() {
        // Deactivate all triggers
        this.triggerTargets.forEach(trigger => {
            trigger.classList.remove('active');
            trigger.setAttribute('aria-expanded', 'false');
        });

        // Hide panel container
        const panelContainer = this.element.querySelector('.mega-menu-secondary');
        if (panelContainer) {
            panelContainer.classList.remove('panel-visible');
        }

        // After animation, hide panels
        setTimeout(() => {
            this.panelTargets.forEach(panel => {
                panel.classList.remove('active');
                panel.setAttribute('aria-hidden', 'true');
            });
        }, 300);
    }

    /**
     * Activate a specific panel and deactivate others
     * @param {string} category - The category identifier
     */
    activatePanel(category) {
        // Deactivate all triggers and panels
        this.triggerTargets.forEach(trigger => {
            trigger.classList.remove('active');
            trigger.setAttribute('aria-expanded', 'false');
        });

        this.panelTargets.forEach(panel => {
            panel.classList.remove('active');
            panel.setAttribute('aria-hidden', 'true');
        });

        // Activate the selected trigger and panel
        const activeTrigger = this.triggerTargets.find(
            trigger => trigger.dataset.category === category
        );

        const activePanel = this.panelTargets.find(
            panel => panel.dataset.category === category
        );

        if (activeTrigger && activePanel) {
            activeTrigger.classList.add('active');
            activeTrigger.setAttribute('aria-expanded', 'true');

            activePanel.classList.add('active');
            activePanel.setAttribute('aria-hidden', 'false');

            // Scroll panel to top
            activePanel.scrollTop = 0;

            // Show the panel container
            const panelContainer = this.element.querySelector('.mega-menu-secondary');
            if (panelContainer) {
                panelContainer.classList.add('panel-visible');
            }
        }
    }

    /**
     * Handle keyboard navigation for accessibility
     * @param {KeyboardEvent} event
     */
    handleKeyboard(event) {
        // Only handle keyboard events on trigger buttons
        if (!event.target.classList.contains('mega-menu-trigger')) {
            return;
        }

        const currentIndex = this.triggerTargets.indexOf(event.target);
        let nextIndex = -1;

        switch (event.key) {
            case 'ArrowDown':
            case 'ArrowRight':
                // Navigate to next category
                event.preventDefault();
                nextIndex = (currentIndex + 1) % this.triggerTargets.length;
                break;

            case 'ArrowUp':
            case 'ArrowLeft':
                // Navigate to previous category
                event.preventDefault();
                nextIndex = currentIndex - 1;
                if (nextIndex < 0) {
                    nextIndex = this.triggerTargets.length - 1;
                }
                break;

            case 'Home':
                // Navigate to first category
                event.preventDefault();
                nextIndex = 0;
                break;

            case 'End':
                // Navigate to last category
                event.preventDefault();
                nextIndex = this.triggerTargets.length - 1;
                break;

            case 'Enter':
            case ' ':
                // Activate current category
                event.preventDefault();
                const category = event.target.dataset.category;
                this.activatePanel(category);
                return;
        }

        if (nextIndex !== -1 && this.triggerTargets[nextIndex]) {
            this.triggerTargets[nextIndex].focus();
            const category = this.triggerTargets[nextIndex].dataset.category;
            this.activatePanel(category);
        }
    }

    /**
     * Handle clicks outside the menu to close panel
     * @param {Event} event
     */
    handleOutsideClick(event) {
        const panelContainer = this.element.querySelector('.mega-menu-secondary');
        if (!panelContainer) return;

        // Check if click is outside both the menu and the panel
        if (!this.element.contains(event.target) && !panelContainer.contains(event.target)) {
            // Close panel if it's open
            if (panelContainer.classList.contains('panel-visible')) {
                this.closePanel();
            }
        }
    }

    /**
     * Get the currently active category
     * @returns {string|null}
     */
    getActiveCategory() {
        const activeTrigger = this.triggerTargets.find(
            trigger => trigger.classList.contains('active')
        );
        return activeTrigger ? activeTrigger.dataset.category : null;
    }

    /**
     * Check if a specific category is active
     * @param {string} category
     * @returns {boolean}
     */
    isCategoryActive(category) {
        return this.getActiveCategory() === category;
    }

    /**
     * Navigate to a specific category programmatically
     * @param {string} category
     */
    navigateTo(category) {
        this.activatePanel(category);

        // Focus the trigger for keyboard users
        const trigger = this.triggerTargets.find(
            t => t.dataset.category === category
        );

        if (trigger) {
            trigger.focus();
        }
    }

    /**
     * Get all available categories
     * @returns {string[]}
     */
    getCategories() {
        return this.triggerTargets.map(trigger => trigger.dataset.category);
    }

    /**
     * Navigate to next category
     */
    nextCategory() {
        const categories = this.getCategories();
        const currentCategory = this.getActiveCategory();
        const currentIndex = categories.indexOf(currentCategory);
        const nextIndex = (currentIndex + 1) % categories.length;

        this.navigateTo(categories[nextIndex]);
    }

    /**
     * Navigate to previous category
     */
    previousCategory() {
        const categories = this.getCategories();
        const currentCategory = this.getActiveCategory();
        const currentIndex = categories.indexOf(currentCategory);
        let previousIndex = currentIndex - 1;

        if (previousIndex < 0) {
            previousIndex = categories.length - 1;
        }

        this.navigateTo(categories[previousIndex]);
    }
}
