<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Rollup;

use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Repository\UserRepository;
use App\Repository\WorkflowInstanceRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Policy-Wizard W7-B — Konzern roll-up aggregator.
 *
 * Walks the Konzern subtree (root + all descendant tenants) and
 * assembles six dashboard data slices into {@see KonzernRollupReport}:
 *
 *   1. tenantTree           — hierarchical tree (depth + children)
 *   2. policyCoverageMatrix — Document count per (tenant, standard)
 *   3. outstandingActions   — open WorkflowInstances with severity + due
 *   4. complianceScore      — fulfillment % per (tenant, framework)
 *   5. settingsDriftRows    — TenantPolicySetting rows with drift markers
 *   6. acknowledgmentCoverage — % of tenant users who acknowledged
 *                                published policies (A.6.3)
 *
 * Spec: docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md
 *       lines 298-301 (Compliance-Manager "What's missing" #4 +
 *       CISO Board-Reporting + Auditor Konzern-Tochter compliance).
 *
 * Read-only: no entities are persisted, no flushes happen. Safe to call
 * from a controller render. Repository dependencies are nullable so a
 * minimal unit-test fixture can leave any slice empty.
 */
final class KonzernRollupAggregator
{
    /**
     * Document.category prefixes / strings that map to a standard code
     * for the policyCoverageMatrix breakdown. Matches the convention
     * the Policy-Wizard's DocumentGenerator uses (`iso27001_*`,
     * `dora_*`, `nis2_*`, `bsi_*`, `iso27701_*`, `bcm_*`).
     *
     * @var array<string, string>
     */
    private const CATEGORY_PREFIX_TO_STANDARD = [
        'iso27001'  => 'ISO 27001',
        'iso_27001' => 'ISO 27001',
        'dora'      => 'DORA',
        'nis2'      => 'NIS2',
        'bsi'       => 'BSI 200-x',
        'iso27701'  => 'ISO 27701',
        'iso_27701' => 'ISO 27701',
        'bcm'       => 'ISO 22301',
        'iso22301'  => 'ISO 22301',
    ];

    private const STANDARD_OTHER = 'Other';

