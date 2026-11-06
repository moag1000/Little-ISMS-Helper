<?php

declare(strict_types=1);

namespace App\Service\Prefill;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Tenant;
use App\Entity\Vulnerability;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\RiskRepository;
use App\Repository\VulnerabilityRepository;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Context prefill for /new forms (UI wave F, "nichts zweimal tippen"): a link
 * like `/risk/new?fromAsset=12` opens the form with what the source record
 * already knows. Only records of the same organisation are read; anything
 * else is ignored silently, so a foreign id never leaks data into a form.
 *
 * Sources: Risk ← Vulnerability, Incident, Asset; Treatment plan ← Risk.
 * Control → evidence needs no form: the control page uploads evidence in place.
 */
class RecordPrefill
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly VulnerabilityRepository $vulnerabilityRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly AssetRepository $assetRepository,
        private readonly RiskRepository $riskRepository,
    ) {
    }

    public function risk(Risk $risk, Request $request, ?Tenant $tenant): void
    {
        $vulnerability = $this->source($this->vulnerabilityRepository, $request, 'fromVulnerability', $tenant);
        if ($vulnerability instanceof Vulnerability) {
            $title = (string) $vulnerability->getTitle();
            $risk->setTitle($this->trans('risk.prefill.title_from_vulnerability', ['%title%' => $title], 'risk'));
            $risk->setDescription($this->derived(
                (string) $vulnerability->getDescription(),
                $this->trans('risk.prefill.note_from_vulnerability', ['%id%' => (string) $vulnerability->getId()], 'risk'),
            ));
            $risk->setThreat($this->trans('risk.prefill.threat_from_vulnerability', ['%title%' => $title], 'risk'));
            $risk->setCategory('security');
            $risk->setLinkedVulnerability($vulnerability);
        }

        $incident = $this->source($this->incidentRepository, $request, 'fromIncident', $tenant);
        if ($incident instanceof Incident) {
            $risk->setTitle($this->trans('risk.prefill.title_from_incident', ['%title%' => (string) $incident->getTitle()], 'risk'));
            $risk->setDescription($this->derived(
                (string) $incident->getDescription(),
                $this->trans('risk.prefill.note_from_incident', ['%id%' => (string) $incident->getId()], 'risk'),
            ));
            $risk->setCategory('operational');
        }

        $asset = $this->source($this->assetRepository, $request, 'fromAsset', $tenant);
        if ($asset instanceof Asset) {
            $risk->setAsset($asset);
            if ($risk->getTitle() === null || $risk->getTitle() === '') {
                $risk->setTitle($this->trans('risk.prefill.title_from_asset', ['%name%' => (string) $asset->getName()], 'risk'));
                $risk->setDescription($this->trans('risk.prefill.note_from_asset', ['%id%' => (string) $asset->getId()], 'risk'));
            }
            // The asset's protection need is the impact baseline (ISO 27005 8.3.2); the user adjusts it.
            $protection = max(
                (int) $asset->getConfidentialityValue(),
                (int) $asset->getIntegrityValue(),
                (int) $asset->getAvailabilityValue(),
            );
            if ($protection >= 1 && $protection <= 5) {
                $risk->setImpact($protection);
            }
        }
    }

    public function treatmentPlan(RiskTreatmentPlan $plan, Request $request, ?Tenant $tenant): void
    {
        // `risk` is the older parameter name some links still use.
        $risk = $this->source($this->riskRepository, $request, 'fromRisk', $tenant)
            ?? $this->source($this->riskRepository, $request, 'risk', $tenant);
        if (!$risk instanceof Risk) {
            return;
        }

        $plan->setRisk($risk);
        $plan->setTitle($this->trans('risk_treatment_plan.prefill.title_from_risk', ['%title%' => (string) $risk->getTitle()], 'risk_treatment_plan'));
        if ($plan->getResponsiblePersonUser() === null && $risk->getRiskOwner() !== null) {
            $plan->setResponsiblePersonUser($risk->getRiskOwner());
        }
    }

    /** @param ObjectRepository<object> $repository */
    private function source(ObjectRepository $repository, Request $request, string $parameter, ?Tenant $tenant): ?object
    {
        $id = $request->query->getInt($parameter);
        if ($id <= 0 || $tenant === null) {
            return null;
        }
        $record = $repository->find($id);
        if ($record === null || !method_exists($record, 'getTenant') || $record->getTenant() !== $tenant) {
            return null;
        }

        return $record;
    }

    private function derived(string $text, string $note): string
    {
        return trim($text . "\n\n" . $note);
    }

    /** @param array<string, string> $parameters */
    private function trans(string $key, array $parameters, string $domain): string
    {
        return $this->translator->trans($key, $parameters, $domain);
    }
}
