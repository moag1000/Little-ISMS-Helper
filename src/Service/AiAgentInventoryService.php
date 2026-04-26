<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Asset;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Tenant;
use App\Repository\AssetRepository;
use Doctrine\ORM\EntityManagerInterface;
use Throwable;

/**
 * AI-Agent-Inventar-Service.
 *
 * Kapselt Lese-Zugriffe auf die ai_agent-Subtypen der Asset-Entität und
 * liefert Compliance-Sichten für EU AI Act, ISO 42001 und MRIS MHC-13.
 *
 * Frameworks (jeweils erfüllt durch dieselbe Inventar-Datenbasis):
 * - EU AI Act (Verordnung 2024/1689) Art. 6/9/10/11/14/16
 * - ISO/IEC 42001 (AIMS) Annex A
 * - MRIS v1.5 MHC-13 (Peddi 2026, CC BY 4.0)
 * - ISO/IEC 27001:2022 A.5.16, A.8.27
 */
class AiAgentInventoryService
{
    public const RISK_CLASSIFICATIONS = [
        'prohibited',     // EU AI Act Art. 5
        'high_risk',      // EU AI Act Art. 6
        'limited_risk',   // EU AI Act Art. 50 (Transparenzpflicht)
        'minimal_risk',   // EU AI Act default
    ];

    /** @var array<string, array{art: string, label: string}> */
    private const COMPLIANCE_FIELDS = [
        'aiAgentClassification'      => ['art' => 'EU AI Act Art. 6',  'label' => 'Risikoklassifikation'],
        'aiAgentPurpose'             => ['art' => 'EU AI Act Art. 11', 'label' => 'Zweckbestimmung / Doku'],
        'aiAgentDataSources'         => ['art' => 'EU AI Act Art. 10', 'label' => 'Datengovernance'],
        'aiAgentOversightMechanism'  => ['art' => 'EU AI Act Art. 14', 'label' => 'Human Oversight'],
        'aiAgentProvider'            => ['art' => 'EU AI Act Art. 16', 'label' => 'Anbieter'],
        'aiAgentModelVersion'        => ['art' => 'ISO 42001 Annex A',  'label' => 'Modell-Version'],
        'aiAgentCapabilityScope'     => ['art' => 'MRIS MHC-13',        'label' => 'Capability-Scope'],
        'aiAgentThreatModelDocId'    => ['art' => 'EU AI Act Art. 9 + MHC-13', 'label' => 'Bedrohungsmodell'],
        'aiAgentExtensionAllowlist'  => ['art' => 'MRIS MHC-13',        'label' => 'Extension-Allowlist'],
    ];

    public function __construct(
        private readonly AssetRepository $assetRepository,
        private readonly ?EntityManagerInterface $entityManager = null,
    ) {
    }

    /**
     * Liefert verknüpfte DPIA(s) für ein Asset oder null, falls das DPIA-Modul
     * nicht im Schema verfügbar ist (Soft-Failure).
     *
     * Quelle: Peddi, R. (2026). MRIS v1.5 MHC-13 — AI-Agent-Governance.
     * Lizenz: CC BY 4.0.
     *
     * @return array<int, DataProtectionImpactAssessment>|null  null = Modul fehlt
     */
    public function findLinkedDpias(Asset $asset): ?array
    {
        if ($this->entityManager === null) {
            return null;
        }

        try {
            $repo = $this->entityManager->getRepository(DataProtectionImpactAssessment::class);
            return $repo->findBy(['relatedAsset' => $asset], ['createdAt' => 'DESC']);
        } catch (Throwable) {
            // Soft-Failure: Schema/Modul nicht verfügbar
            return null;
        }
    }

    /**
     * Alle AI-Agent-Assets eines Mandanten.
     *
     * @return array<int, Asset>
     */
    public function findAllForTenant(Tenant $tenant): array
    {
        return $this->assetRepository->findBy([
            'tenant' => $tenant,
            'assetType' => 'ai_agent',
        ]);
    }

