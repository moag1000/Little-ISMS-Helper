<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Repository\ComplianceFrameworkRepository;
use App\Service\ComplianceWizard\CategoryProvider\IsoFrameworkCategoryProvider;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The fulfilment rule derives ISO 27001 requirements from the wizard checks by
 * their clause number, so the numbers must follow ISO/IEC 27001:2022
 * (10.1 continual improvement, 10.2 nonconformity and corrective action),
 * not the 2013 order.
 */
#[AllowMockObjectsWithoutExpectations]
final class IsoClauseNumberingTest extends TestCase
{
    #[Test]
    public function clauseTenFollowsTheEdition2022(): void
    {
        $provider = new IsoFrameworkCategoryProvider(
            $this->createMock(TenantContext::class),
            $this->createMock(ComplianceFrameworkRepository::class),
        );
        $checks = $provider->getIso27001Categories()['improvement']['checks'];

        self::assertSame('10.2', $checks['nonconformity']['clause']);
        self::assertSame('wizard.check.iso_10_2_nonconformity', $checks['nonconformity']['name']);
        self::assertSame('10.1', $checks['continual_improvement']['clause']);
        self::assertSame('wizard.check.iso_10_1_improvement', $checks['continual_improvement']['name']);
    }
}
