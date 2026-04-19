<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;

/**
 * BSI IT-Grundschutz-Check (Soll-Ist-Vergleich).
 *
 * Groups BSI IT-Grundschutz ComplianceRequirements by Baustein
 * (e.g. "SYS.1.2") and returns per-Baustein Soll/Ist metrics with
 * MUSS/SOLLTE/KANN weighting and Absicherungsstufen-filter
 * (basis | standard | kern).
 *
 * MUSS/SOLLTE/KANN classification precedence:
 *   1. ComplianceRequirement.anforderungsTyp  (authoritative if set)
 *   2. Description text heuristic ("MUSS"/"MÜSSEN"/"DÜRFEN NUR" → muss,
 *      "SOLLTE"/"SOLLTEN" → sollte, "KANN"/"KÖNNEN" → kann)
 *   3. Fallback: critical/high priority → muss, medium → sollte, low → kann
 *
 * Weights for the weighted compliance score:
 *   MUSS = 3, SOLLTE = 2, KANN = 1.
 *
 * "Fulfilled" = fulfillment percentage ≥ 80 (configurable via constant).
 * Fulfillment itself comes from `ComplianceRequirement::calculateFulfillmentFromControls()`,
 * which walks the mappedControls and averages their implementation %.
 */
class BsiGrundschutzCheckService
{
    public const FULFILLED_THRESHOLD = 80;

    public const WEIGHT_MUSS = 3;
    public const WEIGHT_SOLLTE = 2;
    public const WEIGHT_KANN = 1;

    private const LAYERS = [
        'ISMS' => 'Sicherheitsmanagement',
        'ORP'  => 'Organisation und Personal',
        'CON'  => 'Konzepte und Vorgehensweisen',
        'OPS'  => 'Betrieb',
        'APP'  => 'Anwendungen',
        'SYS'  => 'IT-Systeme',
        'IND'  => 'Industrielle IT',
        'NET'  => 'Netze und Kommunikation',
        'INF'  => 'Infrastruktur',
        'DER'  => 'Detektion und Reaktion',
    ];

    public function __construct(
        private readonly ComplianceFrameworkRepository $frameworkRepository,
        private readonly ComplianceRequirementRepository $requirementRepository,
    ) {
    }

    /**
     * Full IT-Grundschutz-Check report.
     *
     * @param string|null $absicherungsStufe 'basis' | 'standard' | 'kern' or null for all
     * @return array{
     *     framework: array{code: string, name: string, version: string|null},
     *     filter: array{absicherungsStufe: string|null},
     *     overall: array,
     *     by_layer: array<string, array>,
     *     bausteine: array<string, array>,
     * }
     */
    public function getCheckReport(?string $absicherungsStufe = null): array
    {
        $framework = $this->frameworkRepository->findOneBy(['code' => 'BSI_GRUNDSCHUTZ']);

        if (!$framework instanceof ComplianceFramework) {
            return [
                'framework' => ['code' => 'BSI_GRUNDSCHUTZ', 'name' => 'BSI IT-Grundschutz', 'version' => null],
                'filter'    => ['absicherungsStufe' => $absicherungsStufe],
                'overall'   => $this->emptyAggregate(),
                'by_layer'  => [],
                'bausteine' => [],
            ];
        }

        $requirements = $this->requirementRepository->findByFramework($framework);

        if ($absicherungsStufe !== null) {
            $requirements = array_values(array_filter(
                $requirements,
                static fn (ComplianceRequirement $r): bool => $r->getAbsicherungsStufe() === $absicherungsStufe
            ));
        }

        $bausteine = [];
        $byLayer = [];
        $overallAgg = $this->newAggregate();

        foreach ($requirements as $req) {
            $reqId = $req->getRequirementId();
            if ($reqId === null) {
                continue;
            }
            $bausteinCode = $this->bausteinCode($reqId);
            $layer = $this->layerOf($bausteinCode);
            $type = $this->classifyType($req);
            $fulfillmentPct = $req->calculateFulfillmentFromControls();
            $fulfilled = $fulfillmentPct >= self::FULFILLED_THRESHOLD;

            if (!isset($bausteine[$bausteinCode])) {
                $bausteine[$bausteinCode] = [
                    'code'         => $bausteinCode,
                    'layer'        => $layer,
                    'name'         => $this->bausteinName($req),
                    'aggregate'    => $this->newAggregate(),
                    'requirements' => [],
                ];
            }

            $bausteine[$bausteinCode]['requirements'][] = [
                'id'                  => $reqId,
                'title'               => $req->getTitle(),
                'type'                => $type,
                'absicherungs_stufe'  => $req->getAbsicherungsStufe(),
                'priority'            => $req->getPriority(),
                'fulfillment_pct'     => $fulfillmentPct,
                'fulfilled'           => $fulfilled,
            ];

            $this->updateAggregate($bausteine[$bausteinCode]['aggregate'], $type, $fulfillmentPct, $fulfilled);
            $this->updateAggregate($overallAgg, $type, $fulfillmentPct, $fulfilled);

            if (!isset($byLayer[$layer])) {
                $byLayer[$layer] = [
                    'layer'     => $layer,
                    'name'      => self::LAYERS[$layer] ?? $layer,
                    'aggregate' => $this->newAggregate(),
                ];
            }
            $this->updateAggregate($byLayer[$layer]['aggregate'], $type, $fulfillmentPct, $fulfilled);
        }

        foreach ($bausteine as &$baustein) {
            $baustein['aggregate'] = $this->finalizeAggregate($baustein['aggregate']);
            usort(
                $baustein['requirements'],
                static fn (array $a, array $b): int => strcmp($a['id'], $b['id'])
            );
        }
        unset($baustein);

        ksort($bausteine);

        foreach ($byLayer as &$layerBucket) {
            $layerBucket['aggregate'] = $this->finalizeAggregate($layerBucket['aggregate']);
        }
        unset($layerBucket);

        ksort($byLayer);

        return [
            'framework' => [
                'code'    => $framework->getCode(),
                'name'    => $framework->getName(),
                'version' => $framework->getVersion(),
            ],
            'filter'    => ['absicherungsStufe' => $absicherungsStufe],
            'overall'   => $this->finalizeAggregate($overallAgg),
            'by_layer'  => $byLayer,
            'bausteine' => $bausteine,
        ];
    }

