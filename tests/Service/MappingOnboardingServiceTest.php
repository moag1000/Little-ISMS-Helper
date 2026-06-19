<?php
declare(strict_types=1);
namespace App\Tests\Service;

use App\Service\MappingOnboardingService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MappingOnboardingServiceTest extends TestCase
{
    private MappingOnboardingService $svc;

    protected function setUp(): void
    {
        $this->svc = new MappingOnboardingService();
    }

    #[Test]
    public function four_steps_in_order(): void
    {
        self::assertSame(['laden', 'reviewen', 'mappen', 'wiederverwenden'], MappingOnboardingService::STEP_IDS);
    }

    #[Test]
    public function laden_complete_only_on_count_increase(): void
    {
        $snap = ['startedAt' => '2026-06-17T10:00:00+00:00', 'mappingCount' => 5];
        self::assertFalse($this->svc->isStepCompleteFrom('laden', $snap, ['mappingCount' => 5, 'latestReviewedAt' => null, 'latestCreatedAt' => null, 'signals' => []]));
        self::assertTrue($this->svc->isStepCompleteFrom('laden', $snap, ['mappingCount' => 6, 'latestReviewedAt' => null, 'latestCreatedAt' => null, 'signals' => []]));
    }

    #[Test]
    public function reviewen_complete_only_on_review_after_start(): void
    {
        $snap = ['startedAt' => '2026-06-17T10:00:00+00:00', 'mappingCount' => 5];
        self::assertFalse($this->svc->isStepCompleteFrom('reviewen', $snap, ['mappingCount' => 5, 'latestReviewedAt' => '2026-06-17T09:00:00+00:00', 'latestCreatedAt' => null, 'signals' => []]));
        self::assertTrue($this->svc->isStepCompleteFrom('reviewen', $snap, ['mappingCount' => 5, 'latestReviewedAt' => '2026-06-17T10:30:00+00:00', 'latestCreatedAt' => null, 'signals' => []]));
    }

    #[Test]
    public function mappen_complete_only_on_create_after_start(): void
    {
        $snap = ['startedAt' => '2026-06-17T10:00:00+00:00', 'mappingCount' => 5];
        self::assertTrue($this->svc->isStepCompleteFrom('mappen', $snap, ['mappingCount' => 5, 'latestReviewedAt' => null, 'latestCreatedAt' => '2026-06-17T10:30:00+00:00', 'signals' => []]));
        self::assertFalse($this->svc->isStepCompleteFrom('mappen', $snap, ['mappingCount' => 5, 'latestReviewedAt' => null, 'latestCreatedAt' => '2026-06-16T10:30:00+00:00', 'signals' => []]));
    }

    #[Test]
    public function wiederverwenden_complete_only_on_signal(): void
    {
        $snap = ['startedAt' => '2026-06-17T10:00:00+00:00', 'mappingCount' => 5];
        self::assertFalse($this->svc->isStepCompleteFrom('wiederverwenden', $snap, ['mappingCount' => 5, 'latestReviewedAt' => null, 'latestCreatedAt' => null, 'signals' => []]));
        self::assertTrue($this->svc->isStepCompleteFrom('wiederverwenden', $snap, ['mappingCount' => 5, 'latestReviewedAt' => null, 'latestCreatedAt' => null, 'signals' => ['crossFrameworkSeen' => true]]));
    }
}
