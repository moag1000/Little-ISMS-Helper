<?php

declare(strict_types=1);

namespace App\Service\Compliance\Fulfillment;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * The only writer of a fulfilment's percentage and status.
 *
 * - apply(): evaluates the rule and makes its value effective unless a person
 *   overrode it. The first evaluation of a row keeps an older hand value that
 *   differs from the rule as an override "Altbestand" — nothing is lost.
 * - override()/clearOverride(): a hand value next to a rule needs a reason and
 *   a review date; clearing hands control back to the rule.
 * - setManual(): requirements without a rule are assessed by hand as before.
 * - refresh*(): recompute after control changes, per framework or per
 *   organisation (nightly scheduler task app:compliance:refresh-fulfillments).
 */
class FulfillmentDerivationService
{
    public const LEGACY_REVIEW_DAYS = 90;

    public function __construct(
        private readonly FulfillmentRuleEngine $engine,
        private readonly AffectedRequirementFinder $finder,
        private readonly ComplianceRequirementFulfillmentRepository $fulfillmentRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
    }

    /**
     * Evaluates the requirement's rule for the fulfilment's organisation.
     *
     * @return bool true when the effective value, status or applicability changed
     */
    public function apply(ComplianceRequirementFulfillment $fulfillment, ?DateTimeImmutable $now = null): bool
    {
        $tenant = $fulfillment->getTenant();
        $requirement = $fulfillment->getRequirement();
        if (!$tenant instanceof Tenant || !$requirement instanceof ComplianceRequirement) {
            return false;
        }

        $now ??= new DateTimeImmutable();
        $before = [$fulfillment->getFulfillmentPercentage(), $fulfillment->getStatus(), $fulfillment->isApplicable()];
        $firstEvaluation = $fulfillment->getDerivedAt() === null && $fulfillment->getId() !== null;

        $derivation = $this->engine->derive($requirement, $tenant);
        if (!$derivation instanceof FulfillmentDerivation) {
            $fulfillment->setDerivation(null, null, [], $now); // no rule: the hand value stays authoritative
            return false;
        }

        $fulfillment->setDerivation($derivation->percentage, $derivation->kind, $derivation->basis, $now);
        if ($derivation->applicable !== null) {
            $fulfillment->setApplicable($derivation->applicable);
            if ($derivation->applicable === false && $derivation->applicabilityJustification !== null) {
                $fulfillment->setApplicabilityJustification($derivation->applicabilityJustification);
            }
        }

        if ($derivation->percentage !== null) {
            if ($firstEvaluation && !$fulfillment->isOverridden() && $this->isHandValue($fulfillment, $derivation->percentage)) {
                $fulfillment->markOverride(
                    ComplianceRequirementFulfillment::OVERRIDE_LEGACY,
                    null,
                    $now->modify('+' . self::LEGACY_REVIEW_DAYS . ' days')->setTime(0, 0),
                    null,
                    $now,
                );
            }
            if (!$fulfillment->isOverridden()) {
                $this->setEffective($fulfillment, $derivation->percentage, $derivation->verified);
            }
        }

        return $before !== [$fulfillment->getFulfillmentPercentage(), $fulfillment->getStatus(), $fulfillment->isApplicable()];
    }

    /**
     * A person sets a value that differs from the rule — with reason and review date.
     */
    public function override(
        ComplianceRequirementFulfillment $fulfillment,
        int $percentage,
        string $reason,
        DateTimeImmutable $reviewDue,
        ?User $by,
        string $source = ComplianceRequirementFulfillment::OVERRIDE_MANUAL,
    ): void {
        $old = $fulfillment->getFulfillmentPercentage();
        $fulfillment->markOverride($source, trim($reason), $reviewDue->setTime(0, 0), $by, new DateTimeImmutable());
        $this->setEffective($fulfillment, $percentage);
        if ($by instanceof User) {
            $fulfillment->setLastUpdatedBy($by);
        }

        $this->auditLogger->logCustom(
            'compliance.fulfillment.override',
            'ComplianceRequirementFulfillment',
            $fulfillment->getId(),
            ['fulfillment_percentage' => $old],
            [
                'fulfillment_percentage' => $fulfillment->getFulfillmentPercentage(),
                'rule_percentage' => $fulfillment->getDerivedPercentage(),
                'override_source' => $source,
                'override_reason' => trim($reason),
                'override_review_due' => $reviewDue->format('Y-m-d'),
            ],
            sprintf('Fulfilment of %s overridden', (string) $fulfillment->getRequirement()?->getRequirementId()),
        );
    }

