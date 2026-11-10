<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\BulkIdsLimitSubscriber;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * S31: a bulk action names at most 500 records, in a JSON body or a form field.
 */
final class BulkIdsLimitSubscriberTest extends TestCase
{
    #[Test]
    public function jsonBodyAboveTheCapIsRefused(): void
    {
        $ids = range(1, BulkIdsLimitSubscriber::MAX_IDS + 1);
        $request = Request::create('/de/risk/bulk-delete', 'POST', content: json_encode(['ids' => $ids]));

        self::assertSame(400, $this->responseStatus($request));
    }

    #[Test]
    public function jsonBodyWithoutContentTypeIsCountedToo(): void
    {
        $request = Request::create('/de/asset/bulk-export', 'POST', server: ['CONTENT_TYPE' => 'text/plain'], content: json_encode(['ids' => range(1, 600)]));

        self::assertSame(400, $this->responseStatus($request));
    }

    #[Test]
    public function formFieldAboveTheCapIsRefused(): void
    {
        $request = Request::create('/de/location-map/x', 'POST', ['ids' => range(1, 501)]);

        self::assertSame(400, $this->responseStatus($request));
    }

    #[Test]
    public function aPageOfIdsAndOtherRequestsPass(): void
    {
        self::assertNull($this->responseStatus(Request::create('/de/risk/bulk-delete', 'POST', content: json_encode(['ids' => range(1, 500)]))));
        self::assertNull($this->responseStatus(Request::create('/de/risk', 'GET', ['ids' => range(1, 900)])), 'GET is not a bulk action');
        self::assertNull($this->responseStatus(Request::create('/de/x', 'POST', content: '{"ids": broken')));
    }

    private function responseStatus(Request $request): ?int
    {
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new BulkIdsLimitSubscriber())->onKernelRequest($event);

        return $event->getResponse()?->getStatusCode();
    }
}
