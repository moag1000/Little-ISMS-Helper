import { Controller } from '@hotwired/stimulus';

/**
 * Risk Slider Controller
 *
 * Provides visual risk scoring with:
 * - Interactive sliders for probability and impact (1-5 scale)
 * - Real-time risk score calculation
 * - Color-coded risk matrix visualization
 * - ISO 27005 compliant risk levels
 *
 * Usage:
 * <div data-controller="risk-slider"
 *      data-risk-slider-probability-value="3"
 *      data-risk-slider-impact-value="4">
 *   <input type="range" data-risk-slider-target="probability" data-action="input->risk-slider#calculate">
 *   <input type="range" data-risk-slider-target="impact" data-action="input->risk-slider#calculate">
 *   <div data-risk-slider-target="score"></div>
 *   <div data-risk-slider-target="level"></div>
 *   <div data-risk-slider-target="matrix"></div>
 * </div>
 */
export default class extends Controller {
    static targets = [
        'probability',
        'probabilityValue',
        'probabilityLabel',
        'impact',
        'impactValue',
        'impactLabel',
        'score',
        'level',
        'matrix',
        'hiddenProbability',
        'hiddenImpact'
    ];

    static values = {
        probability: { type: Number, default: 1 },
        impact: { type: Number, default: 1 }
    };

    // Risk level labels (ISO 27005 aligned)
    probabilityLabels = {
        1: { en: 'Rare', de: 'Selten' },
        2: { en: 'Unlikely', de: 'Unwahrscheinlich' },
        3: { en: 'Possible', de: 'Möglich' },
        4: { en: 'Likely', de: 'Wahrscheinlich' },
        5: { en: 'Almost Certain', de: 'Fast sicher' }
    };

    impactLabels = {
        1: { en: 'Negligible', de: 'Vernachlässigbar' },
        2: { en: 'Minor', de: 'Gering' },
        3: { en: 'Moderate', de: 'Moderat' },
        4: { en: 'Major', de: 'Erheblich' },
        5: { en: 'Severe', de: 'Schwerwiegend' }
    };

    riskLevelConfig = {
        low: { color: '#28a745', bgColor: '#d4edda', label: { en: 'Low Risk', de: 'Niedriges Risiko' }, max: 4 },
        medium: { color: '#ffc107', bgColor: '#fff3cd', label: { en: 'Medium Risk', de: 'Mittleres Risiko' }, max: 9 },
        high: { color: '#fd7e14', bgColor: '#ffe5d0', label: { en: 'High Risk', de: 'Hohes Risiko' }, max: 14 },
        critical: { color: '#dc3545', bgColor: '#f8d7da', label: { en: 'Critical Risk', de: 'Kritisches Risiko' }, max: 25 }
    };

    connect() {
        this.locale = document.documentElement.lang || 'en';
        this.calculate();
    }

    calculate() {
        const probability = this.hasProbabilityTarget
            ? parseInt(this.probabilityTarget.value) || 1
            : this.probabilityValue;
        const impact = this.hasImpactTarget
            ? parseInt(this.impactTarget.value) || 1
            : this.impactValue;

        const score = probability * impact;
        const level = this.getRiskLevel(score);

        // Update hidden form fields if they exist
        if (this.hasHiddenProbabilityTarget) {
            this.hiddenProbabilityTarget.value = probability;
        }
        if (this.hasHiddenImpactTarget) {
            this.hiddenImpactTarget.value = impact;
        }

        // Update probability display
        if (this.hasProbabilityValueTarget) {
            this.probabilityValueTarget.textContent = probability;
        }
        if (this.hasProbabilityLabelTarget) {
            const label = this.probabilityLabels[probability];
            this.probabilityLabelTarget.textContent = label ? label[this.locale] || label.en : '';
        }

        // Update impact display
        if (this.hasImpactValueTarget) {
            this.impactValueTarget.textContent = impact;
        }
        if (this.hasImpactLabelTarget) {
            const label = this.impactLabels[impact];
            this.impactLabelTarget.textContent = label ? label[this.locale] || label.en : '';
        }

        // Update score display
        if (this.hasScoreTarget) {
            this.scoreTarget.textContent = score;
            this.scoreTarget.style.color = level.color;
            this.scoreTarget.style.fontWeight = 'bold';
        }

        // Update level display
        if (this.hasLevelTarget) {
            this.levelTarget.textContent = level.label[this.locale] || level.label.en;
            this.levelTarget.style.backgroundColor = level.bgColor;
            this.levelTarget.style.color = level.color;
            this.levelTarget.style.padding = '4px 12px';
            this.levelTarget.style.borderRadius = '4px';
            this.levelTarget.style.fontWeight = 'bold';
        }

        // Update matrix visualization
        if (this.hasMatrixTarget) {
            this.renderMatrix(probability, impact);
        }

        // Dispatch event for other components
        this.dispatch('calculated', {
            detail: { probability, impact, score, level: this.getRiskLevelName(score) }
        });
    }

