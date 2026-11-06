<?php

declare(strict_types=1);

namespace App\Tests\Service\Compliance;

use App\Controller\ComplianceMappingSeedController;
use App\Service\Compliance\MappingSeedService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The async post-setup seeding (MappingSeedService) and the manual seed page
 * (ComplianceMappingSeedController) must offer the same curated pairs.
 */
final class MappingSeedServicePairsTest extends TestCase
{
    #[Test]
    public function serviceSeedsEveryPairTheSeedPageOffers(): void
    {
        $controller = (new \ReflectionClass(ComplianceMappingSeedController::class))->newInstanceWithoutConstructor();
        $seeds = (new \ReflectionMethod($controller, 'seeds'))->invoke($controller);

        $fromController = array_map(
            static fn (array $s): string => $s['source_code'] . '>' . $s['target_code'] . '@' . $s['command'],
            $seeds,
        );
        $fromService = array_map(
            static fn (array $p): string => $p['source'] . '>' . $p['target'] . '@' . $p['command'],
            MappingSeedService::PAIRS,
        );
        sort($fromController);
        sort($fromService);

        self::assertSame($fromController, $fromService);
    }
}
