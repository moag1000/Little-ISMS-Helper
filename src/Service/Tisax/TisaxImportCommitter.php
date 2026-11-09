<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\GlobalCatalogueVoter;
use App\Service\AuditLogger;
use App\Service\Import\Mapper\TisaxRequirementMapper;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * The commit step of the TISAX BYO import wizard (W9): writes the organisation's
 * own rows, never the shared framework row on a GET.
 *
 * The framework row is shared by every organisation: a missing row is created
 * only by whoever may write the global catalogue ({@see GlobalCatalogueVoter}),
 * a row the super admin switched off is not switched back on by an import.
 */
final class TisaxImportCommitter
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TisaxRequirementMapper $mapper,
        private readonly TisaxImportSupportService $importSupport,
        private readonly TisaxEvidenceLinker $evidenceLinker,
        private readonly TisaxFulfillmentSync $fulfillmentSync,
        private readonly AuditLogger $auditLogger,
        private readonly AuthorizationCheckerInterface $authorization,
    ) {
    }

    /**
     * Translation key (domain tisax_isa) of why the import cannot be committed
     * into the shared framework row, or null when it can.
     */
    public function catalogueNote(?ComplianceFramework $framework): ?string
    {
        if ($framework === null) {
            return $this->authorization->isGranted(GlobalCatalogueVoter::WRITE) ? null : 'tisax.import.commit.catalogue_missing';
        }

        return $framework->isActive() === false ? 'tisax.import.commit.catalogue_inactive' : null;
    }

    /**
     * Map the rows, apply the ticked Reifegrad overwrites, link evidence, sync
     * the fulfilment and write one audit entry. Call only when
     * {@see catalogueNote()} answered null.
     *
     * @param list<VdaIsaControlRow> $controls
     * @param list<string>           $applyIds
     */
    public function commit(array $controls, ?ComplianceFramework $framework, string $version, Tenant $tenant, User $user, array $applyIds): ComplianceFramework
    {
        $framework ??= $this->mapper->findOrCreateFramework($version);
        $result = $this->mapper->mapRows($controls, $framework, $tenant);

        // Selective Reifegrad overwrite: only the diff rows the user ticked.
        $overwritten = $this->importSupport->applyMaturityOverwrites($applyIds, $controls, $framework, $tenant, $user);

        // Evidence linkage: "Referenz Dokumentation" citations → Document evidence
        // (exact/normalised filename only); unmatched ones land in the
        // dataSourceMapping.unlinked_citations review list. Then materialise the
        // fulfilment so coverage / SoA / inheritance reflect the assessment.
        $evidence = $this->evidenceLinker->linkBatch($result['entities'], $tenant);
        $this->em->flush();
        $this->fulfillmentSync->sync($framework, $tenant);

        $this->auditLogger->logImport(
            'ComplianceRequirement',
            $result['created'] + $result['updated'],
            sprintf(
                'TISAX BYO import: %d created, %d updated, %d Reifegrad overwritten, '
                . '%d evidence linked, %d citations unlinked — framework %s',
                $result['created'],
                $result['updated'],
                $overwritten,
                $evidence['linked'],
                $evidence['unlinked'],
                $framework->getCode(),
            ),
        );

        return $framework;
    }
}
