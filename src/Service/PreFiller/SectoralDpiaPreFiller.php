<?php

declare(strict_types=1);

namespace App\Service\PreFiller;

use App\Dpia\Template\DpiaTemplateDto;
use App\Dpia\Template\DpiaTemplateCatalogue;
use App\Entity\DataProtectionImpactAssessment;
use App\Enum\DpiaStatus;

/**
 * F31 — Applies a curated sectoral DPIA template to a fresh DPIA entity.
 *
 * Called by DPIAController::new() when the user selects a sector template
 * in the optional picker step. Only sets fields that are still blank on the
 * entity — does NOT overwrite values already set (e.g. by DpiaPreFiller::fromRisk).
 *
 * Tenant isolation is the caller's responsibility (DPIAController checks).
 */
final readonly class SectoralDpiaPreFiller
{
    public function __construct(
        private DpiaTemplateCatalogue $catalogue,
    ) {}

    /**
     * Applies the template identified by $key to $dpia.
     *
     * @return DpiaTemplateDto|null Returns the applied template, or null if key is unknown.
     */
    public function applyTemplate(string $key, DataProtectionImpactAssessment $dpia): ?DpiaTemplateDto
    {
        $template = $this->catalogue->find($key);
        if ($template === null) {
            return null;
        }

        // Only set blank fields — preserve any values already pre-filled
        // (e.g. from DpiaPreFiller::fromRisk) or user-typed data on page reload.
        if (in_array($dpia->getProcessingDescription(), [null, '', '0'], true)) {
            $dpia->setProcessingDescription($template->processingDescription);
        }
        if (in_array($dpia->getProcessingPurposes(), [null, '', '0'], true)) {
            $dpia->setProcessingPurposes($template->processingPurposes);
        }
        if ($dpia->getDataCategories() === []) {
            $dpia->setDataCategories($template->dataCategories);
        }
        if ($dpia->getDataSubjectCategories() === []) {
            $dpia->setDataSubjectCategories($template->dataSubjectCategories);
        }
        if (in_array($dpia->getNecessityAssessment(), [null, '', '0'], true)) {
            $dpia->setNecessityAssessment($template->necessityAssessment);
        }
        if (in_array($dpia->getProportionalityAssessment(), [null, '', '0'], true)) {
            $dpia->setProportionalityAssessment($template->proportionalityAssessment);
        }
        if (in_array($dpia->getLegalBasis(), [null, '', '0'], true)) {
            $dpia->setLegalBasis($template->legalBasis);
        }
        if (in_array($dpia->getLegislativeCompliance(), [null, '', '0'], true)) {
            $dpia->setLegislativeCompliance($template->legislativeCompliance);
        }
        if ($dpia->getIdentifiedRisks() === []) {
            $dpia->setIdentifiedRisks($template->identifiedRisks);
        }
        if (in_array($dpia->getRiskLevel(), [null, '', '0'], true)) {
            $dpia->setRiskLevel($template->riskLevel);
        }
        if (in_array($dpia->getLikelihood(), [null, '', '0'], true)) {
            $dpia->setLikelihood($template->likelihood);
        }
        if (in_array($dpia->getImpact(), [null, '', '0'], true)) {
            $dpia->setImpact($template->impact);
        }
        if (in_array($dpia->getDataSubjectRisks(), [null, '', '0'], true)) {
            $dpia->setDataSubjectRisks($template->dataSubjectRisks);
        }
        if (in_array($dpia->getTechnicalMeasures(), [null, '', '0'], true)) {
            $dpia->setTechnicalMeasures($template->technicalMeasures);
        }
        if (in_array($dpia->getOrganizationalMeasures(), [null, '', '0'], true)) {
            $dpia->setOrganizationalMeasures($template->organizationalMeasures);
        }
        if (in_array($dpia->getResidualRiskAssessment(), [null, '', '0'], true)) {
            $dpia->setResidualRiskAssessment($template->residualRiskAssessment);
        }
        if (in_array($dpia->getResidualRiskLevel(), [null, '', '0'], true)) {
            $dpia->setResidualRiskLevel($template->residualRiskLevel);
        }
        // requiresSupervisoryConsultation: only set to true if template recommends it,
        // never force to false (user may have already checked it for a different reason).
        if ($template->requiresSupervisoryConsultation) {
            $dpia->setRequiresSupervisoryConsultation(true);
        }

        // Ensure status is draft so the DPO iterates before submission.
        $dpia->setStatus(DpiaStatus::Draft); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist DPIA)

        return $template;
    }

    public function getCatalogue(): DpiaTemplateCatalogue
    {
        return $this->catalogue;
    }
}
