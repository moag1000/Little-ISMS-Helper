<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\ComplianceRequirement;
use App\Repository\ComplianceMappingRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * ComplianceRequirement::getFulfillmentPercentage() returns a float, but the
 * root-cause analysis compared it with `=== 0`: requirements without any
 * implemented control fell into no bucket at all, and their categories were
 * labelled missing_control instead of not_started.
 */
final class ComplianceMappingRootCauseAnalysisTest extends TestCase
{
    #[Test]
    public function untouchedGapsAreReportedAsNotStarted(): void
    {
        $repository = (new \ReflectionClass(ComplianceMappingRepository::class))
            ->newInstanceWithoutConstructor();

        $gap = (new ComplianceRequirement())
            ->setRequirementId('A.5.1')
            ->setTitle('Policies')
            ->setCategory('Organizational')
            ->setPriority('high');

        $analysis = $repository->analyzeGapRootCauses([$gap]);

        self::assertCount(1, $analysis['root_causes']['not_started']);
        self::assertSame(1, $analysis['summary']['not_started_count']);
        self::assertSame(
            'not_started',
            $analysis['category_patterns']['Organizational']['dominant_root_cause'],
        );
    }
}