    /** Hands the value back to the rule. */
    public function clearOverride(ComplianceRequirementFulfillment $fulfillment, ?User $by): void
    {
        if (!$fulfillment->isOverridden()) {
            return;
        }
        $old = ['fulfillment_percentage' => $fulfillment->getFulfillmentPercentage(), 'override_source' => $fulfillment->getOverrideSource()];
        $fulfillment->clearOverride();
        $this->apply($fulfillment);
        if ($by instanceof User) {
            $fulfillment->setLastUpdatedBy($by);
        }

        $this->auditLogger->logCustom(
            'compliance.fulfillment.override_cleared',
            'ComplianceRequirementFulfillment',
            $fulfillment->getId(),
            $old,
            ['fulfillment_percentage' => $fulfillment->getFulfillmentPercentage()],
            sprintf('Fulfilment of %s follows its rule again', (string) $fulfillment->getRequirement()?->getRequirementId()),
        );
    }

    /**
     * Hand assessment of a requirement without rule.
     *
     * @return bool false when a rule computes the requirement (use override())
     */
    public function setManual(ComplianceRequirementFulfillment $fulfillment, int $percentage): bool
    {
        if ($fulfillment->isDerived()) {
            return false;
        }
        $this->setEffective($fulfillment, $percentage);

        return true;
    }

    /**
     * Recomputes every requirement a set of controls (one organisation) feeds,
     * mapping targets after their sources. Missing rows are created for the
     * frameworks the organisation works with.
     *
     * @param list<Control> $controls
     * @return int number of fulfilments whose effective value changed
     */
    public function refreshForControls(Tenant $tenant, array $controls): int
    {
        $this->engine->forgetTenant($tenant);

        return $this->refreshWaves($tenant, $this->finder->wavesForControls($controls));
    }

    /**
     * Recomputes every requirement the organisation's records of these check
     * types answer (register entries → register_coverage, top-management
     * decisions → management_decision, …), after such records changed.
     *
     * @param list<string> $checkTypes
     * @return int number of fulfilments whose effective value changed
     */
    public function refreshForRecords(Tenant $tenant, array $checkTypes): int
    {
        $this->engine->forgetTenant($tenant);
        $requirements = [];
        foreach ($this->engine->recordReferences($tenant, $checkTypes) as $code => $refs) {
            $framework = $this->frameworkRepository->findOneBy(['code' => $code]);
            if (!$framework instanceof ComplianceFramework) {
                continue;
            }
            foreach ($this->requirementRepository->findBy(['framework' => $framework]) as $requirement) {
                $number = RecordCheckRule::requirementNumber($code, (string) $requirement->getRequirementId());
                foreach ($refs as $ref) {
                    if ($number !== '' && RecordCheckRule::covers($ref, $number)) {
                        $requirements[] = $requirement;
                        break;
                    }
                }
            }
        }

        return $requirements === [] ? 0 : $this->refreshRequirements($tenant, $requirements);
    }

    /**
     * After an audit verdict changed (checklist save): drops the cached
     * verdicts and recomputes the audited requirements.
     *
     * @param list<ComplianceRequirement> $requirements
     */
    public function refreshAudited(Tenant $tenant, array $requirements): int
    {
        $this->engine->forgetTenant($tenant);

        return $requirements === [] ? 0 : $this->refreshRequirements($tenant, $requirements);
    }

    /**
     * Recomputes the given requirements and everything inheriting from them.
     *
     * @param list<ComplianceRequirement> $requirements
     */
    public function refreshRequirements(Tenant $tenant, array $requirements): int
    {
        return $this->refreshWaves($tenant, $this->finder->withMappingWaves($requirements));
    }

    /**
     * Recomputes a framework for one organisation and creates the rows a rule
     * now computes ("Neu berechnen", TISAX import).
     *
     * @return array{derived: int, overridden: int, manual: int, changed: int}
     */
    public function refreshFramework(Tenant $tenant, ComplianceFramework $framework): array
    {
        $stats = ['derived' => 0, 'overridden' => 0, 'manual' => 0, 'changed' => 0];
        $now = new DateTimeImmutable();
        foreach ($this->requirementRepository->findBy(['framework' => $framework]) as $requirement) {
            $fulfillment = $this->fulfillmentRepository->findOrCreateForTenantAndRequirement($tenant, $requirement);
            $isNew = $fulfillment->getId() === null;
            $changed = $this->apply($fulfillment, $now);
            if ($isNew && !$fulfillment->isDerived()) {
                continue; // nothing to store for a requirement nobody assessed and no rule computes
            }
            if ($isNew) {
                $this->em->persist($fulfillment);
            }
            $stats['changed'] += $changed ? 1 : 0;
            match (true) {
                $fulfillment->isOverridden() => $stats['overridden']++,
                $fulfillment->isDerived() => $stats['derived']++,
                default => $stats['manual']++,
            };
        }

        return $stats;
    }

