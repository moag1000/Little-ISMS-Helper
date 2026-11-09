<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AuditLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Writes the audit-log entry for every backup, restore and raw data transfer
 * (ISO 27001 A.8.13 / Cl. 7.5.3). These endpoints only wrote to the PSR log:
 * an organisation backup could be downloaded, uploaded, deleted or restored —
 * including the "set admin password" option — without a trace in the audit log.
 *
 * One place, keyed by route, so a new endpoint of the same kind is added here
 * with one line and the controllers stay as they are. The password itself is
 * never logged, only whether one was given.
 */
final class DataTransferAuditSubscriber implements EventSubscriberInterface
{
    /** Route → audit action. */
    public const array AUDITED_ROUTES = [
        'data_backup_create'   => 'backup.create',
        'data_backup_download' => 'backup.download',
        'data_backup_upload'   => 'backup.upload',
        'data_backup_restore'  => 'backup.restore',
        'data_backup_delete'   => 'backup.delete',
        'data_export_execute'  => 'data.export',
    ];

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::RESPONSE => ['onResponse', -10]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $action = self::AUDITED_ROUTES[(string) $request->attributes->get('_route', '')] ?? null;
        if ($action === null || $event->getResponse()->getStatusCode() >= 400) {
            return;
        }

        $details = array_filter([
            'filename'             => $request->attributes->get('filename'),
            'tenant_id'            => $request->request->get('tenant_id'),
            'dry_run'              => $request->request->has('dry_run') ? $request->request->getBoolean('dry_run') : null,
            'clear_before_restore' => $request->request->has('clear_before_restore')
                ? $request->request->getBoolean('clear_before_restore') : null,
            'admin_password_set'   => $action === 'backup.restore'
                ? (string) $request->request->get('admin_password', '') !== '' : null,
            'entity'               => $request->request->get('entity'),
            'status'               => $event->getResponse()->getStatusCode(),
        ], static fn (mixed $v): bool => $v !== null && $v !== '');

        try {
            $this->auditLogger->logCustom($action, 'Backup', null, null, $details);
        } catch (\Throwable $e) {
            // Never break the transfer itself; the PSR log still has the event.
            $this->logger->error('Audit log for data transfer failed', ['action' => $action, 'error' => $e->getMessage()]);
        }
    }
}
