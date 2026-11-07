import { Controller } from '@hotwired/stimulus';
import { faT } from '../js/fa-i18n.js';

/**
 * Enhanced Sidebar Toggle Controller
 *
 * Provides modern mobile UX for sidebar navigation with:
 * - Swipe left on the open sidebar to close it (horizontal swipes only, so
 *   vertical scrolling inside the menu is never blocked). No edge swipe to
 *   open: on iOS Safari the left edge is the browser's back gesture.
 * - Hardware-accelerated animations
 * - Focus trap + inert off-canvas sidebar for accessibility
 * - Keyboard navigation (Arrow keys)
 * - Persistent state (localStorage)
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
        swipeThreshold: { type: Number, default: 50 }
    };

    connect() {
        this.isOpen = false;
        this.touchStartX = 0;
        this.touchStartY = 0;
        this.touchCurrentX = 0;
        this.isDragging = false;
        this.swipeAxis = null;
        this.mobileQuery = window.matchMedia('(max-width: 767.98px)');
        this.focusedIndex = -1;
        this.navLinks = [];

        // Bind methods for event listeners
        this.handleTouchStart = this.handleTouchStart.bind(this);
        this.handleTouchMove = this.handleTouchMove.bind(this);
        this.handleTouchEnd = this.handleTouchEnd.bind(this);
        this.handleKeydown = this.handleKeydown.bind(this);
        this.handleResize = this.handleResize.bind(this);
        this.handleSidebarClick = this.handleSidebarClick.bind(this);

        // Swipe-to-close (touch only fires on touch devices)
        this.setupTouchGestures();

        // Setup keyboard navigation
        this.setupKeyboardNavigation();

        // Auto-close on link click (mobile only, checked per click)
        this.sidebarTarget.addEventListener('click', this.handleSidebarClick);

        // Closed off-canvas sidebar must not be reachable by Tab / VoiceOver
        this.syncInert();

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
        this.sidebarTarget.removeEventListener('touchstart', this.handleTouchStart);
        this.sidebarTarget.removeEventListener('touchmove', this.handleTouchMove);
        this.sidebarTarget.removeEventListener('touchend', this.handleTouchEnd);
        this.sidebarTarget.removeEventListener('click', this.handleSidebarClick);

        this.unlockScroll();

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

        this.sidebarTarget.inert = false;

        // Prevent page scroll behind the menu on mobile
        if (this.isMobile()) {
            this.lockScroll();
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
        this.announce(faT('sidebar.opened'));
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

        this.unlockScroll();
        this.syncInert();

        // Reset transform if any
        this.sidebarTarget.style.transform = '';
        this.backdropTarget.style.opacity = '';

        // Return focus to menu button
        this.menuBtnTarget.focus();
        this.focusedIndex = -1;

        // Save state (desktop only)
        if (!this.isMobile()) {
            this.saveState(false);
        }

        // Announce to screen readers
        this.announce(faT('sidebar.closed'));
    }

    /**
     * Setup swipe-to-close on the sidebar itself
     */
    setupTouchGestures() {
        this.sidebarTarget.addEventListener('touchstart', this.handleTouchStart, { passive: true });
        this.sidebarTarget.addEventListener('touchmove', this.handleTouchMove, { passive: false });
        this.sidebarTarget.addEventListener('touchend', this.handleTouchEnd, { passive: true });
    }

    handleTouchStart(event) {
        if (!this.isOpen || !this.isMobile()) return;
        this.touchStartX = event.touches[0].clientX;
        this.touchStartY = event.touches[0].clientY;
        this.touchCurrentX = this.touchStartX;
        this.swipeAxis = null;
        this.isDragging = true;
    }

    handleTouchMove(event) {
        if (!this.isDragging) return;

        const deltaX = event.touches[0].clientX - this.touchStartX;
        const deltaY = event.touches[0].clientY - this.touchStartY;

        // Decide once per gesture: vertical = let the menu scroll, horizontal = swipe
        if (this.swipeAxis === null) {
            if (Math.abs(deltaX) < 8 && Math.abs(deltaY) < 8) return;
            this.swipeAxis = Math.abs(deltaX) > Math.abs(deltaY) ? 'x' : 'y';
        }
        if (this.swipeAxis !== 'x') return;

        event.preventDefault();
        this.touchCurrentX = event.touches[0].clientX;
        if (deltaX < 0) {
            const width = this.sidebarTarget.offsetWidth || 280;
            this.sidebarTarget.classList.add('pull-revealing');
            this.sidebarTarget.style.transform = `translateX(${Math.max(deltaX, -width)}px)`;
            this.backdropTarget.style.opacity = Math.max(0, 1 + (deltaX / width));
        }
    }

    handleTouchEnd() {
        if (!this.isDragging) return;

        this.isDragging = false;
        this.sidebarTarget.classList.remove('pull-revealing');

        const deltaX = this.touchCurrentX - this.touchStartX;
        if (this.swipeAxis === 'x' && deltaX < -this.swipeThresholdValue) {
            this.close();
        } else {
            this.sidebarTarget.style.transform = '';
            this.backdropTarget.style.opacity = '';
        }
        this.swipeAxis = null;
    }

    /**
     * Close the drawer after a link inside it was tapped (mobile)
     */
    handleSidebarClick(event) {
        if (this.isOpen && this.isMobile() && event.target.closest('a[href]')) {
            this.close();
        }
    }

    /**
     * iOS Safari ignores overflow:hidden on <body> alone; lock <html> too
     */
    lockScroll() {
        document.documentElement.style.overflow = 'hidden';
        document.body.style.overflow = 'hidden';
    }

    unlockScroll() {
        document.documentElement.style.overflow = '';
        document.body.style.overflow = '';
    }

    /**
     * Off-canvas (mobile, closed) sidebar is inert; on desktop it is always reachable
     */
    syncInert() {
        this.sidebarTarget.inert = this.isMobile() && !this.isOpen;
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
        // ESC - Close sidebar (an open flyout closes first, mega-menu handles that)
        if (event.key === 'Escape' && this.isOpen) {
            if (event.defaultPrevented || document.querySelector('.flyout.is-open')) return;
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
        this.syncInert();
    }

    /**
     * Check if viewport is mobile
     */
    isMobile() {
        return this.mobileQuery.matches;
    }

    /**
     * Save sidebar state to localStorage
     */
    saveState(isOpen) {
        try {
            localStorage.setItem(this.storageKeyValue, isOpen ? 'open' : 'closed');
        } catch (error) {
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
