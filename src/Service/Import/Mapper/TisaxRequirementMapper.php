<?php

declare(strict_types=1);

namespace App\Service\Import\Mapper;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Maps parsed VdaIsaControlRow DTOs to ComplianceRequirement entities
 * under the global TISAX-VDA-ISA-6 framework.
 *
 * Delta strategy:
 *  - Match by (framework + requirementId + uploadTenant) composite
 *  - Existing row → update title/description/metadata
 *  - No row → create new with requirementSource='tenant_upload'
 */
final class TisaxRequirementMapper
{
    /** Canonical framework code for the TISAX VDA-ISA 6 framework. */
    public const FRAMEWORK_CODE = 'TISAX-VDA-ISA-6';

    /** ISO 27001 anchor mapping prefix stored in dataSourceMapping. */
    private const ISO_KEY = 'iso27001';

    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {}

    /**
     * Find or create the global TISAX-VDA-ISA-6 framework.
     * Idempotent — safe to call on every import run.
     */
    public function findOrCreateFramework(): ComplianceFramework
    {
        $repo = $this->em->getRepository(ComplianceFramework::class);

        $framework = $repo->findOneBy(['code' => self::FRAMEWORK_CODE]);
        if ($framework !== null) {
            return $framework;
        }

        $framework = new ComplianceFramework();
        $framework->setCode(self::FRAMEWORK_CODE);
        $framework->setName('TISAX VDA-ISA 6.0');
        $framework->setVersion('6.0');
        $framework->setDescription(
            'VDA Information Security Assessment (VDA-ISA) — customer-supplied workbook. '
            . 'ENX-licensed content; tenant-specific requirements only.',
        );
        $framework->setRegulatoryBody('VDA / ENX Association');
        $framework->setApplicableIndustry('Automotive');
        $framework->setMandatory(false);
        $framework->setActive(true);
        $framework->setRequiredModules(['prototype_protection']);

        $this->em->persist($framework);
        $this->em->flush();

        return $framework;
    }

    /**
     * Map a list of parsed rows to ComplianceRequirement entities.
     *
     * Returns a delta summary array:
     *   ['created' => int, 'updated' => int, 'skipped' => int, 'entities' => list<ComplianceRequirement>]
     *
     * @param list<VdaIsaControlRow> $rows
     * @return array{created: int, updated: int, skipped: int, entities: list<ComplianceRequirement>}
     */
    public function mapRows(
        array $rows,
        ComplianceFramework $framework,
        Tenant $tenant,
        bool $dryRun = false,
    ): array {
        $repo     = $this->em->getRepository(ComplianceRequirement::class);
        $created  = 0;
        $updated  = 0;
        $skipped  = 0;
        $entities = [];

        foreach ($rows as $row) {
            // Try to find existing row for this tenant
            $existing = $repo->findOneBy([
                'framework'    => $framework,
                'requirementId' => $row->controlId,
                'uploadTenant' => $tenant,
            ]);

            if ($existing !== null) {
                // Update in place
                $this->hydrateEntity($existing, $row, $framework, $tenant);
                $entities[] = $existing;
                $updated++;
            } else {
                // Create new
                $req = new ComplianceRequirement();
                $this->hydrateEntity($req, $row, $framework, $tenant);
                if (!$dryRun) {
                    $this->em->persist($req);
                }
                $entities[] = $req;
                $created++;
            }
        }

        if (!$dryRun && ($created > 0 || $updated > 0)) {
            $this->em->flush();
        }

        return compact('created', 'updated', 'skipped', 'entities');
    }

    /**
     * Compute a preview delta without writing to the DB.
     *
     * @param list<VdaIsaControlRow> $rows
     * @return array{new: int, existing: int, total: int}
     */
    public function computeDelta(
        array $rows,
        ComplianceFramework $framework,
        Tenant $tenant,
    ): array {
        $repo     = $this->em->getRepository(ComplianceRequirement::class);
        $existing = 0;

        foreach ($rows as $row) {
            if ($repo->findOneBy([
                'framework'    => $framework,
                'requirementId' => $row->controlId,
                'uploadTenant' => $tenant,
            ]) !== null) {
                $existing++;
            }
        }

        $total = count($rows);
        return [
            'new'      => $total - $existing,
            'existing' => $existing,
            'total'    => $total,
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ──────────────────────────────────────────────────────────────────────────

    private function hydrateEntity(
        ComplianceRequirement $req,
        VdaIsaControlRow $row,
        ComplianceFramework $framework,
        Tenant $tenant,
    ): void {
        $req->setFramework($framework);
        $req->setRequirementId($row->controlId);
        $req->setTitle(mb_substr($row->title, 0, 255));
        $req->setDescription($row->description ?? $row->title);
        $req->setPriority($this->derivePriority($row));
        $req->setCategory($row->getTier());
        $req->setRequirementType('core');
        $req->setRequirementSource('tenant_upload');
        $req->setUploadTenant($tenant);
        $req->setUpdatedAt(new DateTimeImmutable());

        // Persist ISO 27001 anchors + evidence hints in dataSourceMapping JSON
        $mapping = $req->getDataSourceMapping() ?? [];
        if ($row->iso27001Ref !== null) {
            $mapping[self::ISO_KEY] = $row->iso27001Ref;
        }
        if ($row->auditEvidenceHint !== null) {
            $mapping['auditEvidence'] = $row->auditEvidenceHint;
        }
        if ($row->titleEn !== null && $row->titleEn !== $row->title) {
            $mapping['titleEn'] = $row->titleEn;
        }
        // Store TISAX maturity targets in dataSourceMapping
        foreach (['mustLevel' => 'must', 'shouldLevel' => 'should', 'highLevel' => 'high', 'veryHighLevel' => 'veryHigh'] as $prop => $key) {
            if ($row->$prop !== null) {
                $mapping["tisax_{$key}"] = $row->$prop;
            }
        }
        $req->setDataSourceMapping($mapping ?: null);
    }

    /**
     * Derive a ComplianceRequirement priority from VDA-ISA maturity levels.
     */
    private function derivePriority(VdaIsaControlRow $row): string
    {
        if ($row->veryHighLevel !== null && $row->veryHighLevel !== '') {
            return 'critical';
        }
        if ($row->highLevel !== null && $row->highLevel !== '') {
            return 'high';
        }
        if ($row->mustLevel !== null && $row->mustLevel !== '') {
            return 'medium';
        }
        return 'low';
    }
}
