<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\DataTransferAuditSubscriber;
use App\Service\AuditLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Backup create / download / upload / restore / delete and the raw data export
 * only wrote to the PSR log, never to the audit log.
 */
final class DataTransferAuditSubscriberTest extends TestCase
{
    #[Test]
    public function restoreIsAuditedWithoutThePassword(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('logCustom')->with(
            'backup.restore',
            'Backup',
            null,
            null,
            self::callback(static function (array $details): bool {
                return $details['filename'] === 'backup_x.zip'
                    && $details['admin_password_set'] === true
                    && !in_array('secret123', $details, true);
            }),
        );

        $request = Request::create('/admin/data/backup/restore/backup_x.zip', 'POST', ['admin_password' => 'secret123']);
        $request->attributes->set('_route', 'data_backup_restore');
        $request->attributes->set('filename', 'backup_x.zip');

        (new DataTransferAuditSubscriber($auditLogger, new NullLogger()))->onResponse($this->event($request, 200));
    }

    #[Test]
    public function refusedRequestsAndOtherRoutesAreNotAudited(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('logCustom');
        $subscriber = new DataTransferAuditSubscriber($auditLogger, new NullLogger());

        $denied = Request::create('/admin/data/backup/download/x.zip');
        $denied->attributes->set('_route', 'data_backup_download');
        $subscriber->onResponse($this->event($denied, 404));

        $other = Request::create('/admin/data/backup');
        $other->attributes->set('_route', 'data_backup_index');
        $subscriber->onResponse($this->event($other, 200));
    }

    #[Test]
    public function everyAuditedRouteExists(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/Controller/AdminBackupController.php');
        foreach (array_keys(DataTransferAuditSubscriber::AUDITED_ROUTES) as $route) {
            self::assertStringContainsString("name: '" . $route . "'", $source, $route);
        }
    }

    private function event(Request $request, int $status): ResponseEvent
    {
        return new ResponseEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MAIN_REQUEST,
            new Response('', $status),
        );
    }
}
