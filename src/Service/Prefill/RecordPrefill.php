<?php

declare(strict_types=1);

namespace App\Service\Prefill;

use App\Entity\Asset;
use App\Entity\BCExercise;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Location;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\Vulnerability;
use App\Repository\AssetRepository;
use App\Repository\IncidentRepository;
use App\Repository\LocationRepository;
use App\Repository\RiskRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\Deadline\Source\ManagementTrainingSource;
use App\Service\Location\LocationLayers;
use DateTimeImmutable;
use Doctrine\Persistence\ObjectRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Context prefill for /new forms (UI wave F, "nichts zweimal tippen"): a link
 * like `/risk/new?fromAsset=12` opens the form with what the source record
 * already knows. Only records of the same organisation are read; anything
 * else is ignored silently, so a foreign id never leaks data into a form.
 *
 * Sources: Risk ← Vulnerability, Incident, Asset, Location (map quick action);
 * Treatment plan ← Risk; internal audit and BC exercise ← Location (map);
 * board training ← the members of top management due (NIS-2 Art. 20 (2)).
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
        private readonly LocationRepository $locationRepository,
        private readonly LocationLayers $locationLayers,
        private readonly ?ManagementTrainingSource $managementTraining = null,
    ) {
    }

    /**
     * Board training (NIS-2 Art. 20 (2)): `?forBoard=1` opens a mandatory
     * training with every member of top management whose training is due
     * within the reminder's lead time as participant.
     */
    public function boardTraining(Training $training, Request $request, ?Tenant $tenant): void
    {
        if (!$request->query->getBoolean('forBoard') || $tenant === null || $this->managementTraining === null) {
            return;
        }
        $training->setTitle($this->trans('training.prefill.board_title', [], 'training'));
        $training->setTrainingType('compliance');
        $training->setMandatory(true);
        $horizon = new DateTimeImmutable(sprintf('+%d days', $this->managementTraining->profile()->leadDays ?? 30));
        foreach ($this->managementTraining->trainees($tenant) as $trainee) {
            if ($trainee->dueAt <= $horizon) {
                $training->addParticipantUser($trainee->user);
            }
        }
    }

    /** Audit of one site: scope type "location", the site, a title naming it. */
    public function internalAudit(InternalAudit $audit, Request $request, ?Tenant $tenant): void
    {
        $location = $this->source($this->locationRepository, $request, 'fromLocation', $tenant);
        if (!$location instanceof Location) {
            return;
        }
        $name = (string) $location->getName();
        $audit->setScopeType('location');
        $audit->setLocation($location);
        $audit->setScopeDetails(['location' => $name]);
        $audit->setTitle($this->trans('audit.prefill.title_from_location', ['%name%' => $name], 'audit'));
        $audit->setScope($this->trans('audit.prefill.scope_from_location', ['%name%' => $name], 'audit'));
    }

    /** BC exercise at one site: scope naming it, the BC plans of the processes there. */
    public function bcExercise(BCExercise $exercise, Request $request, ?Tenant $tenant): void
    {
        $location = $this->source($this->locationRepository, $request, 'fromLocation', $tenant);
        if (!$location instanceof Location || $tenant === null) {
            return;
        }
        $root = $location;
        $guard = 0;
        while (($parent = $root->getParentLocation()) !== null && $parent->getTenant() === $tenant && $guard++ < 50) {
            $root = $parent;
        }
        $name = (string) $location->getName();
        $exercise->setName($this->trans('bc_exercises.prefill.name_from_location', ['%name%' => $name], 'bc_exercises'));
        $exercise->setScope($this->trans('bc_exercises.prefill.scope_from_location', ['%name%' => $name], 'bc_exercises'));
        foreach ($this->locationLayers->plansAtSite($tenant, (int) $root->getId()) as $plan) {
            $exercise->addTestedPlan($plan);
        }
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

        $location = $this->source($this->locationRepository, $request, 'fromLocation', $tenant);
        if ($location instanceof Location) {
            $risk->setLocation($location);
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