    private function bausteinCode(string $requirementId): string
    {
        $parts = explode('.', $requirementId);
        $collected = [];
        foreach ($parts as $part) {
            if (preg_match('/^A\d+$/', $part) === 1) {
                break;
            }
            $collected[] = $part;
        }
        return implode('.', $collected);
    }

    private function layerOf(string $bausteinCode): string
    {
        $first = strstr($bausteinCode, '.', true);
        $layer = $first === false ? $bausteinCode : $first;
        return array_key_exists($layer, self::LAYERS) ? $layer : 'UNKNOWN';
    }

    /**
     * Best-effort human-readable Baustein name from the requirement's
     * `category` field (typical form: "SYS.1.2 Windows Server").
     */
    private function bausteinName(ComplianceRequirement $req): string
    {
        $cat = (string) $req->getCategory();
        if ($cat === '') {
            return (string) $req->getRequirementId();
        }
        $parts = explode(' ', $cat, 2);
        return isset($parts[1]) ? trim($parts[1]) : $cat;
    }

    /**
     * Classify a requirement as muss | sollte | kann.
     */
    private function classifyType(ComplianceRequirement $req): string
    {
        $explicit = $req->getAnforderungsTyp();
        if ($explicit !== null && $explicit !== '') {
            $norm = strtolower($explicit);
            if (in_array($norm, ['muss', 'sollte', 'kann'], true)) {
                return $norm;
            }
        }

        $desc = (string) $req->getDescription();
        if ($desc !== '') {
            if (preg_match('/\b(MUSS|MÜSSEN|DÜRFEN NUR|DARF NUR)\b/u', $desc) === 1) {
                return 'muss';
            }
            if (preg_match('/\b(SOLLTE|SOLLTEN)\b/u', $desc) === 1) {
                return 'sollte';
            }
            if (preg_match('/\b(KANN|KÖNNEN)\b/u', $desc) === 1) {
                return 'kann';
            }
        }

        return match ((string) $req->getPriority()) {
            'critical', 'high' => 'muss',
            'medium'           => 'sollte',
            'low'              => 'kann',
            default            => 'muss',
        };
    }

    private function newAggregate(): array
    {
        return [
            'muss'   => ['total' => 0, 'fulfilled' => 0, 'sum_pct' => 0],
            'sollte' => ['total' => 0, 'fulfilled' => 0, 'sum_pct' => 0],
            'kann'   => ['total' => 0, 'fulfilled' => 0, 'sum_pct' => 0],
        ];
    }

    private function emptyAggregate(): array
    {
        return $this->finalizeAggregate($this->newAggregate());
    }

    private function updateAggregate(array &$agg, string $type, int $fulfillmentPct, bool $fulfilled): void
    {
        if (!isset($agg[$type])) {
            return;
        }
        $agg[$type]['total']++;
        $agg[$type]['sum_pct'] += $fulfillmentPct;
        if ($fulfilled) {
            $agg[$type]['fulfilled']++;
        }
    }

    /**
     * Convert raw accumulators into the shape the UI/controller expects.
     */
    private function finalizeAggregate(array $agg): array
    {
        $breakdown = [];
        $weightedNumerator = 0.0;
        $weightedDenominator = 0.0;
        $totalCount = 0;
        $fulfilledCount = 0;
        $sumPct = 0;

        foreach (['muss', 'sollte', 'kann'] as $type) {
            $row = $agg[$type] ?? ['total' => 0, 'fulfilled' => 0, 'sum_pct' => 0];
            $pct = $row['total'] > 0 ? (int) round($row['sum_pct'] / $row['total']) : null;
            $breakdown[$type] = [
                'total'     => $row['total'],
                'fulfilled' => $row['fulfilled'],
                'pct'       => $pct,
            ];
            $weight = $this->weightFor($type);
            if ($row['total'] > 0) {
                $weightedNumerator   += $weight * ($row['sum_pct'] / $row['total']);
                $weightedDenominator += $weight;
            }
            $totalCount     += $row['total'];
            $fulfilledCount += $row['fulfilled'];
            $sumPct         += $row['sum_pct'];
        }

        $weighted = $weightedDenominator > 0
            ? (int) round($weightedNumerator / $weightedDenominator)
            : null;
        $unweighted = $totalCount > 0 ? (int) round($sumPct / $totalCount) : null;

        return [
            'total'            => $totalCount,
            'fulfilled'        => $fulfilledCount,
            'fulfillment_pct'  => $unweighted,
            'weighted_pct'     => $weighted,
            'breakdown'        => $breakdown,
            'status'           => $this->statusFor($weighted),
        ];
    }

    private function weightFor(string $type): int
    {
        return match ($type) {
            'muss'   => self::WEIGHT_MUSS,
            'sollte' => self::WEIGHT_SOLLTE,
            'kann'   => self::WEIGHT_KANN,
            default  => 0,
        };
    }

    private function statusFor(?int $weighted): string
    {
        if ($weighted === null) {
            return 'na';
        }
        if ($weighted >= 80) {
            return 'good';
        }
        if ($weighted >= 50) {
            return 'warning';
        }
        return 'danger';
    }
}