    /**
     * Liefert pro AI-Agent eine Compliance-Vollständigkeits-Bewertung über
     * die 9 EU-AI-Act/ISO-42001/MHC-13-Felder. Audit-tauglich für Hochrisiko-
     * Systeme, die laut AI Act Art. 11 vollständig dokumentiert sein müssen.
     *
     * @return array{filled: int, total: int, percentage: float, missing: array<int, string>}
     */
    public function complianceCompleteness(Asset $agent): array
    {
        if (!$agent->isAiAgent()) {
            return ['filled' => 0, 'total' => 0, 'percentage' => 0.0, 'missing' => []];
        }

        $total = count(self::COMPLIANCE_FIELDS);
        $filled = 0;
        $missing = [];

        foreach (self::COMPLIANCE_FIELDS as $field => $meta) {
            $getter = 'get' . ucfirst($field);
            if (!method_exists($agent, $getter)) {
                continue;
            }
            $value = $agent->$getter();
            $isFilled = $value !== null && $value !== '' && $value !== [];
            if ($isFilled) {
                $filled++;
            } else {
                $missing[] = $meta['label'] . ' (' . $meta['art'] . ')';
            }
        }

        $percentage = $total > 0 ? round(($filled / $total) * 100, 1) : 0.0;

        return [
            'filled'     => $filled,
            'total'      => $total,
            'percentage' => $percentage,
            'missing'    => $missing,
        ];
    }

    /**
     * Identifiziert Hochrisiko-Agenten mit unvollständiger Dokumentation —
     * EU AI Act Art. 11 verlangt vollständige technische Doku, EU AI Act Art. 9
     * + DSGVO Art. 35 verlangen für personenbezogene Verarbeitung eine DPIA.
     * Ein Hochrisiko-Agent ohne verknüpfte DPIA gilt daher ebenfalls als
     * "incomplete" (Audit-Sicht).
     *
     * Soft-Failure: Wenn das DPIA-Modul nicht im Schema ist, wird die
     * DPIA-Prüfung übersprungen und nur die Feld-Vollständigkeit bewertet.
     *
     * @return array<int, Asset>
     */
    public function findHighRiskWithIncompleteDocumentation(Tenant $tenant): array
    {
        $highRisk = $this->assetRepository->findBy([
            'tenant' => $tenant,
            'assetType' => 'ai_agent',
            'aiAgentClassification' => 'high_risk',
        ]);

        return array_values(array_filter(
            $highRisk,
            fn(Asset $a): bool => $this->complianceCompleteness($a)['percentage'] < 100.0
                || $this->isMissingDpia($a),
        ));
    }

    /**
     * True, wenn der Agent KEINE verknüpfte DPIA hat. Soft-Failure: bei
     * fehlendem DPIA-Modul (null) wird die Prüfung übersprungen → false
     * (kein Mangel meldbar, weil nicht prüfbar).
     */
    private function isMissingDpia(Asset $agent): bool
    {
        $dpias = $this->findLinkedDpias($agent);
        if ($dpias === null) {
            return false;
        }
        return $dpias === [];
    }

    /**
     * Liefert Aggregat-Statistik über das Inventar pro Risikoklasse.
     *
     * @return array{total: int, by_classification: array<string, int>, unclassified: int, avg_completeness: float}
     */
    public function inventoryStats(Tenant $tenant): array
    {
        $agents = $this->findAllForTenant($tenant);
        $byClass = array_fill_keys(self::RISK_CLASSIFICATIONS, 0);
        $unclassified = 0;
        $totalCompleteness = 0.0;

        foreach ($agents as $agent) {
            $class = $agent->getAiAgentClassification();
            if ($class === null) {
                $unclassified++;
            } elseif (isset($byClass[$class])) {
                $byClass[$class]++;
            }
            $totalCompleteness += $this->complianceCompleteness($agent)['percentage'];
        }

        $count = count($agents);
        return [
            'total'              => $count,
            'by_classification'  => $byClass,
            'unclassified'       => $unclassified,
            'avg_completeness'   => $count > 0 ? round($totalCompleteness / $count, 1) : 0.0,
        ];
    }
}
