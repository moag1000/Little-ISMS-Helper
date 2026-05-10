<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Repository\AuditLogRepository;
use App\Repository\DocumentRepository;
use App\Repository\RiskRepository;
use App\Repository\WorkflowInstanceRepository;
use DateTimeImmutable;
use DateTimeInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Audit V3 C6 — Activity-Feed (Cross-Persona).
 *
 * Aggregates user-relevant activity from:
 *   - AuditLog (last 50 entries)
 *   - WorkflowInstance updates (recent transitions)
 *   - Document version changes
 *   - Risk status changes (last X)
 *
 * Returns a uniform list of feed entries:
 *   { tone, icon, title, subtitle, link, timestamp, actor }
 *
 * Used both as:
 *   - Full-page feed via /activity-feed
 *   - Dashboard widget via _activity_feed_widget.html.twig
 */
class ActivityFeed
{
    public function __construct(
        private readonly AuditLogRepository $auditLogRepo,
        private readonly WorkflowInstanceRepository $workflowRepo,
        private readonly DocumentRepository $documentRepo,
        private readonly RiskRepository $riskRepo,
        private readonly TenantContext $tenantContext,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Generate a route URL safely. Returns null if route does not exist
     * (e.g. during refactors) instead of throwing.
     */
    private function safeUrl(string $route, array $params): ?string
    {
        try {
            return $this->urlGenerator->generate($route, $params);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Compliance-relevant entity types and audit-log action prefixes for
     * scope=compliance filtering (V4-EF-6).
     */
    private const COMPLIANCE_ENTITY_TYPES = [
        'Document',
        'ComplianceFramework',
        'ComplianceMapping',
        'AuditFinding',
        'CertificationBundle',
        'WizardSession',
    ];

    private const COMPLIANCE_SOURCES = ['document', 'workflow'];

    private const COMPLIANCE_ACTION_PREFIXES = ['approve', 'reject', 'compliance'];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(?User $user = null, int $limit = 50, string $scope = 'all'): array
    {
        $items = [];
        $tenant = $this->tenantContext->getCurrentTenant();

        // 1. AuditLog (last 50) — tenant-scoped (Audit V3 W2-C1).
        // When no tenant context is available we deliberately return nothing
        // from this source rather than leaking cross-tenant rows.
        $auditLogs = $tenant
            ? $this->auditLogRepo->findAllOrderedForTenant($tenant, $limit, 0)
            : [];
        foreach ($auditLogs as $log) {
            $entityType = $log->getEntityType() ?? '';
            $action     = $log->getAction() ?? '';
            if ($scope === 'compliance' && !$this->isComplianceAuditLog($entityType, $action)) {
                continue;
            }
            $items[] = [
                'tone'      => $this->toneForAction($action),
                'icon'      => $this->iconForAction($action),
                'title'     => sprintf('%s %s', $action !== '' ? $action : '?', $entityType),
                'subtitle'  => $log->getDescription() ?? '',
                'link'      => null,
                'timestamp' => $log->getCreatedAt(),
                'actor'     => $log->getUserName() ?? '—',
                'source'    => 'audit_log',
            ];
        }

        // 2. WorkflowInstance recent (active) — tenant-scoped (Audit V3 W2-C1).
        // scope=compliance: only Document-approval workflows surfaced.
        $activeWorkflows = $tenant
            ? $this->workflowRepo->findActiveForTenant($tenant)
            : [];
        foreach (array_slice($activeWorkflows, 0, 25) as $instance) {
            /** @var WorkflowInstance $instance */
            if ($scope === 'compliance') {
                $wfEntityType = $instance->getEntityType() ?? '';
                if (!$this->isComplianceWorkflow($wfEntityType)) {
                    continue;
                }
            }
            $items[] = [
                'tone'      => $instance->getStatus() === 'rejected' ? 'danger'
                    : ($instance->getStatus() === 'approved' ? 'success' : 'warning'),
                'icon'      => 'fa-icon--ui-circle',
                'title'     => sprintf('Workflow %s — %s',
                    $instance->getWorkflow()?->getName() ?? '?',
                    $instance->getStatus()),
                'subtitle'  => $instance->getCurrentStep()?->getName() ?? '',
                'link'      => $this->safeUrl('app_workflow_instance_show', ['id' => $instance->getId()]),
                'timestamp' => $instance->getStartedAt(),
                'actor'     => $instance->getInitiatedBy() ? trim(($instance->getInitiatedBy()->getFirstName() ?? '') . ' ' . ($instance->getInitiatedBy()->getLastName() ?? '')) : '—',
                'source'    => 'workflow',
            ];
        }

        // 3. Recent Document changes (always compliance-relevant).
        if ($tenant) {
            $docs = $this->documentRepo->createQueryBuilder('d')
                ->andWhere('d.tenant = :tenant')
                ->setParameter('tenant', $tenant)
                ->orderBy('d.id', 'DESC')
                ->setMaxResults(15)
                ->getQuery()
                ->getResult();
            foreach ($docs as $doc) {
                /** @var Document $doc */
                $items[] = [
                    'tone'      => 'info',
                    'icon'      => 'fa-icon--ui-document',
                    'title'     => sprintf('Document %s · v%s', $doc->getOriginalFilename() ?? '—', $doc->getVersion() ?? '—'),
                    'subtitle'  => $doc->getStatus() ?? '',
                    'link'      => $this->safeUrl('app_document_show', ['id' => $doc->getId()]),
                    'timestamp' => method_exists($doc, 'getUpdatedAt') ? $doc->getUpdatedAt() : null,
                    'actor'     => '—',
                    'source'    => 'document',
                ];
            }
        }

        // 4. Recent Risks — excluded from compliance scope (operational, not compliance-specific).
        if ($tenant && $scope !== 'compliance') {
            $risks = $this->riskRepo->createQueryBuilder('r')
                ->andWhere('r.tenant = :tenant')
                ->setParameter('tenant', $tenant)
                ->orderBy('r.id', 'DESC')
                ->setMaxResults(15)
                ->getQuery()
                ->getResult();
            foreach ($risks as $risk) {
                /** @var Risk $risk */
                $items[] = [
                    'tone'      => 'warning',
                    'icon'      => 'fa-icon--status-warning',
                    'title'     => 'Risk · ' . ($risk->getTitle() ?? '—'),
                    'subtitle'  => method_exists($risk, 'getStatus')
                        ? (is_object($risk->getStatus()) ? $risk->getStatus()->value : (string) $risk->getStatus())
                        : '',
                    'link'      => $this->safeUrl('app_risk_show', ['id' => $risk->getId()]),
                    'timestamp' => method_exists($risk, 'getUpdatedAt') ? $risk->getUpdatedAt() : null,
                    'actor'     => '—',
                    'source'    => 'risk',
                ];
            }
        }

        // Sort by timestamp desc
        usort($items, static function (array $a, array $b): int {
            $ta = $a['timestamp'] instanceof DateTimeInterface ? $a['timestamp']->getTimestamp() : 0;
            $tb = $b['timestamp'] instanceof DateTimeInterface ? $b['timestamp']->getTimestamp() : 0;
            return $tb <=> $ta;
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * V4-EF-6: Determine whether an audit-log entry is compliance-relevant.
     * Matches on entity type (Document, ComplianceFramework, …) or action prefix
     * (approve, reject, compliance*).
     */
    private function isComplianceAuditLog(string $entityType, string $action): bool
    {
        // Strip namespace prefix to get the short class name.
        $shortType = (string) preg_replace('#^.*\\\\#', '', $entityType);
        foreach (self::COMPLIANCE_ENTITY_TYPES as $type) {
            if (strcasecmp($shortType, $type) === 0) {
                return true;
            }
        }
        $lAction = strtolower($action);
        foreach (self::COMPLIANCE_ACTION_PREFIXES as $prefix) {
            if (str_starts_with($lAction, $prefix)) {
                return true;
            }
        }
        return false;
    }

    /**
     * V4-EF-6: Determine whether a workflow is compliance-relevant based on the
     * entity type it operates on (e.g. Document approval workflow).
     */
    private function isComplianceWorkflow(string $entityType): bool
    {
        $shortType = (string) preg_replace('#^.*\\\\#', '', $entityType);
        foreach (self::COMPLIANCE_ENTITY_TYPES as $type) {
            if (strcasecmp($shortType, $type) === 0) {
                return true;
            }
        }
        return false;
    }

    private function toneForAction(string $action): string
    {
        return match (true) {
            str_contains($action, 'delete') || str_contains($action, 'reject') => 'danger',
            str_contains($action, 'approve') || str_contains($action, 'create') => 'success',
            str_contains($action, 'update') || str_contains($action, 'edit') => 'warning',
            default => 'neutral',
        };
    }

    private function iconForAction(string $action): string
    {
        return match (true) {
            str_contains($action, 'delete')  => 'fa-icon--util-trash',
            str_contains($action, 'create')  => 'fa-icon--ui-plus',
            str_contains($action, 'update') || str_contains($action, 'edit') => 'fa-icon--ui-edit',
            str_contains($action, 'approve') => 'fa-icon--ui-check',
            str_contains($action, 'reject')  => 'fa-icon--ui-close',
            default                           => 'fa-icon--ui-circle',
        };
    }
}
