import { Controller } from '@hotwired/stimulus';

/**
 * Skeleton Loader Controller
 * Shows skeleton screens during loading for better perceived performance
 *
 * Usage:
 * <div data-controller="skeleton" data-skeleton-target="container">
 *   <div data-skeleton-target="skeleton">[Skeleton HTML]</div>
 *   <div data-skeleton-target="content" class="hidden">[Real Content]</div>
 * </div>
 */
export default class extends Controller {
    static targets = ['skeleton', 'content', 'container'];
    static values = {
        duration: { type: Number, default: 800 },
        url: String
    };

    connect() {
        // If URL is provided, fetch content
        if (this.hasUrlValue) {
            this.loadContent();
        } else {
            // Otherwise, just show content after duration
            this.showContent();
        }
    }

    async loadContent() {
        try {
            // Show skeleton
            this.showSkeleton();

            // Fetch content
            const response = await fetch(this.urlValue, {
                headers: {
                    'Accept': 'text/html',
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            const html = await response.text();

            // Wait minimum duration for smooth transition
            await this.waitMinimumDuration();

            // Update content
            this.contentTarget.innerHTML = html;

            // Show content
            this.showContent();

        } catch (error) {
            this.showError();
        }
    }

    showSkeleton() {
        if (this.hasSkeletonTarget) {
            this.skeletonTarget.classList.remove('hidden');
        }
        if (this.hasContentTarget) {
            this.contentTarget.classList.add('hidden');
        }
    }

    showContent() {
        setTimeout(() => {
            if (this.hasSkeletonTarget) {
                this.skeletonTarget.classList.add('hidden');
            }
            if (this.hasContentTarget) {
                this.contentTarget.classList.remove('hidden');
                this.contentTarget.style.opacity = '0';

                // Fade in animation
                requestAnimationFrame(() => {
                    this.contentTarget.style.transition = 'opacity 0.3s ease';
                    this.contentTarget.style.opacity = '1';
                });
            }
        }, this.durationValue);
    }

    showError() {
        if (this.hasSkeletonTarget) {
            this.skeletonTarget.innerHTML = `
                <div class="skeleton-error">
                    <i class="bi bi-exclamation-circle"></i>
                    <p>Fehler beim Laden der Inhalte</p>
                    <button class="btn btn-sm btn-primary" data-action="click->skeleton#retry">
                        Erneut versuchen
                    </button>
                </div>
            `;
        }
    }

    retry() {
        this.loadContent();
    }

    waitMinimumDuration() {
        return new Promise(resolve => {
            setTimeout(resolve, this.durationValue);
        });
    }
}
