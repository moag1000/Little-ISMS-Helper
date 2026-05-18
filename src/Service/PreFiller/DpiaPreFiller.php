<?php

declare(strict_types=1);

namespace App\Service\PreFiller;

use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Risk;
use App\Enum\DpiaStatus;

/**
 * Sprint-2 P-7 Wave-2 — DPIA Pre-Filler from a Risk source.
 *
 * Called by {@see App\Controller\DPIAController::new()} when the AlvaHint
 * action button "DPIA anlegen mit Vorbefüllung" is taken from a Risk
 * with `requiresDPIA = true`. Maps the relevant Risk metadata onto a
 * fresh DPIA skeleton so the DPO does not have to retype the context
 * (Data-Reuse principle, GDPR Art. 35(7)).
 *
 * Tenant isolation is the caller's responsibility — this service
 * assumes the Risk and the DPIA share a tenant (caller checks).
 */
final readonly class DpiaPreFiller
{
    public function fromRisk(Risk $risk, DataProtectionImpactAssessment $dpia): DataProtectionImpactAssessment
    {
        // Title: keep DPIA naming convention "DPIA: <Risk title>".
        $dpia->setTitle(sprintf('DPIA: %s', (string) ($risk->getTitle() ?? 'Risk')));

        // Processing description / purpose carry the risk narrative as
        // a sane starting point. DPO will refine before submission.
        $description = trim((string) $risk->getDescription());
        if ($description !== '') {
            $dpia->setProcessingDescription($description);
        } else {
            $dpia->setProcessingDescription(
                'Pre-filled from Risk: please describe the processing operations (Art. 35(7)(a) GDPR).'
            );
        }

        // ProcessingPurposes is NotBlank — we MUST set a placeholder so
        // the form does not auto-reject on submit. DPO is expected to
        // overwrite this string.
        $dpia->setProcessingPurposes(
            'Pre-filled placeholder — replace with the concrete processing purpose (Art. 35(7)(a) GDPR).'
        );

        // Necessity assessment placeholder (also NotBlank in the entity).
        $dpia->setNecessityAssessment(
            'Pre-filled placeholder — describe why this processing is necessary (Art. 35(7)(b) GDPR).'
        );

        // Reuse the linked Asset from the Risk if present — the DPIA
        // links 1:1 to an Asset (e.g. AI agent under EU AI Act Art. 9).
        $asset = $risk->getAsset();
        if ($asset !== null) {
            $dpia->setRelatedAsset($asset);
        }

        // Pre-fill data-subject-impact narrative into the DPIA's
        // dataSubjectRisks slot (Art. 35(7)(c)) if Risk has one.
        $dataSubjectImpact = method_exists($risk, 'getDataSubjectImpact')
            ? trim((string) $risk->getDataSubjectImpact())
            : '';
        if ($dataSubjectImpact !== '' && method_exists($dpia, 'setDataSubjectRisks')) {
            $dpia->setDataSubjectRisks($dataSubjectImpact);
        }

        // Always start in 'draft' so the DPO can iterate before submission.
        $dpia->setStatus(DpiaStatus::Draft); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist DPIA; 'draft' is the dpia_lifecycle initial_marking)

        return $dpia;
    }
}
