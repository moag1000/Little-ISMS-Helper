import { Controller } from '@hotwired/stimulus';

/**
 * Global Search Controller
 * Keyboard Shortcut: Cmd+K / Ctrl+K
 *
 * Features:
 * - Instant search across all entities (Assets, Risks, Controls, Incidents, Trainings)
 * - Keyboard navigation (Arrow keys, Enter, ESC)
 * - Categorized results
 * - Highlighting of search terms
 */
export default class extends Controller {
    static targets = [
        'modal',
        'input',
        'results',
        'loading',
        'empty'
    ];

    static values = {
        searchUrl: String,
        minChars: { type: Number, default: 2 },
        debounceDelay: { type: Number, default: 300 }
    };

    connect() {
        // Register keyboard shortcut: Cmd+K / Ctrl+K
        this.boundHandleGlobalKeydown = this.handleGlobalKeydown.bind(this);
        document.addEventListener('keydown', this.boundHandleGlobalKeydown);

        // Register modal keydown handler (ESC, arrows, etc.)
        this.boundHandleKeydown = this.handleKeydown.bind(this);
        document.addEventListener('keydown', this.boundHandleKeydown);

        // Debounced search function
        this.searchTimeout = null;
        this.selectedIndex = -1;
    }

    disconnect() {
        document.removeEventListener('keydown', this.boundHandleGlobalKeydown);
        document.removeEventListener('keydown', this.boundHandleKeydown);
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }
    }

    handleGlobalKeydown(event) {
        // Cmd+K (Mac) or Ctrl+K (Windows/Linux)
        if ((event.metaKey || event.ctrlKey) && event.key === 'k') {
            event.preventDefault();
            this.open();
        }
    }

    open() {
        // Remove inline style that modal manager might have set
        this.modalTarget.style.display = '';

        this.modalTarget.classList.remove('d-none');
        this.modalTarget.classList.add('show');
        document.body.style.overflow = 'hidden';

        // Focus input
        setTimeout(() => {
            this.inputTarget.focus();
        }, 100);
    }

    close() {
        this.modalTarget.classList.add('d-none');
        this.modalTarget.classList.remove('show');
        document.body.style.overflow = '';

        // Clear search
        this.inputTarget.value = '';
        this.resultsTarget.innerHTML = '';
        this.selectedIndex = -1;
    }

    handleBackdropClick(event) {
        if (event.target === this.modalTarget) {
            this.close();
        }
    }

    handleKeydown(event) {
        // Only handle keys when modal is open
        if (!this.hasModalTarget || this.modalTarget.classList.contains('d-none')) {
            return;
        }

        switch (event.key) {
            case 'Escape':
                event.preventDefault();
                this.close();
                break;
            case 'ArrowDown':
                event.preventDefault();
                this.moveSelection(1);
                break;
            case 'ArrowUp':
                event.preventDefault();
                this.moveSelection(-1);
                break;
            case 'Enter':
                event.preventDefault();
                this.selectCurrent();
                break;
        }
    }

    search(event) {
        const query = this.inputTarget.value.trim();

        // Clear previous timeout
        if (this.searchTimeout) {
            clearTimeout(this.searchTimeout);
        }

        // Check minimum characters
        if (query.length < this.minCharsValue) {
            this.resultsTarget.innerHTML = '';
            this.selectedIndex = -1;
            return;
        }

        // Show loading
        this.showLoading();

        // Debounce search
        this.searchTimeout = setTimeout(() => {
            this.performSearch(query);
        }, this.debounceDelayValue);
    }

    async performSearch(query) {
        try {
            const url = `${this.searchUrlValue}?q=${encodeURIComponent(query)}`;
            const response = await fetch(url);

            if (!response.ok) {
                throw new Error('Search failed');
            }

            const data = await response.json();
            this.displayResults(data, query);
        } catch (error) {
            console.error('Search error:', error);
            this.displayError();
        }
    }

    showLoading() {
        this.resultsTarget.innerHTML = `
            <div class="search-loading">
                <div class="spinner-border spinner-border-sm text-primary" role="status">
                    <span class="visually-hidden">Loading...</span>
                </div>
                <span class="ms-2">Suche läuft...</span>
            </div>
        `;
    }

    displayResults(data, query) {
        if (data.total === 0) {
            this.displayEmpty(query);
            return;
        }

        let html = '';

        // Group results by category
        const categories = [
            { key: 'assets', label: 'Assets', icon: 'bi-server', color: 'primary' },
            { key: 'risks', label: 'Risiken', icon: 'bi-exclamation-triangle', color: 'warning' },
            { key: 'controls', label: 'Controls', icon: 'bi-shield-check', color: 'success' },
            { key: 'incidents', label: 'Vorfälle', icon: 'bi-exclamation-circle', color: 'danger' },
            { key: 'trainings', label: 'Trainings', icon: 'bi-mortarboard', color: 'info' }
        ];

        categories.forEach(category => {
            if (data[category.key] && data[category.key].length > 0) {
                html += this.renderCategory(category, data[category.key], query);
            }
        });

        this.resultsTarget.innerHTML = html;
        this.selectedIndex = -1;
    }

    renderCategory(category, items, query) {
        let html = `
            <div class="search-category">
                <div class="search-category-header">
                    <i class="${category.icon} text-${category.color}"></i>
                    <span>${category.label}</span>
                    <span class="badge bg-${category.color}">${items.length}</span>
                </div>
                <div class="search-category-items">
        `;

        items.forEach((item, index) => {
            html += `
                <a href="${item.url}"
                   class="search-result-item"
                   data-index="${index}"
                   data-action="click->search#handleResultClick">
                    <div class="search-result-icon">
                        <i class="${category.icon} text-${category.color}"></i>
                    </div>
                    <div class="search-result-content">
                        <div class="search-result-title">${this.highlight(item.title, query)}</div>
                        ${item.description ? `<div class="search-result-description">${this.highlight(item.description, query)}</div>` : ''}
                    </div>
                    ${item.badge ? `<span class="badge bg-secondary">${item.badge}</span>` : ''}
                </a>
            `;
        });

        html += `
                </div>
            </div>
        `;

        return html;
    }

    highlight(text, query) {
        if (!text) return '';

        const regex = new RegExp(`(${query})`, 'gi');
        return text.replace(regex, '<mark>$1</mark>');
    }

    displayEmpty(query) {
        this.resultsTarget.innerHTML = `
            <div class="search-empty">
                <i class="bi-search" style="font-size: 3rem; color: #ccc;"></i>
                <p class="mt-3 mb-0">Keine Ergebnisse für "${query}"</p>
                <p class="text-muted small">Versuchen Sie andere Suchbegriffe</p>
            </div>
        `;
    }

    displayError() {
        this.resultsTarget.innerHTML = `
            <div class="search-error">
                <i class="bi-exclamation-triangle text-danger" style="font-size: 3rem;"></i>
                <p class="mt-3 mb-0">Fehler bei der Suche</p>
                <p class="text-muted small">Bitte versuchen Sie es erneut</p>
            </div>
        `;
    }

    moveSelection(direction) {
        const items = this.resultsTarget.querySelectorAll('.search-result-item');
        if (items.length === 0) return;

        // Remove previous selection
        if (this.selectedIndex >= 0 && this.selectedIndex < items.length) {
            items[this.selectedIndex].classList.remove('selected');
        }

        // Update index
        this.selectedIndex += direction;

        // Wrap around
        if (this.selectedIndex < 0) {
            this.selectedIndex = items.length - 1;
        } else if (this.selectedIndex >= items.length) {
            this.selectedIndex = 0;
        }

        // Add selection
        items[this.selectedIndex].classList.add('selected');
        items[this.selectedIndex].scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    selectCurrent() {
        const items = this.resultsTarget.querySelectorAll('.search-result-item');
        if (this.selectedIndex >= 0 && this.selectedIndex < items.length) {
            items[this.selectedIndex].click();
        }
    }

    handleResultClick(event) {
        // Close modal and navigate
        this.close();
    }
}
