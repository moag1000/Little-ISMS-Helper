import { Controller } from '@hotwired/stimulus';

/**
 * Batch Analysis Controller - Compliance mapping quality analysis
 *
 * Usage:
 * <div data-controller="batch-analysis"
 *      data-batch-analysis-url-value="/compliance/mapping/analyze">
 *     <button data-action="click->batch-analysis#start"
 *             data-batch-analysis-count-param="10"
 *             data-batch-analysis-force-param="false">
 *         Analyze 10
 *     </button>
 *     <button data-action="click->batch-analysis#cancel">Cancel</button>
 *     <div data-batch-analysis-target="progress"></div>
 *     <div data-batch-analysis-target="progressBar"></div>
 *     <div data-batch-analysis-target="results"></div>
 * </div>
 */
export default class extends Controller {
    static targets = ['progress', 'progressBar', 'progressText', 'analyzedCount',
                      'remainingCount', 'errorCount', 'statusText', 'results',
                      'resultsMessage', 'log', 'cancelBtn'];
    static values = {
        url: String,
        batchSize: { type: Number, default: 5 },
        running: { type: Boolean, default: false },
        cancelled: { type: Boolean, default: false }
    };

    connect() {
        this.analyzed = 0;
        this.errors = 0;
        this.total = 0;
    }

    async start(event) {
        event.preventDefault();

        const count = event.params.count;
        const force = event.params.force === 'true' || event.params.force === true;

        if (this.runningValue) return;

        this.runningValue = true;
        this.cancelledValue = false;
        this.analyzed = 0;
        this.errors = 0;

        // Show progress UI
        if (this.hasProgressTarget) {
            this.progressTarget.style.display = 'block';
        }
        if (this.hasResultsTarget) {
            this.resultsTarget.style.display = 'none';
        }

        // Calculate total
        this.total = count === 'all' ? parseInt(this.element.dataset.totalUnanalyzed || 0) : parseInt(count);

        this.updateProgress();
        this.updateStatus('{{ "compliance.status.analyzing"|trans({}, "compliance") }}' || 'Analyzing...');

        try {
            await this.processBatches(count, force);
        } catch (error) {
            this.updateStatus('Error: ' + error.message);
        }

        this.runningValue = false;
    }

    async processBatches(count, force) {
        const isAll = count === 'all';
        let remaining = isAll ? Infinity : parseInt(count);
        let offset = 0;

        while (remaining > 0 && !this.cancelledValue) {
            const batchSize = Math.min(this.batchSizeValue, remaining);

            try {
                const response = await fetch(this.urlValue, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: JSON.stringify({
                        limit: batchSize,
                        offset: offset,
                        force: force
                    })
                });

                const result = await response.json();

                if (!result.success) {
                    this.errors++;
                    this.log('Error: ' + result.message);
                    break;
                }

                const processed = result.analyzed || 0;
                this.analyzed += processed;

                if (processed === 0) {
                    // No more to process
                    break;
                }

                if (!isAll) {
                    remaining -= processed;
                }
                offset += batchSize;

                this.updateProgress();

            } catch (error) {
                this.errors++;
                this.log('Request error: ' + error.message);
                break;
            }

            // Small delay between batches
            await new Promise(resolve => setTimeout(resolve, 100));
        }

        this.showResults();
    }

    cancel() {
        this.cancelledValue = true;
        this.updateStatus('{{ "common.cancelled"|trans({}, "messages") }}' || 'Cancelled');
    }

    updateProgress() {
        const percent = this.total > 0 ? Math.round((this.analyzed / this.total) * 100) : 0;

        if (this.hasProgressBarTarget) {
            this.progressBarTarget.style.width = percent + '%';
        }
        if (this.hasProgressTextTarget) {
            this.progressTextTarget.textContent = percent + '%';
        }
        if (this.hasAnalyzedCountTarget) {
            this.analyzedCountTarget.textContent = this.analyzed;
        }
        if (this.hasRemainingCountTarget) {
            this.remainingCountTarget.textContent = Math.max(0, this.total - this.analyzed);
        }
        if (this.hasErrorCountTarget) {
            this.errorCountTarget.textContent = this.errors;
        }
    }

    updateStatus(status) {
        if (this.hasStatusTextTarget) {
            this.statusTextTarget.textContent = status;
        }
    }

    log(message) {
        if (this.hasLogTarget) {
            this.logTarget.style.display = 'block';
            const time = new Date().toLocaleTimeString();
            this.logTarget.innerHTML += `[${time}] ${message}<br>`;
            this.logTarget.scrollTop = this.logTarget.scrollHeight;
        }
    }

    showResults() {
        if (this.hasProgressTarget) {
            this.progressTarget.style.display = 'none';
        }
        if (this.hasResultsTarget) {
            this.resultsTarget.style.display = 'block';
        }
        if (this.hasResultsMessageTarget) {
            this.resultsMessageTarget.textContent =
                `${this.analyzed} Mappings analysiert, ${this.errors} Fehler`;
        }
        this.updateStatus(this.cancelledValue ? 'Abgebrochen' : 'Fertig');
    }
}
