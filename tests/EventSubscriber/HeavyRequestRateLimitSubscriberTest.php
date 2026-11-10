<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\Entity\User;
use App\EventSubscriber\HeavyRequestRateLimitSubscriber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;

/**
 * S31: exports, PDFs, previews, analytics data and recomputations count against a
 * budget per logged-in person; job polling, downloads of finished jobs and normal
 * pages do not.
 */
final class HeavyRequestRateLimitSubscriberTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function routes(): iterable
    {
        yield 'compliance export' => ['app_compliance_export_gaps_pdf', true];
        yield 'risk excel' => ['app_risk_export_excel', true];
        yield 'bulk export' => ['app_asset_bulk_export', true];
        yield 'management pdf' => ['app_management_reports_risk_pdf', true];
        yield 'report preview' => ['report_builder_preview', true];
        yield 'report pdf' => ['report_builder_export_pdf', true];
        yield 'analytics trends' => ['app_analytics_trends_data', true];
        yield 'mapping recompute' => ['admin_mapping_quality_recompute', true];
        yield 'job status' => ['admin_job_status', false];
        yield 'job progress page' => ['admin_job_progress_page', false];
        yield 'finished export download' => ['app_risk_export_download', false];
        yield 'single widget' => ['report_builder_widget_data', false];
        yield 'risk list' => ['app_risk_index', false];
        yield 'dashboard' => ['app_dashboard', false];
    }

    #[Test]
    #[DataProvider('routes')]
    public function routeIsCountedWhenHeavy(string $route, bool $heavy): void
    {
        self::assertSame($heavy, HeavyRequestRateLimitSubscriber::isHeavy($route));
    }

    #[Test]
    public function exhaustedBudgetAnswers429PerPerson(): void
    {
        $alice = (new User())->setEmail('alice@example.test');
        $bob = (new User())->setEmail('bob@example.test');
        $limiter = new RateLimiterFactory(
            ['id' => 'heavy', 'policy' => 'sliding_window', 'limit' => 2, 'interval' => '1 minute'],
            new InMemoryStorage(),
        );

        self::assertNull($this->hit($limiter, $alice, 'app_risk_export_excel'));
        self::assertNull($this->hit($limiter, $alice, 'report_builder_preview'));
        self::assertSame(429, $this->hit($limiter, $alice, 'app_analytics_trends_data'));
        self::assertNull($this->hit($limiter, $alice, 'app_risk_index'), 'normal pages are not counted');
        self::assertNull($this->hit($limiter, $bob, 'app_risk_export_excel'), 'each person has an own budget');
        self::assertNull($this->hit($limiter, null, 'app_risk_export_excel'), 'no person, nothing to key on (the firewall decides)');
    }

    private function hit(RateLimiterFactory $limiter, ?User $user, string $route): ?int
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);
        $request = Request::create('/de/x');
        $request->attributes->set('_route', $route);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        (new HeavyRequestRateLimitSubscriber($limiter, $security))->onKernelRequest($event);

        return $event->getResponse()?->getStatusCode();
    }
}
