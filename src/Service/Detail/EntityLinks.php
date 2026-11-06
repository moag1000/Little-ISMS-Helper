<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Asset;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Vulnerability;
use App\Repository\RiskRepository;
use App\Risk\RiskMatrixThresholds;
use App\Service\ModuleConfigurationService;

/**
 * The evidence chain of a record, read from the relations it already has
 * (UI wave E): Asset → Risk → Control → Evidence → Incident → Vulnerability.
 * Every detail page renders it as its first block
 * (`_components/_fa_entity_links.html.twig`), so an auditor sees the chain
 * without scrolling and a gap shows up as an empty group.
 *
 * Groups whose module is off are left out; the record's own type is skipped.
 */
class EntityLinks
{
    public const int LIMIT = 8;

    /** Group type => [module key, show route]. Order is the chain order. */
    private const array GROUPS = [
        'asset' => ['assets', 'app_asset_show'],
        'risk' => ['risks', 'app_risk_show'],
        'control' => ['controls', 'app_soa_show'],
        'evidence' => ['documents', 'app_document_show'],
        'incident' => ['incidents', 'app_incident_show'],
        'vulnerability' => ['vulnerability_intel', 'app_vulnerability_show'],
    ];

    /** "Derive X from this record" links that the /new forms already understand. */
    private const array DERIVE = [
        'vulnerability' => ['risk' => ['app_risk_new', 'fromVulnerability']],
        'incident' => [
            'risk' => ['app_risk_new', 'fromIncident'],
            'vulnerability' => ['app_vulnerability_new', 'fromIncident'],
        ],
        'risk' => ['incident' => ['app_incident_new', 'fromRisk']],
    ];

    public function __construct(
        private readonly ModuleConfigurationService $modules,
        private readonly RiskRepository $riskRepository,
    ) {
    }

    /**
     * @return list<array{type: string, total: int, via: ?string, route: string,
     *     items: list<array{id: int, label: string, code: ?string, tone: string, meta: ?string}>,
     *     derive: ?array{route: string, params: array<string, int>}}>
     */
    public function for(object $entity): array
    {
        $source = self::typeOf($entity);
        if ($source === null) {
            return [];
        }

        $related = $this->related($entity);
        $groups = [];
        foreach (self::GROUPS as $type => [$module, $route]) {
            if ($type === $source || !array_key_exists($type, $related)) {
                continue;
            }
            if (!$this->modules->isModuleActive($module)) {
                continue;
            }
            [$records, $via] = $related[$type];
            $derive = self::DERIVE[$source][$type] ?? null;
            $groups[] = [
                'type' => $type,
                'total' => count($records),
                'via' => $via,
                'route' => $route,
                'items' => array_map(self::item(...), array_slice($records, 0, self::LIMIT)),
                'derive' => $derive === null ? null : [
                    'route' => $derive[0],
                    'params' => [$derive[1] => (int) $entity->getId()],
                ],
            ];
        }

        return $groups;
    }

    public static function typeOf(object $entity): ?string
    {
        return match (true) {
            $entity instanceof Asset => 'asset',
            $entity instanceof Risk => 'risk',
            $entity instanceof Control => 'control',
            $entity instanceof Incident => 'incident',
            $entity instanceof Vulnerability => 'vulnerability',
            default => null,
        };
    }

    /** @return array<string, array{0: list<object>, 1: ?string}> type => [records, via] */
    private function related(object $entity): array
    {
        return match (true) {
            $entity instanceof Risk => [
                'asset' => [self::unique([$entity->getAsset(), ...$entity->getIctAssetDependency()]), null],
                'control' => [self::unique([...$entity->getControls()]), null],
                'evidence' => [self::evidenceOf([...$entity->getControls()]), 'control'],
                'incident' => [self::unique([...$entity->getIncidents()]), null],
                'vulnerability' => [self::unique([$entity->getLinkedVulnerability()]), null],
            ],
            $entity instanceof Control => [
                'asset' => [self::unique([...$entity->getProtectedAssets()]), null],
                'risk' => [self::unique([...$entity->getRisks()]), null],
                'evidence' => [self::unique([...$entity->getEvidenceDocuments()]), null],
                'incident' => [self::unique([...$entity->getIncidents()]), null],
            ],
            $entity instanceof Asset => [
                'risk' => [self::unique([...$entity->getRisks()]), null],
                'control' => [self::unique([...$entity->getProtectingControls()]), null],
                'evidence' => [self::evidenceOf([...$entity->getProtectingControls()]), 'control'],
                'incident' => [self::unique([...$entity->getIncidents()]), null],
            ],
            $entity instanceof Incident => [
                'asset' => [self::unique([...$entity->getAffectedAssets()]), null],
                'risk' => [self::unique([...$entity->getRealizedRisks()]), null],
                'control' => [self::unique([...$entity->getRelatedControls(), ...$entity->getFailedControls()]), null],
                'vulnerability' => [self::unique([...$entity->getRelatedVulnerabilities()]), null],
            ],
            $entity instanceof Vulnerability => [
                'asset' => [self::unique([...$entity->getAffectedAssets()]), null],
                'risk' => [self::unique($this->riskRepository->findBy(['linkedVulnerability' => $entity])), null],
                'incident' => [self::unique([...$entity->getIncidents()]), null],
            ],
            default => [],
        };
    }

