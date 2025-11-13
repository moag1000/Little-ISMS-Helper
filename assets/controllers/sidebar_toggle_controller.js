import { Controller } from '@hotwired/stimulus';

/**
 * Sidebar Toggle Controller
 * Handles mobile sidebar navigation toggle
 */
export default class extends Controller {
    static targets = ['sidebar', 'backdrop', 'menuBtn'];

    connect() {
        // Close sidebar when clicking on navigation links (mobile only)
        if (window.innerWidth <= 768) {
            this.sidebarTarget.querySelectorAll('a').forEach(link => {
                link.addEventListener('click', () => this.close());
            });
        }

        // Close sidebar on window resize if it becomes desktop size
        window.addEventListener('resize', () => {
            if (window.innerWidth > 768) {
                this.close();
            }
        });
    }

    toggle() {
        const isOpen = this.sidebarTarget.classList.contains('open');

        if (isOpen) {
            this.close();
        } else {
            this.open();
        }
    }

    open() {
        this.sidebarTarget.classList.add('open');
        this.backdropTarget.classList.add('active');
        this.menuBtnTarget.classList.add('active');
        this.menuBtnTarget.setAttribute('aria-expanded', 'true');

        // Prevent body scroll when sidebar is open
        document.body.style.overflow = 'hidden';
    }

    close() {
        this.sidebarTarget.classList.remove('open');
        this.backdropTarget.classList.remove('active');
        this.menuBtnTarget.classList.remove('active');
        this.menuBtnTarget.setAttribute('aria-expanded', 'false');

        // Restore body scroll
        document.body.style.overflow = '';
    }

    // Handle escape key
    handleKeydown(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
