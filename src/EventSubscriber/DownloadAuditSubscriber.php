<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\AuditLogger;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Every file the application hands out leaves a trace in the audit log
 * (ISO 27001 A.8.15, GDPR Art. 5(2) accountability).
 *
 * The sweep of 2026-11 found a dozen exports without any audit entry: data-breach
 * and DPIA PDFs, the record of processing activities, management and portfolio
 * reports, the SoA snapshot, risk and analytics exports, the Konzern one-pager.
 * Instead of one call per controller, this subscriber writes `export.download`
 * for any response with a Content-Disposition header (not images) whose request
 * did not write an audit entry already — controllers with their own, more
 * specific entry (logExport, logView, logBulk) are not logged twice
 * (AuditLogger::REQUEST_LOGGED_ATTRIBUTE). A new export is covered without code.
 */
final class DownloadAuditSubscriber implements EventSubscriberInterface
{
    public const string ACTION = 'export.download';

    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // After DataTransferAuditSubscriber (-10), so its specific entry wins.
        return [KernelEvents::RESPONSE => ['onResponse', -20]];
    }

    public function onResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $response = $event->getResponse();
        $disposition = (string) $response->headers->get('Content-Disposition', '');
        if ($disposition === ''
            || $response->getStatusCode() !== 200
            || str_starts_with((string) $response->headers->get('Content-Type', ''), 'image/')
            || $request->attributes->get(AuditLogger::REQUEST_LOGGED_ATTRIBUTE) === true
        ) {
            return;
        }

        $details = array_filter([
            'route'        => $request->attributes->get('_route'),
            'filename'     => self::filenameOf($disposition),
            'content_type' => $response->headers->get('Content-Type'),
        ], static fn (mixed $v): bool => is_string($v) && $v !== '');

        try {
            $this->auditLogger->logCustom(self::ACTION, 'Export', null, null, $details);
        } catch (\Throwable $e) {
            // Never break the download itself.
            $this->logger->error('Audit log for download failed', ['error' => $e->getMessage()] + $details);
        }
    }

    public static function filenameOf(string $disposition): ?string
    {
        if (preg_match("/filename\\*=(?:UTF-8'')?([^;]+)/i", $disposition, $m) === 1) {
            return rawurldecode(trim($m[1], " \"'"));
        }
        if (preg_match('/filename="?([^";]+)"?/i', $disposition, $m) === 1) {
            return trim($m[1]);
        }

        return null;
    }
}
