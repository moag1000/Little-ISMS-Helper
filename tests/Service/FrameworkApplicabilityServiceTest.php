<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\Catalog\FrameworkCode;
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
}