    /**
     * Nightly: every framework the organisation works with (ISO 27001 first,
     * because most mappings inherit from it) plus ISO 27001 itself once the
     * organisation has controls.
     *
     * @return array{derived: int, overridden: int, manual: int, changed: int}
     */
    public function refreshTenant(Tenant $tenant): array
    {
        $this->engine->forgetTenant($tenant);
        $inUse = $this->finder->frameworkIdsInUse($tenant);
        $iso = $this->frameworkRepository->findOneBy(['code' => 'ISO27001']);
        if ($iso instanceof ComplianceFramework) {
            $inUse = [(int) $iso->getId() => true] + $inUse;
        }

        $total = ['derived' => 0, 'overridden' => 0, 'manual' => 0, 'changed' => 0];
        foreach (array_keys($inUse) as $frameworkId) {
            $framework = $this->frameworkRepository->find($frameworkId);
            if (!$framework instanceof ComplianceFramework) {
                continue;
            }
            foreach ($this->refreshFramework($tenant, $framework) as $key => $count) {
                $total[$key] += $count;
            }
            $this->em->flush();
        }

        return $total;
    }

    public function flush(): void
    {
        $this->em->flush();
    }

    /** Stores one fulfilment (new rows included). */
    public function save(ComplianceRequirementFulfillment $fulfillment): void
    {
        if ($fulfillment->getId() === null) {
            $this->em->persist($fulfillment);
        }
        $this->em->flush();
    }

    /**
     * @param list<list<ComplianceRequirement>> $waves
     */
    private function refreshWaves(Tenant $tenant, array $waves): int
    {
        if ($waves === []) {
            return 0;
        }
        $inUse = $this->finder->frameworkIdsInUse($tenant);
        $now = new DateTimeImmutable();
        $changed = 0;

        foreach ($waves as $wave) {
            foreach ($wave as $requirement) {
                $fulfillment = $this->fulfillmentRepository->findOneBy(['tenant' => $tenant, 'requirement' => $requirement]);
                if (!$fulfillment instanceof ComplianceRequirementFulfillment) {
                    if (!isset($inUse[(int) $requirement->getFramework()?->getId()])
                        && !FulfillmentRuleEngine::isAnnexARequirement($requirement)) {
                        continue;
                    }
                    $fulfillment = (new ComplianceRequirementFulfillment())
                        ->setTenant($tenant)
                        ->setRequirement($requirement);
                    $this->apply($fulfillment, $now);
                    if ($fulfillment->isDerived()) {
                        $this->em->persist($fulfillment);
                        $changed++;
                    }
                    continue;
                }
                if ($this->apply($fulfillment, $now)) {
                    $changed++;
                }
            }
        }

        return $changed;
    }

    private function setEffective(ComplianceRequirementFulfillment $fulfillment, int $percentage, bool $auditVerified = false): void
    {
        $percentage = max(0, min(100, $percentage));
        $fulfillment->setFulfillmentPercentage($percentage);

        $verified = $auditVerified || $fulfillment->getStatus() === ComplianceRequirementFulfillmentStatus::Verified->value;
        $fulfillment->setStatus(match (true) {
            $percentage >= 100 && $verified => ComplianceRequirementFulfillmentStatus::Verified,
            $percentage >= 100 => ComplianceRequirementFulfillmentStatus::Implemented,
            $percentage > 0 => ComplianceRequirementFulfillmentStatus::InProgress,
            default => ComplianceRequirementFulfillmentStatus::NotStarted,
        });
    }

    /** Was the stored value set by a person (or an old writer) and not by the rule? */
    private function isHandValue(ComplianceRequirementFulfillment $fulfillment, int $rulePercentage): bool
    {
        if ($fulfillment->getFulfillmentPercentage() === $rulePercentage) {
            return false;
        }

        return $fulfillment->getFulfillmentPercentage() > 0
            || $fulfillment->getStatus() !== ComplianceRequirementFulfillmentStatus::NotStarted->value;
    }
}
