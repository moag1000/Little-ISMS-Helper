import { Controller } from '@hotwired/stimulus';

/**
 * Enhanced Sidebar Toggle Controller
 *
 * Provides modern mobile UX for sidebar navigation with:
 * - Touch swipe gestures (left/right)
 * - Hardware-accelerated animations
 * - Focus trap for accessibility
 * - Keyboard navigation (Arrow keys)
 * - Pull-to-reveal visual feedback
 * - Persistent state (localStorage)
 * - Elastic bounce effect
 *
 * Usage:
 * <body data-controller="sidebar-toggle">
 *   <button data-action="click->sidebar-toggle#toggle" data-sidebar-toggle-target="menuBtn">...</button>
 *   <aside data-sidebar-toggle-target="sidebar">...</aside>
 *   <div data-sidebar-toggle-target="backdrop" data-action="click->sidebar-toggle#close">...</div>
 * </body>
 */
export default class extends Controller {
    static targets = ['sidebar', 'backdrop', 'menuBtn'];
    static values = {
        storageKey: { type: String, default: 'sidebar_mobile_state' },
        swipeThreshold: { type: Number, default: 50 },
        pullThreshold: { type: Number, default: 20 }
    };

    connect() {
        this.isOpen = false;
        this.touchStartX = 0;
        this.touchCurrentX = 0;
        this.isDragging = false;
        this.focusedIndex = -1;
        this.navLinks = [];

        // Bind methods for event listeners
        this.handleTouchStart = this.handleTouchStart.bind(this);
        this.handleTouchMove = this.handleTouchMove.bind(this);
        this.handleTouchEnd = this.handleTouchEnd.bind(this);
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleResize = this.handleResize.bind(this);

        // Setup touch gestures (mobile only)
        if (this.isMobile()) {
            this.setupTouchGestures();
        }

        // Setup keyboard navigation
        this.setupKeyboardNavigation();

        // Auto-close on link click (mobile only)
        if (this.isMobile()) {
            this.sidebarTarget.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => this.close());
            });
        }

        // Window resize handler
        window.addEventListener('resize', this.handleResize);

        // Restore state from localStorage (desktop only)
        if (!this.isMobile()) {
            this.restoreState();
        }

        // Collect nav links for keyboard navigation
        this.updateNavLinks();
    }

    disconnect() {
        // Remove touch event listeners
        if (this.isMobile()) {
            document.removeEventListener('touchstart', this.handleTouchStart);
            document.removeEventListener('touchmove', this.handleTouchMove);
            document.removeEventListener('touchend', this.handleTouchEnd);
        }

        // Remove keyboard listener
        document.removeEventListener('keydown', this.handleKeydown);

        // Remove resize listener
        window.removeEventListener('resize', this.handleResize);
    }

    /**
     * Toggle sidebar open/close
     */
    toggle() {
        if (this.isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    /**
     * Open sidebar with animation
     */
    open() {
        this.isOpen = true;

        // Add classes
        this.sidebarTarget.classList.add('open');
        this.backdropTarget.classList.add('active');
        this.menuBtnTarget.classList.add('active');

        // Update ARIA
        this.menuBtnTarget.setAttribute('aria-expanded', 'true');
        this.sidebarTarget.setAttribute('aria-hidden', 'false');

        // Prevent body scroll on mobile
        if (this.isMobile()) {
            document.body.style.overflow = 'hidden';
        }

        // Focus first link for accessibility
        setTimeout(() => {
            const firstLink = this.sidebarTarget.querySelector('a');
            if (firstLink) {
                firstLink.focus();
                this.focusedIndex = 0;
            }
        }, 300);

        // Save state (desktop only)
        if (!this.isMobile()) {
            this.saveState(true);
        }

        // Announce to screen readers
        this.announce('Navigation geöffnet');
    }

    /**
     * Close sidebar with animation
     */
    close() {
        this.isOpen = false;

        // Remove classes
        this.sidebarTarget.classList.remove('open');
        this.backdropTarget.classList.remove('active');
        this.menuBtnTarget.classList.remove('active');

        // Update ARIA
        this.menuBtnTarget.setAttribute('aria-expanded', 'false');
        this.sidebarTarget.setAttribute('aria-hidden', 'true');

        // Restore body scroll
        document.body.style.overflow = '';

        // Reset transform if any
        this.sidebarTarget.style.transform = '';

        // Return focus to menu button
        this.menuBtnTarget.focus();
        this.focusedIndex = -1;

        // Save state (desktop only)
        if (!this.isMobile()) {
            this.saveState(false);
        }

        // Announce to screen readers
        this.announce('Navigation geschlossen');
    }

    /**
     * Setup touch gesture detection
     */
    setupTouchGestures() {
        // Listen on document for edge swipe detection
        document.addEventListener('touchstart', this.handleTouchStart, { passive: true });
        document.addEventListener('touchmove', this.handleTouchMove, { passive: false });
        document.addEventListener('touchend', this.handleTouchEnd, { passive: true });
    }

    /**
     * Handle touch start
     */
    handleTouchStart(event) {
        this.touchStartX = event.touches[0].clientX;
        this.touchCurrentX = this.touchStartX;

        // Enable dragging if:
        // - Sidebar is open and touch starts on sidebar
        // - Sidebar is closed and touch starts near left edge
        if (this.isOpen && this.sidebarTarget.contains(event.target)) {
            this.isDragging = true;
        } else if (!this.isOpen && this.touchStartX < this.pullThresholdValue) {
            this.isDragging = true;
            this.sidebarTarget.classList.add('pull-revealing');
        }
    }

    /**
     * Handle touch move
     */
    handleTouchMove(event) {
        if (!this.isDragging) return;

        this.touchCurrentX = event.touches[0].clientX;
        const deltaX = this.touchCurrentX - this.touchStartX;

        // Prevent default to avoid page scroll during swipe
        if (Math.abs(deltaX) > 10) {
            event.preventDefault();
        }

        if (this.isOpen) {
            // Swipe to close (left swipe)
            if (deltaX < 0) {
                const translateX = Math.max(deltaX, -280);
                this.sidebarTarget.style.transform = `translateX(${translateX}px)`;

                // Adjust backdrop opacity
                const opacity = Math.max(0, 1 + (deltaX / 280));
                this.backdropTarget.style.opacity = opacity;
            }
        } else {
            // Pull to reveal (right swipe from edge)
            if (deltaX > 0) {
                const translateX = Math.min(deltaX, 280);
                this.sidebarTarget.style.transform = `translateX(calc(-100% + ${translateX}px))`;

                // Show backdrop gradually
                const opacity = Math.min(0.8, deltaX / 280);
                this.backdropTarget.style.opacity = opacity;
                this.backdropTarget.classList.add('active');
            }
        }
    }

    /**
     * Handle touch end
     */
    handleTouchEnd(event) {
        if (!this.isDragging) return;

        this.isDragging = false;
        this.sidebarTarget.classList.remove('pull-revealing');

        const deltaX = this.touchCurrentX - this.touchStartX;

        if (this.isOpen) {
            // If swiped more than threshold to left, close
            if (deltaX < -this.swipeThresholdValue) {
                this.close();
            } else {
                // Reset position with bounce
                this.sidebarTarget.style.transform = '';
                this.backdropTarget.style.opacity = '';
            }
        } else {
            // If pulled more than threshold to right, open
            if (deltaX > this.swipeThresholdValue) {
                this.open();
            } else {
                // Reset position
                this.sidebarTarget.style.transform = '';
                this.backdropTarget.style.opacity = '';
                this.backdropTarget.classList.remove('active');
            }
        }
    }

    /**
     * Setup keyboard navigation
     */
    setupKeyboardNavigation() {
        document.addEventListener('keydown', this.handleKeydown);
    }

    /**
     * Handle keyboard events
     */
    handleKeydown(event) {
        // ESC - Close sidebar
        if (event.key === 'Escape' && this.isOpen) {
            event.preventDefault();
            this.close();
            return;
        }

        // Only handle navigation keys when sidebar is open
        if (!this.isOpen) return;

        // Arrow Down - Next link
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.focusNextLink();
        }

        // Arrow Up - Previous link
        if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.focusPreviousLink();
        }

        // Home - First link
        if (event.key === 'Home') {
            event.preventDefault();
            this.focusFirstLink();
        }

        // End - Last link
        if (event.key === 'End') {
            event.preventDefault();
            this.focusLastLink();
        }

        // Tab - Focus trap (keep focus within sidebar)
        if (event.key === 'Tab') {
            this.handleTabKey(event);
        }
    }

    /**
     * Focus next navigation link
     */
    focusNextLink() {
        if (this.navLinks.length === 0) return;

        this.focusedIndex = (this.focusedIndex + 1) % this.navLinks.length;
        this.navLinks[this.focusedIndex].focus();
    }

    /**
     * Focus previous navigation link
     */
    focusPreviousLink() {
        if (this.navLinks.length === 0) return;

        this.focusedIndex = this.focusedIndex <= 0 ? this.navLinks.length - 1 : this.focusedIndex - 1;
        this.navLinks[this.focusedIndex].focus();
    }

    /**
     * Focus first navigation link
     */
    focusFirstLink() {
        if (this.navLinks.length === 0) return;

        this.focusedIndex = 0;
        this.navLinks[this.focusedIndex].focus();
    }

    /**
     * Focus last navigation link
     */
    focusLastLink() {
        if (this.navLinks.length === 0) return;

        this.focusedIndex = this.navLinks.length - 1;
        this.navLinks[this.focusedIndex].focus();
    }

    /**
     * Handle Tab key for focus trap
     */
    handleTabKey(event) {
        if (!this.isOpen || !this.isMobile()) return;

        const focusableElements = this.getFocusableElements();
        const firstElement = focusableElements[0];
        const lastElement = focusableElements[focusableElements.length - 1];

        // Shift + Tab on first element → go to last
        if (event.shiftKey && document.activeElement === firstElement) {
            event.preventDefault();
            lastElement.focus();
        }
        // Tab on last element → go to first
        else if (!event.shiftKey && document.activeElement === lastElement) {
            event.preventDefault();
            firstElement.focus();
        }
    }

    /**
     * Get all focusable elements in sidebar
     */
    getFocusableElements() {
        const selectors = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])'
        ];

        return Array.from(this.sidebarTarget.querySelectorAll(selectors.join(', ')))
            .filter(el => {
                return el.offsetParent !== null && // visible
                       getComputedStyle(el).visibility !== 'hidden' &&
                       getComputedStyle(el).display !== 'none';
            });
    }

    /**
     * Update nav links array
     */
    updateNavLinks() {
        this.navLinks = Array.from(this.sidebarTarget.querySelectorAll('a[href]'));
    }

    /**
     * Handle window resize
     */
    handleResize() {
        // Auto-close sidebar on resize to desktop
        if (!this.isMobile() && this.isOpen) {
            this.close();
        }
    }

    /**
     * Check if viewport is mobile
     */
    isMobile() {
        return window.innerWidth <= 768;
    }

    /**
     * Save sidebar state to localStorage
     */
    saveState(isOpen) {
        try {
            localStorage.setItem(this.storageKeyValue, isOpen ? 'open' : 'closed');
        } catch (error) {
            console.warn('Failed to save sidebar state:', error);
        }
    }

    /**
     * Restore sidebar state from localStorage
     */
    restoreState() {
        try {
            const savedState = localStorage.getItem(this.storageKeyValue);
            if (savedState === 'open') {
                this.open();
            }
        } catch (error) {
            console.warn('Failed to restore sidebar state:', error);
        }
    }

    /**
     * Announce to screen readers
     */
    announce(message) {
        let liveRegion = document.getElementById('sidebar-announcement');

        if (!liveRegion) {
            liveRegion = document.createElement('div');
            liveRegion.id = 'sidebar-announcement';
            liveRegion.className = 'sr-only';
            liveRegion.setAttribute('role', 'status');
            liveRegion.setAttribute('aria-live', 'polite');
            document.body.appendChild(liveRegion);
        }

        liveRegion.textContent = message;

        // Clear after announcement
        setTimeout(() => {
            liveRegion.textContent = '';
        }, 1000);
    }
}