    public function __construct(
        private readonly DocumentRepository $documentRepository,
        private readonly WorkflowInstanceRepository $workflowInstanceRepository,
        private readonly TenantPolicySettingRepository $tenantPolicySettingRepository,
        private readonly ComplianceFrameworkRepository $complianceFrameworkRepository,
        private readonly ComplianceRequirementRepository $complianceRequirementRepository,
        private readonly PolicyAcknowledgementRepository $policyAcknowledgementRepository,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Assemble the roll-up. Returns an empty report (subsidiaryCount=0)
     * when the tenant has no descendants — the controller renders an
     * empty-state in that case.
     */
    public function aggregateForKonzern(Tenant $konzernRoot): KonzernRollupReport
    {
        $descendants = $konzernRoot->getAllSubsidiaries();
        $allTenants = array_merge([$konzernRoot], $descendants);

        $tree = $this->buildTenantTree($konzernRoot);

        if ($descendants === []) {
            return new KonzernRollupReport(
                konzernRoot: $konzernRoot,
                tenantTree: $tree,
                policyCoverageMatrix: [],
                outstandingActions: [],
                complianceScore: [],
                settingsDriftRows: [],
                acknowledgmentCoverage: [],
                subsidiaryCount: 0,
            );
        }

        return new KonzernRollupReport(
            konzernRoot: $konzernRoot,
            tenantTree: $tree,
            policyCoverageMatrix: $this->buildPolicyCoverageMatrix($allTenants),
            outstandingActions: $this->buildOutstandingActions($allTenants),
            complianceScore: $this->buildComplianceScore($allTenants),
            settingsDriftRows: $this->buildSettingsDriftRows($konzernRoot, $descendants),
            acknowledgmentCoverage: $this->buildAcknowledgmentCoverage($allTenants),
            subsidiaryCount: count($descendants),
        );
    }

    // -----------------------------------------------------------------
    // tenantTree
    // -----------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function buildTenantTree(Tenant $root): array
    {
        return [$this->renderTenantNode($root, 0)];
    }

    /**
     * @return array<string, mixed>
     */
    private function renderTenantNode(Tenant $tenant, int $depth): array
    {
        $children = [];
        foreach ($tenant->getSubsidiaries() as $child) {
            if (!$child instanceof Tenant) {
                continue;
            }
            $children[] = $this->renderTenantNode($child, $depth + 1);
        }
        return [
            'tenant_id' => $tenant->getId() ?? 0,
            'code'      => $tenant->getCode() ?? '',
            'name'      => $tenant->getName() ?? '',
            'depth'     => $depth,
            'children'  => $children,
        ];
    }

    // -----------------------------------------------------------------
    // policyCoverageMatrix
    // -----------------------------------------------------------------

    /**
     * @param list<Tenant> $tenants
     * @return list<array<string, mixed>>
     */
    private function buildPolicyCoverageMatrix(array $tenants): array
    {
        $rows = [];
        foreach ($tenants as $tenant) {
            $tenantId = $tenant->getId();
            if ($tenantId === null) {
                continue;
            }

            $documents = $this->documentRepository->findBy([
                'tenant'     => $tenant,
                'isArchived' => false,
            ]);

            $perStandard = [];
            foreach ($documents as $document) {
                if (!$document instanceof Document) {
                    continue;
                }
                if (in_array($document->getStatus(), ['deleted', 'archived'], true)) {
                    continue;
                }
                $standard = $this->classifyDocumentStandard($document);
                if (!isset($perStandard[$standard])) {
                    $perStandard[$standard] = [
                        'standard_code'             => $standard,
                        'policy_count'              => 0,
                        'last_updated_at'           => null,
                        'approval_status_breakdown' => [],
                    ];
                }
                $perStandard[$standard]['policy_count']++;

                $updatedAt = $document->getUpdatedAt();
                if ($updatedAt instanceof DateTimeInterface) {
                    $iso = $updatedAt->format(DATE_ATOM);
                    if ($perStandard[$standard]['last_updated_at'] === null
                        || $iso > $perStandard[$standard]['last_updated_at']) {
                        $perStandard[$standard]['last_updated_at'] = $iso;
                    }
                }

                $status = $document->getStatus();
                $perStandard[$standard]['approval_status_breakdown'][$status]
                    = ($perStandard[$standard]['approval_status_breakdown'][$status] ?? 0) + 1;
            }

            $rows[] = [
                'tenant_id'   => $tenantId,
                'tenant_code' => $tenant->getCode() ?? '',
                'tenant_name' => $tenant->getName() ?? '',
                'standards'   => $perStandard,
            ];
        }
        return $rows;
    }

    private function classifyDocumentStandard(Document $document): string
    {
        $category = strtolower((string) ($document->getCategory() ?? ''));
        if ($category === '') {
            return self::STANDARD_OTHER;
        }
        foreach (self::CATEGORY_PREFIX_TO_STANDARD as $prefix => $label) {
            if (str_starts_with($category, $prefix)) {
                return $label;
            }
        }
        return self::STANDARD_OTHER;
    }

    // -----------------------------------------------------------------
    // outstandingActions
    // -----------------------------------------------------------------

    /**
     * @param list<Tenant> $tenants
     * @return list<array<string, mixed>>
     */
    private function buildOutstandingActions(array $tenants): array
    {
        $rows = [];
        $now = new DateTimeImmutable();

        foreach ($tenants as $tenant) {
            $tenantId = $tenant->getId();
            if ($tenantId === null) {
                continue;
            }

            $instances = $this->workflowInstanceRepository->findBy([
                'tenant' => $tenant,
                'status' => ['pending', 'in_progress'],
            ]);

            foreach ($instances as $instance) {
                if (!$instance instanceof WorkflowInstance) {
                    continue;
                }
                $dueDate = $instance->getDueDate();
                $dueIn = null;
                $severity = 'info';
                if ($dueDate instanceof DateTimeInterface) {
                    $dueIn = $dueDate->getTimestamp() - $now->getTimestamp();
                    if ($dueIn < 0) {
                        $severity = 'danger';
                    } elseif ($dueIn < 86_400 * 3) {
                        // <72h
                        $severity = 'warning';
                    }
                }

                $action = $instance->getCurrentStep()?->getName() ?? $instance->getStatus();

                $rows[] = [
                    'tenant_id'            => $tenantId,
                    'tenant_code'          => $tenant->getCode() ?? '',
                    'tenant_name'          => $tenant->getName() ?? '',
                    'action'               => $action,
                    'severity'             => $severity,
                    'due_in_seconds'       => $dueIn,
                    'workflow_instance_id' => $instance->getId() ?? 0,
                    'entity_type'          => $instance->getEntityType() ?? '',
                    'entity_id'            => $instance->getEntityId() ?? 0,
                ];
            }
        }

        // Most-overdue first, then most-urgent.
        usort($rows, static function (array $a, array $b): int {
            $sevOrder = ['danger' => 0, 'warning' => 1, 'info' => 2];
            $sevDiff = $sevOrder[$a['severity']] <=> $sevOrder[$b['severity']];
            if ($sevDiff !== 0) {
                return $sevDiff;
            }
            $aDue = $a['due_in_seconds'] ?? PHP_INT_MAX;
            $bDue = $b['due_in_seconds'] ?? PHP_INT_MAX;
            return $aDue <=> $bDue;
        });

        return $rows;
    }

    // -----------------------------------------------------------------
    // complianceScore
    // -----------------------------------------------------------------

    /**
     * @param list<Tenant> $tenants
     * @return list<array<string, mixed>>
     */
    private function buildComplianceScore(array $tenants): array
    {
        $rows = [];
        $frameworks = $this->complianceFrameworkRepository->findBy(['active' => true]);

        foreach ($tenants as $tenant) {
            $tenantId = $tenant->getId();
            if ($tenantId === null) {
                continue;
            }

            foreach ($frameworks as $framework) {
                if (!$framework instanceof ComplianceFramework) {
                    continue;
                }
                try {
                    $stats = $this->complianceRequirementRepository->getFrameworkStatisticsForTenant(
                        $framework,
                        $tenant,
                    );
                } catch (\Throwable $error) {
                    $this->logger->warning(
                        'KonzernRollupAggregator: framework statistics fetch failed; skipping row',
                        [
                            'tenant_id'    => $tenantId,
                            'framework_id' => $framework->getId(),
                            'error'        => $error->getMessage(),
                        ],
                    );
                    continue;
                }

                $applicable = (int) ($stats['applicable'] ?? 0);
                $fulfilled = (int) ($stats['fulfilled'] ?? 0);
                $score = $applicable > 0
                    ? round(($fulfilled / $applicable) * 100, 2)
                    : 0.0;

                $rows[] = [
                    'tenant_id'              => $tenantId,
                    'tenant_code'            => $tenant->getCode() ?? '',
                    'tenant_name'            => $tenant->getName() ?? '',
                    'framework_code'         => $framework->getCode() ?? '',
                    'framework_name'         => $framework->getName() ?? '',
                    'score_percentage'       => (float) $score,
                    'total_requirements'     => (int) ($stats['total'] ?? 0),
                    'fulfilled_requirements' => $fulfilled,
                ];
            }
        }
        return $rows;
    }

    // -----------------------------------------------------------------
    // settingsDriftRows
    // -----------------------------------------------------------------

    /**
     * @param list<Tenant> $descendants
     * @return list<array<string, mixed>>
     */
    private function buildSettingsDriftRows(Tenant $konzernRoot, array $descendants): array
    {
        $rows = [];
        // Pre-load Konzern's own values so each drift row can carry the
        // parent baseline next to the Tochter override.
        $konzernSettings = [];
        foreach ($this->tenantPolicySettingRepository->findByTenant($konzernRoot) as $setting) {
            if (!$setting instanceof TenantPolicySetting) {
                continue;
            }
            $key = $setting->getKey();
            if ($key === null) {
                continue;
            }
            $konzernSettings[$key] = $setting->getValue();
        }

        foreach ($descendants as $tenant) {
            $tenantId = $tenant->getId();
            if ($tenantId === null) {
                continue;
            }
            foreach ($this->tenantPolicySettingRepository->findByTenant($tenant) as $setting) {
                if (!$setting instanceof TenantPolicySetting) {
                    continue;
                }
                $value = $setting->getValue();
                if (!is_array($value) || !isset($value['_meta']) || !is_array($value['_meta'])) {
                    continue;
                }
                $meta = $value['_meta'];
                if (($meta['settings_drift_detected'] ?? false) !== true) {
                    continue;
                }
                $tochterValue = $value;
                unset($tochterValue['_meta']);
                if (array_keys($tochterValue) === ['value']) {
                    $tochterValue = $tochterValue['value'];
                }

                $key = $setting->getKey() ?? '';
                $rows[] = [
                    'tenant_id'         => $tenantId,
                    'tenant_code'       => $tenant->getCode() ?? '',
                    'tenant_name'       => $tenant->getName() ?? '',
                    'setting_key'       => $key,
                    'konzern_value'     => $meta['drift_parent_value'] ?? ($konzernSettings[$key] ?? null),
                    'tochter_value'     => $tochterValue,
                    'drift_detected_at' => $meta['drift_detected_at'] ?? null,
                    'override_mode'     => $setting->getOverrideMode(),
                ];
            }
        }
        return $rows;
    }

    // -----------------------------------------------------------------
    // acknowledgmentCoverage
    // -----------------------------------------------------------------

    /**
     * @param list<Tenant> $tenants
     * @return list<array<string, mixed>>
     */
    private function buildAcknowledgmentCoverage(array $tenants): array
    {
        $rows = [];

        foreach ($tenants as $tenant) {
            $tenantId = $tenant->getId();
            if ($tenantId === null) {
                continue;
            }

            $publishedDocs = $this->countPublishedDocuments($tenant);
            $totalUsers = $this->countActiveUsersForTenant($tenant);
            $acks = $this->countAcknowledgementsForTenant($tenant);

            // Coverage definition: "average ack-rate across published docs".
            // expected = published × users; if any side is zero we report 0.
            $expected = $publishedDocs * $totalUsers;
            $coverage = $expected > 0 ? round(($acks / $expected) * 100, 2) : 0.0;

            $rows[] = [
                'tenant_id'                 => $tenantId,
                'tenant_code'               => $tenant->getCode() ?? '',
                'tenant_name'               => $tenant->getName() ?? '',
                'published_documents_count' => $publishedDocs,
                'total_users'               => $totalUsers,
                'acknowledgements_count'    => $acks,
                'coverage_percentage'       => (float) $coverage,
            ];
        }
        return $rows;
    }

    private function countPublishedDocuments(Tenant $tenant): int
    {
        $documents = $this->documentRepository->findBy([
            'tenant'     => $tenant,
            'isArchived' => false,
            'status'     => 'published',
        ]);
        return count($documents);
    }

    private function countActiveUsersForTenant(Tenant $tenant): int
    {
        // UserRepository.findByTenant is not standard; the User entity
        // owns its tenant FK. findBy() works here.
        $users = $this->userRepository->findBy([
            'tenant'   => $tenant,
            'isActive' => true,
        ]);
        return count($users);
    }

    private function countAcknowledgementsForTenant(Tenant $tenant): int
    {
        // Audit V3 W2-C4: count only completed ACKNOWLEDGED rows.
        $acks = $this->policyAcknowledgementRepository->findBy([
            'tenant' => $tenant,
            'status' => PolicyAcknowledgement::STATUS_ACKNOWLEDGED,
        ]);
        // Use unique (document, user) pairs so multi-version acks count
        // as a single coverage hit per (doc, user).
        $seen = [];
        foreach ($acks as $ack) {
            if (!$ack instanceof PolicyAcknowledgement) {
                continue;
            }
            $key = ($ack->getDocument()?->getId() ?? 0) . ':' . ($ack->getUser()?->getId() ?? 0);
            $seen[$key] = true;
        }
        return count($seen);
    }
}
