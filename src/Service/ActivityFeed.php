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
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function recent(?User $user = null, int $limit = 50): array
    {
        $items = [];

        // 1. AuditLog (last 50)
        foreach ($this->auditLogRepo->findAllOrdered($limit, 0) as $log) {
            $items[] = [
                'tone'      => $this->toneForAction($log->getAction() ?? ''),
                'icon'      => $this->iconForAction($log->getAction() ?? ''),
                'title'     => sprintf('%s %s', $log->getAction() ?? '?', $log->getEntityType() ?? ''),
                'subtitle'  => $log->getDescription() ?? '',
                'link'      => null,
                'timestamp' => $log->getCreatedAt(),
                'actor'     => $log->getUserName() ?? '—',
                'source'    => 'audit_log',
            ];
        }

        // 2. WorkflowInstance recent (active)
        foreach (array_slice($this->workflowRepo->findActive(), 0, 25) as $instance) {
            /** @var WorkflowInstance $instance */
            $items[] = [
                'tone'      => $instance->getStatus() === 'rejected' ? 'danger'
                    : ($instance->getStatus() === 'approved' ? 'success' : 'warning'),
                'icon'      => 'fa-icon--ui-circle',
                'title'     => sprintf('Workflow %s — %s',
                    $instance->getWorkflow()?->getName() ?? '?',
                    $instance->getStatus()),
                'subtitle'  => $instance->getCurrentStep()?->getName() ?? '',
                'link'      => '/workflow/instance/' . $instance->getId(),
                'timestamp' => $instance->getStartedAt(),
                'actor'     => $instance->getInitiatedBy() ? trim(($instance->getInitiatedBy()->getFirstName() ?? '') . ' ' . ($instance->getInitiatedBy()->getLastName() ?? '')) : '—',
                'source'    => 'workflow',
            ];
        }

        // 3. Recent Document changes
        $tenant = $this->tenantContext->getCurrentTenant();
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
                    'title'     => sprintf('Document %s · v%s', $doc->getTitle() ?? '—', $doc->getVersion() ?? '—'),
                    'subtitle'  => $doc->getStatus() ?? '',
                    'link'      => '/document/' . $doc->getId(),
                    'timestamp' => method_exists($doc, 'getUpdatedAt') ? $doc->getUpdatedAt() : null,
                    'actor'     => '—',
                    'source'    => 'document',
                ];
            }
        }

        // 4. Recent Risks
        if ($tenant) {
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
                    'link'      => '/risk/' . $risk->getId(),
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
