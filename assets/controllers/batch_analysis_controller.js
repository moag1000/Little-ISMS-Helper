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

        // Show progress UI — using classList because the elements use the
        // Bootstrap `d-none` utility class, which sets `display:none!important`
        // and therefore is NOT overridable via inline `style.display = 'block'`.
        if (this.hasProgressTarget) {
            this.progressTarget.classList.remove('d-none');
        }
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.add('d-none');
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

    async processBatches(count, reanalyze) {
        const isAll = count === 'all';
        let remaining = isAll ? Infinity : parseInt(count);
        // Offset stays 0 for incremental analysis: the backend filters out
        // already-analyzed records, so the next batch's "first row" is again
        // an unanalyzed record. Advancing offset would skip exactly the rows
        // we just processed (they fall out of the filtered set), causing
        // "alle analysieren" to miss every other batch_size chunk.
        // For reanalyze=true the filter doesn't exclude, so offset must
        // advance to avoid re-processing the same chunk.
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
                        // Backend parameter is `reanalyze`, not `force`.
                        reanalyze: reanalyze
                    })
                });

                const result = await response.json();

                if (!result.success) {
                    this.errors++;
                    this.log('Error: ' + (result.error || result.message || 'unknown error'));
                    break;
                }

                const processed = result.analyzed || 0;
                this.analyzed += processed;
                this.errors += result.errors || 0;

                if (processed === 0) {
                    // No more to process
                    break;
                }

                if (!isAll) {
                    remaining -= processed;
                }

                // Only advance offset in reanalyze mode (filter doesn't shrink
                // the result set there). For incremental analysis stay at 0.
                if (reanalyze) {
                    offset += batchSize;
                }

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
            this.progressTarget.classList.add('d-none');
        }
        if (this.hasResultsTarget) {
            this.resultsTarget.classList.remove('d-none');
        }
        if (this.hasResultsMessageTarget) {
            this.resultsMessageTarget.textContent =
                `${this.analyzed} Mappings analysiert, ${this.errors} Fehler`;
        }
        this.updateStatus(this.cancelledValue ? 'Abgebrochen' : 'Fertig');
    }
}
