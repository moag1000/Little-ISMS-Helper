<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\Compliance\RequirementMaturity;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Steuert das Reifegrad-Tracking für MRIS-MHC-Requirements
 * (Initial/Defined/Managed) gem. Peddi (2026), MRIS v1.5 Kap. 9.5.
 *
 * Ist und Soll gehören der Organisation (S18): sie liegen an ihrer Erfüllungszeile, nie an der
 * gemeinsamen Katalog-Anforderung — siehe {@see RequirementMaturity}.
 *
 * Lizenz Quellwerk: CC BY 4.0.
 */
final class MrisMaturityService
{
    public const STAGES = ['initial', 'defined', 'managed'];

    /** @var array<string, int> */
    private const STAGE_RANK = ['initial' => 1, 'defined' => 2, 'managed' => 3];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly RequirementMaturity $maturity,
    ) {
    }

    /**
     * Setzt den Ist-Stand. Loggt Vorher/Nachher im Audit-Trail (logUpdate).
     */
    public function setCurrent(ComplianceRequirement $requirement, Tenant $tenant, ?string $stage): void
    {
        $this->guardStage($stage);
        $previous = $this->maturity->current($requirement, $tenant);
        if ($previous === $stage) {
            return;
        }
        $this->maturity->setCurrent($requirement, $tenant, $stage);
        $this->entityManager->flush();

        $this->auditLogger->logUpdate(
            entityType: 'ComplianceRequirement',
            entityId: $requirement->getId(),
            oldValues: ['maturity_current' => $previous],
            newValues: ['maturity_current' => $stage],
            description: sprintf('MRIS-Reifegrad (Ist) %s → %s für %s', $previous ?? 'null', $stage ?? 'null', $requirement->getRequirementId() ?? '?'),
        );
    }

    /**
     * Setzt den Soll-Stand. Loggt Vorher/Nachher im Audit-Trail (logUpdate),
     * damit Soll-Anhebungen im Quartals-Review nachvollziehbar sind.
     */
    public function setTarget(ComplianceRequirement $requirement, Tenant $tenant, ?string $stage): void
    {
        $this->guardStage($stage);
        $previous = $this->maturity->target($requirement, $tenant);
        if ($previous === $stage) {
            return;
        }
        $this->maturity->setTarget($requirement, $tenant, $stage);
        $this->entityManager->flush();

        $this->auditLogger->logUpdate(
            entityType: 'ComplianceRequirement',
            entityId: $requirement->getId(),
            oldValues: ['maturity_target' => $previous],
            newValues: ['maturity_target' => $stage],
            description: sprintf('MRIS-Reifegrad (Soll) %s → %s für %s', $previous ?? 'null', $stage ?? 'null', $requirement->getRequirementId() ?? '?'),
        );
    }

    /**
     * Setzt das Ist aller Organisationen zurück (MRIS-Versionswechsel hat die MHC geändert).
     */
    public function resetCurrentEverywhere(ComplianceRequirement $requirement, string $reason): void
    {
        $cleared = $this->maturity->clearCurrentEverywhere($requirement);
        if ($cleared === 0) {
            return;
        }
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'MrisMaturityReset',
            entityType: 'ComplianceRequirement',
            entityId: $requirement->getId(),
            oldValues: ['organisations_with_current' => $cleared],
            newValues: ['maturity_current' => null],
            description: sprintf('MRIS-Reifegrad (Ist) zurückgesetzt für %s: %s', $requirement->getRequirementId() ?? '?', $reason),
        );
    }

    /**
     * Liefert das Soll-Ist-Delta in Stufen.
     * Positiv: Soll > Ist (Lücke). Null: Soll erfüllt. Negativ: Ist > Soll.
     * Null wenn Soll oder Ist fehlen.
     */
    public function delta(ComplianceRequirement $requirement, Tenant $tenant): ?int
    {
        $current = $this->maturity->current($requirement, $tenant);
        $target  = $this->maturity->target($requirement, $tenant);
        if ($current === null || $target === null
            || !isset(self::STAGE_RANK[$current], self::STAGE_RANK[$target])) {
            return null;
        }
        return self::STAGE_RANK[$target] - self::STAGE_RANK[$current];
    }

    /**
     * @return string Eines aus 'gap' (Soll > Ist), 'on_target' (gleich), 'exceeded' (Ist > Soll), 'unset' (eines fehlt).
     */
    public function gapStatus(ComplianceRequirement $requirement, Tenant $tenant): string
    {
        $delta = $this->delta($requirement, $tenant);
        return match (true) {
            $delta === null => 'unset',
            $delta > 0 => 'gap',
            $delta === 0 => 'on_target',
            default => 'exceeded',
        };
    }

    /**
     * Validiert, dass die Stufe ein erlaubter Wert ist.
     */
    private function guardStage(?string $stage): void
    {
        if ($stage === null) {
            return;
        }
        if (!in_array($stage, self::STAGES, true)) {
            throw new \App\Exception\BusinessRule\BusinessRuleException(sprintf(
                'Ungültiger Reifegrad "%s". Erlaubt: %s.',
                $stage,
                implode(', ', self::STAGES),
            ));
        }
    }
}