    getRiskLevel(score) {
        if (score <= this.riskLevelConfig.low.max) return this.riskLevelConfig.low;
        if (score <= this.riskLevelConfig.medium.max) return this.riskLevelConfig.medium;
        if (score <= this.riskLevelConfig.high.max) return this.riskLevelConfig.high;
        return this.riskLevelConfig.critical;
    }

    getRiskLevelName(score) {
        if (score <= 4) return 'low';
        if (score <= 9) return 'medium';
        if (score <= 14) return 'high';
        return 'critical';
    }

    renderMatrix(selectedProbability, selectedImpact) {
        // Create 5x5 risk matrix
        const matrix = this.matrixTarget;
        matrix.innerHTML = '';
        matrix.style.display = 'grid';
        matrix.style.gridTemplateColumns = 'auto repeat(5, 1fr)';
        matrix.style.gap = '2px';
        matrix.style.fontSize = '0.75rem';
        matrix.style.maxWidth = '300px';

        // Header row (Impact labels)
        matrix.appendChild(this.createCell('', 'header-corner'));
        for (let i = 1; i <= 5; i++) {
            matrix.appendChild(this.createCell(i.toString(), 'header'));
        }

        // Matrix rows (Probability)
        for (let p = 5; p >= 1; p--) {
            // Row label
            matrix.appendChild(this.createCell(p.toString(), 'row-label'));

            // Risk cells
            for (let i = 1; i <= 5; i++) {
                const score = p * i;
                const level = this.getRiskLevel(score);
                const isSelected = p === selectedProbability && i === selectedImpact;

                const cell = this.createCell(score.toString(), 'cell', level.bgColor);
                if (isSelected) {
                    cell.style.border = '3px solid #000';
                    cell.style.fontWeight = 'bold';
                }
                cell.style.cursor = 'pointer';
                cell.dataset.probability = p;
                cell.dataset.impact = i;
                cell.addEventListener('click', () => this.selectFromMatrix(p, i));

                matrix.appendChild(cell);
            }
        }

        // Labels
        const probabilityLabel = document.createElement('div');
        probabilityLabel.textContent = this.locale === 'de' ? 'Wahrscheinlichkeit ↑' : 'Probability ↑';
        probabilityLabel.style.gridColumn = '1';
        probabilityLabel.style.writingMode = 'vertical-rl';
        probabilityLabel.style.textAlign = 'center';
        probabilityLabel.style.fontSize = '0.65rem';
        probabilityLabel.style.color = '#6c757d';

        const impactLabel = document.createElement('div');
        impactLabel.textContent = this.locale === 'de' ? 'Auswirkung →' : 'Impact →';
        impactLabel.style.gridColumn = '2 / -1';
        impactLabel.style.textAlign = 'center';
        impactLabel.style.fontSize = '0.65rem';
        impactLabel.style.color = '#6c757d';
        impactLabel.style.marginTop = '4px';

        matrix.appendChild(impactLabel);
    }

    createCell(content, type, bgColor = null) {
        const cell = document.createElement('div');
        cell.textContent = content;
        cell.style.padding = '6px';
        cell.style.textAlign = 'center';
        cell.style.borderRadius = '2px';

        if (type === 'header' || type === 'row-label') {
            cell.style.backgroundColor = '#f8f9fa';
            cell.style.fontWeight = '500';
        } else if (type === 'header-corner') {
            cell.style.backgroundColor = 'transparent';
        } else if (bgColor) {
            cell.style.backgroundColor = bgColor;
        }

        return cell;
    }

    selectFromMatrix(probability, impact) {
        if (this.hasProbabilityTarget) {
            this.probabilityTarget.value = probability;
        }
        if (this.hasImpactTarget) {
            this.impactTarget.value = impact;
        }
        this.calculate();
    }

    // Preset risk levels for quick selection
    setLow() {
        this.setValues(2, 2);
    }

    setMedium() {
        this.setValues(3, 3);
    }

    setHigh() {
        this.setValues(4, 4);
    }

    setCritical() {
        this.setValues(5, 5);
    }

    setValues(probability, impact) {
        if (this.hasProbabilityTarget) {
            this.probabilityTarget.value = probability;
        }
        if (this.hasImpactTarget) {
            this.impactTarget.value = impact;
        }
        this.calculate();
    }
}