    /**
     * @param list<Control> $controls
     * @return list<object>
     */
    private static function evidenceOf(array $controls): array
    {
        $documents = [];
        foreach ($controls as $control) {
            foreach ($control->getEvidenceDocuments() as $document) {
                $documents[] = $document;
            }
        }

        return self::unique($documents);
    }

    /**
     * Drops nulls and duplicates (by id), keeps the order.
     *
     * @param array<int, object|null> $records
     * @return list<object>
     */
    private static function unique(array $records): array
    {
        $seen = [];
        $out = [];
        foreach ($records as $record) {
            if ($record === null || !method_exists($record, 'getId') || $record->getId() === null) {
                continue;
            }
            if (isset($seen[$record->getId()])) {
                continue;
            }
            $seen[$record->getId()] = true;
            $out[] = $record;
        }

        return $out;
    }

    /** @return array{id: int, label: string, code: ?string, tone: string, meta: ?string} */
    private static function item(object $record): array
    {
        [$label, $code, $tone, $meta] = match (true) {
            $record instanceof Asset => [
                (string) $record->getName(),
                null,
                self::ciaTone(self::maxCia($record)),
                self::maxCia($record) > 0 ? 'CIA ' . self::maxCia($record) . '/5' : null,
            ],
            $record instanceof Risk => [
                (string) $record->getTitle(),
                null,
                self::bandTone(RiskMatrixThresholds::classify($record->getResidualRiskLevel())),
                (string) $record->getResidualRiskLevel(),
            ],
            $record instanceof Control => [
                (string) $record->getName(),
                $record->getControlId(),
                $record->isApplicable() === false ? 'neutral' : match ($record->getImplementationStatus()) {
                    'implemented', 'verified' => 'success',
                    'in_progress', 'partial', 'planned' => 'warning',
                    default => 'danger',
                },
                $record->getImplementationPercentage() !== null ? $record->getImplementationPercentage() . ' %' : null,
            ],
            $record instanceof Document => [
                (string) $record->getOriginalFilename(),
                null,
                'neutral',
                $record->getCategory(),
            ],
            $record instanceof Incident => [
                (string) $record->getTitle(),
                $record->getIncidentNumber(),
                self::severityTone($record->getSeverity()?->value),
                $record->getSeverity()?->value,
            ],
            $record instanceof Vulnerability => [
                (string) $record->getTitle(),
                $record->getCveId(),
                self::severityTone($record->getSeverity()),
                $record->getSeverity(),
            ],
            default => ['', null, 'neutral', null],
        };

        return [
            'id' => (int) $record->getId(),
            'label' => $label,
            'code' => $code,
            'tone' => $tone,
            'meta' => $meta,
        ];
    }

    private static function bandTone(string $band): string
    {
        return match ($band) {
            'critical', 'high' => 'danger',
            'medium' => 'warning',
            default => 'success',
        };
    }

    private static function severityTone(?string $severity): string
    {
        return match ($severity) {
            'critical', 'high' => 'danger',
            'medium' => 'warning',
            default => 'neutral',
        };
    }

    private static function maxCia(Asset $asset): int
    {
        return max(
            (int) $asset->getConfidentialityValue(),
            (int) $asset->getIntegrityValue(),
            (int) $asset->getAvailabilityValue(),
        );
    }

    private static function ciaTone(int $max): string
    {
        return $max >= 4 ? 'danger' : ($max === 3 ? 'warning' : 'neutral');
    }
}
