<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Catalog\FrameworkCode;
use App\Entity\Tenant;
use App\Service\FrameworkApplicabilityService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FrameworkApplicabilityServiceTest extends TestCase
{
    /**
     * @return array<string, array{bucket: string, reason_key: string}>
     */
    private function classify(string $country = 'DE', bool $aiHighRisk = false): array
    {
        return (new FrameworkApplicabilityService())->classifyAll(['it_services'], '51-250', $country, $aiHighRisk);
    }

    #[Test]
    public function everyClassifiedCodeIsACanonicalFrameworkCode(): void
    {
        foreach (array_keys($this->classify()) as $code) {
            self::assertTrue(FrameworkCode::isCanonical($code), sprintf('"%s" is not a canonical framework code.', $code));
        }
    }

    #[Test]
    public function everyCanonicalFrameworkExceptLoaderOnlyEucsIsClassified(): void
    {
        $classified = array_keys($this->classify());
        $missing = array_diff(FrameworkCode::CANONICAL, $classified, ['EUCS']);

        self::assertSame([], array_values($missing));
    }

    #[Test]
    public function dachFrameworksFollowTheCountry(): void
    {
        self::assertSame(FrameworkApplicabilityService::BUCKET_RECOMMENDED, $this->classify('CH')['REVDSG-CH']['bucket']);
        self::assertSame(FrameworkApplicabilityService::BUCKET_OPTIONAL, $this->classify('DE')['REVDSG-CH']['bucket']);
        self::assertSame(FrameworkApplicabilityService::BUCKET_RECOMMENDED, $this->classify('AT')['NISG-AT']['bucket']);
    }

    #[Test]
    public function nis2CategoryFollowsSectorAndSize(): void
    {
        $service = new FrameworkApplicabilityService();

        self::assertSame(Tenant::NIS2_ESSENTIAL, $service->nis2Category('energy', 300, null));
        self::assertSame(Tenant::NIS2_ESSENTIAL, $service->nis2Category('energy', 20, 60_000_000.0));
        self::assertSame(Tenant::NIS2_IMPORTANT, $service->nis2Category('energy', 80, null));
        self::assertSame(Tenant::NIS2_IMPORTANT, $service->nis2Category('manufacturing', 900, null), 'Annex II is never essential');
        self::assertSame(Tenant::NIS2_NOT_REGULATED, $service->nis2Category('food', 30, 5_000_000.0));
        self::assertSame(Tenant::NIS2_NOT_REGULATED, $service->nis2Category('other', 5000, null));
        self::assertSame(Tenant::NIS2_ESSENTIAL, $service->nis2Category('public_administration', 10, null));
    }
}
