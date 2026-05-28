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
     * Maps controlId → Nis2ComplianceService dashboard letter key (Option A: narrower fix).
     *
     * Nis2ComplianceService uses a legacy 11-key grid (21.2.a..21.2.k) whose internal
     * method-to-letter assignments do NOT match the directive's Art. 21(2)(a)-(j) letters.
     * The mapping below selects the closest semantic match per service method for each
     * directive measure. A full alignment of Nis2ComplianceService keys to directive
     * letters (dropping 21.2.k) is tracked separately as a follow-up refactor.
     *
     * NIS2-ART21-B (Incident Handling) and NIS2-ART21-F (Effectiveness Assessment) have
     * no adequate proxy in the current service; they are mapped to null via absence from
     * this array, and 'metric' will be null in the rollup.
     *
     * @var array<string, string>
     */
    private const LETTER_KEY_MAP = [
        'NIS2-ART21-A' => '21.2.a', // Risk management policies → riskManagementPolicies()
        // NIS2-ART21-B (incident handling) — no service proxy available; metric = null
        'NIS2-ART21-C' => '21.2.j', // Business continuity → businessContinuity()
        'NIS2-ART21-D' => '21.2.f', // Supply chain security → supplyChainSecurity()
        'NIS2-ART21-E' => '21.2.e', // Secure SDLC + vuln handling → secureSdlc() + vulnerabilityManagement() (best proxy: secureSdlc)
        // NIS2-ART21-F (effectiveness assessment) — no dedicated service proxy; metric = null
        'NIS2-ART21-G' => '21.2.g', // Cyber hygiene + training → hrSecurity() (training completion proxy)
        'NIS2-ART21-H' => '21.2.k', // Cryptography → cryptographicControls()
        'NIS2-ART21-I' => '21.2.h', // HR security + access control + asset mgmt → accessControl() (best proxy)
        'NIS2-ART21-J' => '21.2.b', // MFA + secure comms → authentication() (MFA adoption proxy)
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
            $dbReqs = $this->requirementRepository->findByFramework($nis2Framework);
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
