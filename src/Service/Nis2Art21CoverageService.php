<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * NIS2 Art. 21(2) Coverage Service
 *
 * Provides the 10 canonical Art. 21(2)(a)-(j) requirements with live
 * compliance metrics merged from Nis2ComplianceService. Intended for the
 * requirements catalogue view (route app_nis2_art21_requirements).
 *
 * Kept as a separate service (not merged into Nis2ComplianceService) to avoid
 * bloating the existing final-class with additional optional dependencies.
 */
class Nis2Art21CoverageService
{
    /**
     * Maps controlId → Nis2ComplianceService dashboard letter key.
     *
     * After the Option B refactor (fix/nis2-legacy-grid-directive-alignment),
     * Nis2ComplianceService now uses directive-correct 10-letter keys (21.2.a..21.2.j).
     * Each NIS2-ART21-X descriptor maps 1:1 to its directive-correct letter key.
     *
     * @var array<string, string>
     */
    private const LETTER_KEY_MAP = [
        'NIS2-ART21-A' => '21.2.a', // (a) risk analysis & InfoSys security → riskManagementPolicies()
        'NIS2-ART21-B' => '21.2.b', // (b) incident handling → incidentHandling()
        'NIS2-ART21-C' => '21.2.c', // (c) BCM / backup / crisis mgmt → businessContinuity()
        'NIS2-ART21-D' => '21.2.d', // (d) supply chain security → supplyChainSecurity()
        'NIS2-ART21-E' => '21.2.e', // (e) secure development & acquisition → secureSdlc()
        'NIS2-ART21-F' => '21.2.f', // (f) effectiveness assessment → effectivenessAssessment()
        'NIS2-ART21-G' => '21.2.g', // (g) cyber hygiene + training → cyberHygieneAndTraining()
        'NIS2-ART21-H' => '21.2.h', // (h) cryptography & encryption → cryptographicControls()
        'NIS2-ART21-I' => '21.2.i', // (i) HR security + access ctrl + asset mgmt → accessControlAndAssetMgmt()
        'NIS2-ART21-J' => '21.2.j', // (j) MFA + secured communications → authentication()
    ];

    /** @var array<int, array<string, string>> Static descriptor list — mirrors YAML fixture order (CELEX:32022L2555 Art. 21(2)(a)-(j)) */
    private const DESCRIPTORS = [
        ['controlId' => 'NIS2-ART21-A', 'clauseReference' => 'Art. 21(2)(a)', 'title' => 'Risikoanalyse und Sicherheit der Informationssysteme', 'category' => 'Risikomanagement', 'priority' => 'critical'],
        ['controlId' => 'NIS2-ART21-B', 'clauseReference' => 'Art. 21(2)(b)', 'title' => 'Behandlung von Sicherheitsvorfaellen', 'category' => 'Incident Management', 'priority' => 'critical'],
        ['controlId' => 'NIS2-ART21-C', 'clauseReference' => 'Art. 21(2)(c)', 'title' => 'Aufrechterhaltung des Betriebs (BCM, Backup, Krisenmanagement)', 'category' => 'Business Continuity', 'priority' => 'critical'],
        ['controlId' => 'NIS2-ART21-D', 'clauseReference' => 'Art. 21(2)(d)', 'title' => 'Sicherheit der Lieferkette', 'category' => 'Lieferkettensicherheit', 'priority' => 'high'],
        ['controlId' => 'NIS2-ART21-E', 'clauseReference' => 'Art. 21(2)(e)', 'title' => 'Sicherheit in Beschaffung, Entwicklung und Wartung inkl. Schwachstellenmanagement', 'category' => 'Secure Development', 'priority' => 'high'],
        ['controlId' => 'NIS2-ART21-F', 'clauseReference' => 'Art. 21(2)(f)', 'title' => 'Wirksamkeitsbewertung der Cybersicherheits-Massnahmen', 'category' => 'Wirksamkeitsbewertung', 'priority' => 'high'],
        ['controlId' => 'NIS2-ART21-G', 'clauseReference' => 'Art. 21(2)(g)', 'title' => 'Cyber-Hygiene und Cybersicherheitsschulungen', 'category' => 'Training und Awareness', 'priority' => 'high'],
        ['controlId' => 'NIS2-ART21-H', 'clauseReference' => 'Art. 21(2)(h)', 'title' => 'Kryptografie und Verschluesselung', 'category' => 'Kryptografie', 'priority' => 'high'],
        ['controlId' => 'NIS2-ART21-I', 'clauseReference' => 'Art. 21(2)(i)', 'title' => 'Personalsicherheit, Zugriffskontrolle und Asset-Management', 'category' => 'Personalsicherheit / Zugriffskontrolle', 'priority' => 'critical'],
        ['controlId' => 'NIS2-ART21-J', 'clauseReference' => 'Art. 21(2)(j)', 'title' => 'MFA, sichere Kommunikation und Notfallkommunikation', 'category' => 'Authentifizierung / Sichere Kommunikation', 'priority' => 'critical'],
    ];

    public function __construct(
        private readonly Nis2ComplianceService $nis2ComplianceService,
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Art. 21(2) Coverage Rollup — returns the 10 canonical requirements with their
     * live metric from Nis2ComplianceService merged in for fulfilment context.
     *
     * Each entry:
     *   [
     *     'controlId'       => 'NIS2-ART21-A',
     *     'clauseReference' => 'Art. 21(2)(a)',
     *     'title'           => string,
     *     'category'        => string,
     *     'priority'        => 'critical'|'high'|'medium'|'low',
     *     'metric'          => array (getDashboardPayload letter shape) | null,
     *     'requirement'     => ComplianceRequirement | null,
     *   ]
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCoverageRollup(?Tenant $tenant = null): array
    {
        $payload = $this->nis2ComplianceService->getDashboardPayload($tenant);
        $letterMap = $payload['letters'] ?? [];

        $dbRequirements = [];
        $nis2Framework = $this->frameworkRepository->findOneBy(['code' => 'NIS2']);
        if ($nis2Framework !== null) {
            // Top-level only — the rollup maps the 10 canonical Art. 21(2)
            // requirements; imported sub-requirements never match a descriptor key.
            $dbReqs = $this->requirementRepository->findTopLevelByFramework($nis2Framework);
            foreach ($dbReqs as $req) {
                $dbRequirements[$req->getRequirementId()] = $req;
            }
        }

        $result = [];
        foreach (self::DESCRIPTORS as $descriptor) {
            $letterKey = self::LETTER_KEY_MAP[$descriptor['controlId']] ?? null;
            $result[] = [
                'controlId'       => $descriptor['controlId'],
                'clauseReference' => $descriptor['clauseReference'],
                'title'           => $descriptor['title'],
                'category'        => $descriptor['category'],
                'priority'        => $descriptor['priority'],
                'metric'          => ($letterKey !== null && isset($letterMap[$letterKey])) ? $letterMap[$letterKey] : null,
                'requirement'     => $dbRequirements[$descriptor['controlId']] ?? null,
            ];
        }

        return $result;
    }
}
