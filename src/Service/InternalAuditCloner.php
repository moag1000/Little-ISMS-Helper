<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\InternalAudit;
use App\Entity\Tenant;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * InternalAudit Cloner (Sprint 3 / C1).
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

        if (method_exists($source, 'getScope')) {
            $scope = $source->getScope();
            if ($scope !== null && method_exists($clone, 'setScope')) {
                $clone->setScope($scope);
            }
        }
        if (method_exists($source, 'getScopeType') && method_exists($clone, 'setScopeType')) {
            $scopeType = $source->getScopeType();
            if ($scopeType !== null) {
                $clone->setScopeType($scopeType);
            }
        }
        if (method_exists($source, 'getObjectives') && method_exists($clone, 'setObjectives')) {
            $clone->setObjectives($source->getObjectives());
        }
        if (method_exists($source, 'getAuditType') && method_exists($clone, 'setAuditType')) {
            $clone->setAuditType((string) $source->getAuditType());
        }
        if (method_exists($source, 'getFramework') && method_exists($clone, 'setFramework')) {
            $fw = $source->getFramework();
            if ($fw !== null) {
                $clone->setFramework($fw);
            }
        }
        if (method_exists($source, 'getStandard') && method_exists($clone, 'setStandard')) {
            $std = $source->getStandard();
            if ($std !== null) {
                $clone->setStandard($std);
            }
        }

        // Primary compliance framework relation — method name is
        // `setScopedFramework` in the current entity.
        $primaryFramework = $source->getComplianceFramework();
        if ($primaryFramework instanceof ComplianceFramework
            && method_exists($clone, 'setScopedFramework')
        ) {
            $clone->setScopedFramework($primaryFramework);
        }

        // B4 multi-framework scope — copy the additional-framework set.
        foreach ($source->getAdditionalScopedFrameworks() as $fw) {
            if ($fw instanceof ComplianceFramework) {
                $clone->addAdditionalScopedFramework($fw);
            }
        }

        if (method_exists($source, 'getLeadAuditor') && method_exists($clone, 'setLeadAuditor')) {
            $clone->setLeadAuditor((string) $source->getLeadAuditor());
        }
        if (method_exists($source, 'getAuditTeam') && method_exists($clone, 'setAuditTeam')) {
            $team = $source->getAuditTeam();
            if ($team !== null) {
                $clone->setAuditTeam($team);
            }
        }

        $clone->setPlannedDate($plannedDate ?? $source->getPlannedDate() ?? new DateTimeImmutable());
        if (method_exists($clone, 'setStatus')) {
            $clone->setStatus('planned');
        }

        $this->entityManager->persist($clone);
        return $clone;
    }
}
