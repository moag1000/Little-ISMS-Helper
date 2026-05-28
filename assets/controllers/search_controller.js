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

        // Group results by category — navigation FIRST so admins find settings fast
        const categories = [
            { key: 'navigation',             label: 'Navigation',          icon: 'nav-arrow-right',  color: 'primary' },
            { key: 'assets',                 label: 'Assets',              icon: 'asset-server',     color: 'primary' },
            { key: 'risks',                  label: 'Risiken',             icon: 'status-warning',   color: 'warning' },
            { key: 'controls',               label: 'Controls',            icon: 'shield-check',     color: 'success' },
            { key: 'incidents',              label: 'Vorfälle',            icon: 'status-critical',  color: 'danger' },
            { key: 'trainings',              label: 'Trainings',           icon: 'nav-mortarboard',  color: 'info' },
            { key: 'documents',              label: 'Dokumente',           icon: 'nav-document',     color: 'info' },
            { key: 'suppliers',              label: 'Lieferanten',         icon: 'nav-supplier',     color: 'info' },
            { key: 'processing_activities',  label: 'Verarbeitungstätigkeiten', icon: 'nav-privacy', color: 'primary' },
            { key: 'dpias',                  label: 'DSFA',                icon: 'nav-privacy',      color: 'primary' },
            { key: 'data_breaches',          label: 'Datenpannen',         icon: 'nav-privacy',      color: 'danger' },
            { key: 'audit_findings',         label: 'Audit-Findings',      icon: 'nav-audit',        color: 'warning' },
            { key: 'corrective_actions',     label: 'Korrekturmaßnahmen',  icon: 'nav-audit',        color: 'warning' },
            { key: 'change_requests',        label: 'Change Requests',     icon: 'nav-change',       color: 'info' },
            { key: 'internal_audits',        label: 'Audits',              icon: 'nav-audit',        color: 'info' },
            { key: 'business_processes',     label: 'Geschäftsprozesse',   icon: 'nav-bcm',          color: 'info' },
            { key: 'bc_plans',               label: 'BC-Pläne',            icon: 'nav-bcm',          color: 'info' },
            { key: 'bc_exercises',           label: 'BC-Übungen',          icon: 'nav-bcm',          color: 'info' },
            { key: 'crisis_teams',           label: 'Krisenteams',         icon: 'nav-bcm',          color: 'warning' },
            { key: 'management_reviews',     label: 'Management-Reviews',  icon: 'nav-report',       color: 'info' },
            { key: 'objectives',             label: 'Ziele',               icon: 'nav-target',       color: 'primary' },
            { key: 'vulnerabilities',        label: 'Schwachstellen',      icon: 'status-critical',  color: 'danger' },
            { key: 'patches',                label: 'Patches',             icon: 'nav-wrench',       color: 'info' },
            { key: 'threat_intelligence',    label: 'Threat Intel',        icon: 'status-warning',   color: 'warning' },
            { key: 'persons',                label: 'Personen',            icon: 'nav-people',       color: 'info' },
            { key: 'interested_parties',     label: 'Stakeholder',         icon: 'nav-people',       color: 'info' },
            { key: 'consents',               label: 'Einwilligungen',      icon: 'nav-privacy',      color: 'info' },
            { key: 'data_subject_requests',  label: 'Betroffenenanfragen', icon: 'nav-privacy',      color: 'warning' },
            { key: 'compliance_frameworks',  label: 'Compliance-Frameworks', icon: 'nav-compliance', color: 'primary' },
            { key: 'compliance_requirements', label: 'Compliance-Anforderungen', icon: 'nav-compliance', color: 'primary' }
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
                    <i class="fa-icon fa-icon--${category.icon} text-${category.color}" aria-hidden="true"></i>
                    <span>${category.label}</span>
                    <span class="badge bg-${category.color}">${items.length}</span>
                </div>
                <div class="search-category-items">
        `;

        items.forEach((item, index) => {
            // Prefer per-item icon (backend already returns full class for navigation results)
            const iconClass = item.icon
                ? (item.icon.startsWith('fa-icon--') ? item.icon : `fa-icon--${item.icon}`)
                : `fa-icon--${category.icon}`;
            html += `
                <a href="${item.url}"
                   class="search-result-item"
                   data-index="${index}"
                   data-action="click->search#handleResultClick">
                    <div class="search-result-icon">
                        <i class="fa-icon ${iconClass} text-${category.color}" aria-hidden="true"></i>
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
                <i class="fa-icon fa-icon--ui-search" style="font-size: 3rem; color: #ccc;" aria-hidden="true"></i>
                <p class="mt-3 mb-0">Keine Ergebnisse für "${query}"</p>
                <p class="text-muted small">Versuchen Sie andere Suchbegriffe</p>
            </div>
        `;
    }

    displayError() {
        this.resultsTarget.innerHTML = `
            <div class="search-error">
                <i class="fa-icon fa-icon--status-warning text-danger" style="font-size: 3rem;" aria-hidden="true"></i>
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
