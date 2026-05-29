<?php

declare(strict_types=1);

namespace App\Service\Tisax;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\AuditLogger;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Support logic for the TISAX BYO import wizard's commit + validate steps.
 *
 * Extracted from TisaxImportWizardController to keep the controller within the
 * god-class budget and to make the session-state (de)serialisation, the
 * organisation-mismatch heuristic, and the selective Reifegrad-overwrite path
 * independently unit-testable.
 */
final class TisaxImportSupportService
{
    /** Legal-form suffixes stripped before comparing organisation names. */
    private const LEGAL_FORMS = 'gmbh|mbh|ag|kg|se|ohg|gbr|e\.?\s?v|co|kgaa|ug|ltd|inc|llc|sa|nv|bv';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly TisaxMaturityAssessmentService $maturityService,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Serialise parsed control DTOs into a plain array for session storage.
     *
     * @param list<VdaIsaControlRow> $controls
     * @return list<array<string, mixed>>
     */
    public function serialiseControls(array $controls): array
    {
        return array_map(
            static fn (VdaIsaControlRow $ctrl): array => [
                'controlId'         => $ctrl->controlId,
                'title'             => $ctrl->title,
                'titleEn'           => $ctrl->titleEn,
                'description'       => $ctrl->description,
                'mustLevel'         => $ctrl->mustLevel,
                'shouldLevel'       => $ctrl->shouldLevel,
                'highLevel'         => $ctrl->highLevel,
                'veryHighLevel'     => $ctrl->veryHighLevel,
                'iso27001Ref'       => $ctrl->iso27001Ref,
                'auditEvidenceHint' => $ctrl->auditEvidenceHint,
                'rawRowIndex'       => $ctrl->rawRowIndex,
                'maturityCurrent'   => $ctrl->maturityCurrent,
            ],
            $controls,
        );
    }

    /**
     * Rebuild control DTOs from their session representation.
     *
     * @param mixed $serialised  raw value pulled from the session
     * @return list<VdaIsaControlRow>|null
     */
    public function deserialiseControls(mixed $serialised): ?array
    {
        if ($serialised === null || !is_array($serialised)) {
            return null;
        }

        return array_map(
            static fn (array $d): VdaIsaControlRow => new VdaIsaControlRow(
                controlId: $d['controlId'],
                title: $d['title'],
                titleEn: $d['titleEn'] ?? null,
                description: $d['description'] ?? null,
                mustLevel: $d['mustLevel'] ?? null,
                shouldLevel: $d['shouldLevel'] ?? null,
                highLevel: $d['highLevel'] ?? null,
                veryHighLevel: $d['veryHighLevel'] ?? null,
                iso27001Ref: $d['iso27001Ref'] ?? null,
                auditEvidenceHint: $d['auditEvidenceHint'] ?? null,
                rawRowIndex: $d['rawRowIndex'] ?? 0,
                maturityCurrent: $d['maturityCurrent'] ?? null,
            ),
            $serialised,
        );
    }

    /**
     * Decide whether the workbook organisation name differs from the tenant.
     * Normalises away legal-form suffixes and punctuation, then treats a
     * substring match in either direction as "same organisation". Returns
     * false when no workbook company name is available (nothing to warn about).
     */
    public function isOrganisationMismatch(?string $workbookCompany, ?Tenant $tenant): bool
    {
        if ($workbookCompany === null || trim($workbookCompany) === '' || $tenant === null) {
            return false;
        }

        $wb  = $this->normaliseOrgName($workbookCompany);
        $org = $this->normaliseOrgName((string) $tenant->getName());

        if ($wb === '' || $org === '') {
            return false;
        }

        return !str_contains($wb, $org) && !str_contains($org, $wb);
    }

    /**
     * Overwrite the stored Reifegrad with the workbook value for the explicitly
     * selected control IDs. Resolves each control ID to its requirement PK and
     * delegates to the tenant-scoped, validating bulk setter.
     *
     * @param list<string>           $applyControlIds
     * @param list<VdaIsaControlRow> $controls
     * @return int  number of requirements whose Reifegrad was overwritten
     */
    public function applyMaturityOverwrites(
        array $applyControlIds,
        array $controls,
        ComplianceFramework $framework,
        Tenant $tenant,
        User $user,
    ): int {
        $applyControlIds = array_flip(array_filter($applyControlIds, static fn (string $v): bool => $v !== ''));
        if ($applyControlIds === []) {
            return 0;
        }

        $repo     = $this->em->getRepository(ComplianceRequirement::class);
        $levelMap = [];

        foreach ($controls as $ctrl) {
            if (!isset($applyControlIds[$ctrl->controlId]) || $ctrl->maturityCurrent === null) {
                continue;
            }
            $req = $repo->findOneBy([
                'framework'     => $framework,
                'requirementId' => $ctrl->controlId,
                'uploadTenant'  => $tenant,
            ]);
            if ($req !== null && $req->getId() !== null) {
                $levelMap[$req->getId()] = (int) $ctrl->maturityCurrent;
            }
        }

        if ($levelMap === []) {
            return 0;
        }

        $count = $this->maturityService->bulkSetReifegrad($levelMap, $user, $tenant);

        $this->auditLogger->logImport(
            'ComplianceRequirement',
            $count,
            sprintf('TISAX BYO import: %d Reifegrad values overwritten from workbook on user confirmation', $count),
        );

        return $count;
    }

    /**
     * Lowercase, strip common legal-form suffixes and non-alphanumerics so that
     * "CANCOM GmbH" and "Cancom" compare equal.
     */
    private function normaliseOrgName(string $name): string
    {
        $name = mb_strtolower($name);
        $name = preg_replace('/\b(' . self::LEGAL_FORMS . ')\b/u', ' ', $name) ?? $name;
        $name = preg_replace('/[^a-z0-9]+/u', '', $name) ?? $name;

        return trim($name);
    }
}
