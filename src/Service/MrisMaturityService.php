<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceRequirement;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use DomainException;

/**
 * Steuert das Reifegrad-Tracking für MRIS-MHC-Requirements
 * (Initial/Defined/Managed) gem. Peddi (2026), MRIS v1.5 Kap. 9.5.
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
    ) {
    }

    /**
     * Setzt den Ist-Stand. Loggt Vorher/Nachher im Audit-Trail (logUpdate).
     */
    public function setCurrent(ComplianceRequirement $requirement, ?string $stage): void
    {
        $this->guardStage($stage);
        $previous = $requirement->getMaturityCurrent();
        if ($previous === $stage) {
            return;
        }
        $requirement->setMaturityCurrent($stage);
        $requirement->setMaturityReviewedAt(new DateTimeImmutable());
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
     * Setzt den Soll-Stand (kein Audit-Eintrag, da deklarativ).
     */
    public function setTarget(ComplianceRequirement $requirement, ?string $stage): void
    {
        $this->guardStage($stage);
        $requirement->setMaturityTarget($stage);
        $this->entityManager->flush();
    }

    /**
     * Liefert das Soll-Ist-Delta in Stufen.
     * Positiv: Soll > Ist (Lücke). Null: Soll erfüllt. Negativ: Ist > Soll.
     * Null wenn Soll oder Ist fehlen.
     */
    public function delta(ComplianceRequirement $requirement): ?int
    {
        $current = $requirement->getMaturityCurrent();
        $target  = $requirement->getMaturityTarget();
        if ($current === null || $target === null) {
            return null;
        }
        return self::STAGE_RANK[$target] - self::STAGE_RANK[$current];
    }

    /**
     * @return string Eines aus 'gap' (Soll > Ist), 'on_target' (gleich), 'exceeded' (Ist > Soll), 'unset' (eines fehlt).
     */
    public function gapStatus(ComplianceRequirement $requirement): string
    {
        $delta = $this->delta($requirement);
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
            throw new DomainException(sprintf(
                'Ungültiger Reifegrad "%s". Erlaubt: %s.',
                $stage,
                implode(', ', self::STAGES),
            ));
        }
    }
}
