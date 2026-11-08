<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\DownloadAuditSubscriber;
use App\Service\AuditLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Data-breach and DPIA PDFs, the processing register, management reports and a
 * dozen other exports wrote no audit entry. Every download is logged once.
 */
final class DownloadAuditSubscriberTest extends TestCase
{
    #[Test]
    public function aDownloadWithoutOwnEntryIsLogged(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::once())->method('logCustom')->with(
            DownloadAuditSubscriber::ACTION,
            'Export',
            null,
            null,
            ['route' => 'app_data_breach_export_pdf', 'filename' => 'breach-7.pdf', 'content_type' => 'application/pdf'],
        );

        $request = Request::create('/de/data-breach/7/pdf');
        $request->attributes->set('_route', 'app_data_breach_export_pdf');
        $response = new Response('%PDF', 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="breach-7.pdf"',
        ]);

        (new DownloadAuditSubscriber($auditLogger, new NullLogger()))->onResponse($this->event($request, $response));
    }

    #[Test]
    public function controllersWithTheirOwnEntryAndOrdinaryPagesAreNotLoggedTwice(): void
    {
        $auditLogger = $this->createMock(AuditLogger::class);
        $auditLogger->expects(self::never())->method('logCustom');
        $subscriber = new DownloadAuditSubscriber($auditLogger, new NullLogger());

        $logged = Request::create('/de/risk/export');
        $logged->attributes->set(AuditLogger::REQUEST_LOGGED_ATTRIBUTE, true);
        $subscriber->onResponse($this->event($logged, new Response('', 200, ['Content-Disposition' => 'attachment; filename="r.csv"'])));

        $subscriber->onResponse($this->event(Request::create('/de/risk'), new Response('<html>')));
        $subscriber->onResponse($this->event(
            Request::create('/de/profile/avatar'),
            new Response('', 200, ['Content-Type' => 'image/png', 'Content-Disposition' => 'inline; filename="a.png"']),
        ));
        $subscriber->onResponse($this->event(
            Request::create('/de/risk/export'),
            new Response('', 403, ['Content-Disposition' => 'attachment; filename="r.csv"']),
        ));
    }

    #[Test]
    public function readsBothFilenameForms(): void
    {
        self::assertSame('Bericht ä.pdf', DownloadAuditSubscriber::filenameOf("attachment; filename*=UTF-8''Bericht%20%C3%A4.pdf"));
        self::assertSame('x.csv', DownloadAuditSubscriber::filenameOf('attachment; filename="x.csv"'));
        self::assertNull(DownloadAuditSubscriber::filenameOf('attachment'));
    }

    private function event(Request $request, Response $response): ResponseEvent
    {
        return new ResponseEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    }
}
