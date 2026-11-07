<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use App\Enum\InternalAuditStatus;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * InternalAudit Cloner.
 *
 * Fokussiert die Consultant-Anforderung *"nimm meinen 27001-Auditplan
 * aus Kunde A und wende ihn auf Kunde B an"* als generische
 * Klon-Funktion auf `InternalAudit`:
 *
 *   - Kopiert Titel, Scope, Objectives, Status, Audit-Typ, Standard
 *     und Framework-Referenzen (primary + additional).
 *   - Plant den neuen Audit ab einem optionalen `$plannedDate`. Ohne
 *     Angabe wird der Ursprungstermin übernommen.
 *   - Setzt `status = 'planned'` und löscht `actualDate`/`reportedAt`,
 *     damit der Klon operativ neu startet.
 *   - Bleibt bewusst *shallow*: Findings, Audit-Berichte, Evidence-
 *     Dateien werden nicht kopiert — das würde die Audit-Historie
 *     verfälschen. Wer einen kompletten Audit-Rerun will, legt
 *     anschließend Findings manuell an.
 *
 * Keine Persistence — der Caller entscheidet, ob und wann geflusht
 * wird. So kann der Command einen Dry-Run-Modus bauen ohne Tricks.
 */
final class InternalAuditCloner
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param string|null $titleOverride Optional neuer Titel, sonst
     *                                   "<Original-Titel> (Kopie)".
     */
    public function clone(
        InternalAudit $source,
        ?Tenant $targetTenant = null,
        ?DateTimeInterface $plannedDate = null,
        ?string $titleOverride = null,
    ): InternalAudit {
        $clone = new InternalAudit();

        $baseTitle = (string) $source->getTitle();
        $clone->setTitle($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseTitle !== '' ? $baseTitle . ' (Kopie)' : 'Kopie')
        );

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $scope = $source->getScope();
        if ($scope !== null) {
            $clone->setScope($scope);
        }
        $scopeType = $source->getScopeType();
        if ($scopeType !== null) {
            $clone->setScopeType($scopeType);
        }
        $clone->setObjectives($source->getObjectives());

        // Primary compliance framework relation — method name is
        // `setScopedFramework` in the current entity.
        $primaryFramework = $source->getScopedFramework();
        if ($primaryFramework instanceof ComplianceFramework) {
            $clone->setScopedFramework($primaryFramework);
        }

        // Multi-framework scope — copy the additional-framework set.
        foreach ($source->getAdditionalScopedFrameworks() as $fw) {
            if ($fw instanceof ComplianceFramework) {
                $clone->addAdditionalScopedFramework($fw);
            }
        }

        $clone->setLeadAuditor((string) $source->getLeadAuditor());
        $team = $source->getAuditTeam();
        if ($team !== null) {
            $clone->setAuditTeam($team);
        }

        $clone->setPlannedDate($plannedDate ?? $source->getPlannedDate() ?? new DateTimeImmutable());
        $clone->setStatus(InternalAuditStatus::Planned); // @phpstan-ignore lifecycle.directSetStatus (initial state on pre-persist InternalAudit clone; 'planned' is the internal_audit_lifecycle initial_marking)

        $this->entityManager->persist($clone);
        return $clone;
    }
}
