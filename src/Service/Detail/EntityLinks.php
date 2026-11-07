<?php

declare(strict_types=1);

namespace App\Service\Detail;

use App\Entity\Asset;
use App\Entity\AuditFinding;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\ChangeRequest;
use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\CorrectiveAction;
use App\Entity\DataBreach;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Document;
use App\Entity\Incident;
use App\Entity\InternalAudit;
use App\Entity\Location;
use App\Entity\Patch;
use App\Entity\ProcessingActivity;
use App\Entity\Risk;
use App\Entity\RiskTreatmentPlan;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\Training;
use App\Entity\Vulnerability;
use App\Repository\ControlRepository;
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
 * Records outside the chain (supplier, BC plan, processing activity, …) show
 * the chain types they relate to; a type they cannot relate to is not shown.
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

    /**
     * "Derive X from this record" links that the /new forms understand
     * (`App\Service\Prefill\RecordPrefill`). A route starting with `#` is an
     * anchor on the same page (control evidence is uploaded in place).
     */
    private const array DERIVE = [
        'asset' => ['risk' => ['app_risk_new', 'fromAsset']],
        'control' => ['evidence' => ['#evidence', null]],
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
        private readonly ?ControlRepository $controlRepository = null,
    ) {
    }

    /**
     * @return list<array{type: string, total: int, via: ?string, route: string,
     *     items: list<array{id: int, label: string, code: ?string, tone: string, meta: ?string}>,
     *     derive: ?array{route: ?string, params: array<string, int>, anchor: ?string}}>
     */
    public function for(object $entity): array
    {
        $related = $this->related($entity);
        if ($related === []) {
            return [];
        }
        $source = self::typeOf($entity);
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
                'derive' => match (true) {
                    $derive === null => null,
                    $derive[1] === null => ['route' => null, 'params' => [], 'anchor' => $derive[0]],
                    default => ['route' => $derive[0], 'params' => [$derive[1] => (int) $entity->getId()], 'anchor' => null],
                },
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
            $entity instanceof Document => 'evidence',
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
            default => $this->relatedOutsideChain($entity),
        };
    }

    /**
     * Records that are not links of the chain themselves but point into it.
     *
     * @return array<string, array{0: list<object>, 1: ?string}>
     */
    private function relatedOutsideChain(object $entity): array
    {
        return match (true) {
            $entity instanceof Document => [
                'control' => [self::unique($this->controlsUsingEvidence($entity)), null],
            ],
            $entity instanceof AuditFinding => [
                'control' => [self::unique([$entity->getRelatedControl(), ...$entity->getRelatedControls()]), null],
            ],
            $entity instanceof CorrectiveAction => [
                'control' => [self::unique([...$entity->getRelatedControls()]), null],
                'incident' => [self::unique([$entity->getSourceIncident()]), null],
            ],
            $entity instanceof RiskTreatmentPlan => [
                'risk' => [self::unique([$entity->getRisk()]), null],
                'control' => [self::unique([...$entity->getControls()]), null],
                'evidence' => [self::unique([...$entity->getEvidenceDocuments()]), null],
            ],
            $entity instanceof ComplianceRequirement => [
                'control' => [self::unique([...$entity->getMappedControls()]), null],
                'evidence' => [self::unique([...$entity->getEvidenceDocuments()]), null],
            ],
            $entity instanceof BusinessProcess => [
                'asset' => [self::unique([...$entity->getSupportingAssets()]), null],
                'risk' => [self::unique([...$entity->getIdentifiedRisks()]), null],
                'incident' => [self::unique([...$entity->getIncidents()]), null],
            ],
            $entity instanceof BusinessContinuityPlan => [
                'asset' => [self::unique([...$entity->getCriticalAssets()]), null],
                'evidence' => [self::unique([...$entity->getDocuments()]), null],
            ],
            $entity instanceof ChangeRequest => [
                'asset' => [self::unique([...$entity->getAffectedAssets()]), null],
                'risk' => [self::unique([...$entity->getAssociatedRisks()]), null],
                'control' => [self::unique([...$entity->getAffectedControls()]), null],
                'evidence' => [self::unique([...$entity->getDocuments()]), null],
            ],
            $entity instanceof Supplier => [
                'asset' => [self::unique([...$entity->getSupportedAssets()]), null],
                'risk' => [self::unique([...$entity->getIdentifiedRisks()]), null],
                'evidence' => [self::unique([...$entity->getDocuments()]), null],
            ],
            $entity instanceof ProcessingActivity => [
                'asset' => [self::unique([...$entity->getAssets()]), null],
                'control' => [self::unique([...$entity->getImplementedControls()]), null],
            ],
            $entity instanceof DataProtectionImpactAssessment => [
                'asset' => [self::unique([$entity->getRelatedAsset()]), null],
                'control' => [self::unique([...$entity->getImplementedControls()]), null],
            ],
            $entity instanceof DataBreach => [
                'incident' => [self::unique([$entity->getIncident()]), null],
            ],
            $entity instanceof Patch => [
                'asset' => [self::unique([...$entity->getAffectedAssets()]), null],
                'vulnerability' => [self::unique([$entity->getVulnerability()]), null],
            ],
            $entity instanceof ThreatIntelligence => [
                'asset' => [self::unique([...$entity->getAffectedAssets()]), null],
                'incident' => [self::unique([...$entity->getResultingIncidents()]), null],
            ],
            $entity instanceof Location => [
                'asset' => [self::unique([...$entity->getAssets()]), null],
            ],
            $entity instanceof InternalAudit => [
                'asset' => [self::unique([...$entity->getScopedAssets()]), null],
            ],
            $entity instanceof Training => [
                'control' => [self::unique([...$entity->getCoveredControls()]), null],
            ],
            default => [],
        };
    }

    /** @return list<Control> controls that hold the document as evidence */
    private function controlsUsingEvidence(Document $document): array
    {
        if ($this->controlRepository === null || $document->getId() === null) {
            return [];
        }
        $qb = $this->controlRepository->createQueryBuilder('c')
            ->innerJoin('c.evidenceDocuments', 'd')
            ->andWhere('d.id = :document')
            ->setParameter('document', $document->getId())
            ->orderBy('c.controlId', 'ASC');
        if ($document->getTenant() !== null) {
            $qb->andWhere('c.tenant = :tenant')->setParameter('tenant', $document->getTenant());
        }

        return $qb->getQuery()->getResult();
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
