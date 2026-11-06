<?php

declare(strict_types=1);

namespace App\Tests\Service\Catalog;

use App\Service\Catalog\RequirementIdPrefixes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RequirementIdPrefixesTest extends TestCase
{
    #[Test]
    public function knownFrameworksReturnTheirLoaderPrefixes(): void
    {
        self::assertSame(['27701', 'ISO27701'], RequirementIdPrefixes::forFramework('ISO27701'));
        self::assertSame(['AIACT', 'EUAIACT', 'EU-AI-ACT'], RequirementIdPrefixes::forFramework('EU-AI-ACT'));
        self::assertContains('BSIG', RequirementIdPrefixes::forFramework('NIS2UMSUCG'));
    }

    #[Test]
    public function aliasCodesShareTheCanonicalPrefixes(): void
    {
        self::assertSame(
            RequirementIdPrefixes::forFramework('NIST-CSF-2.0'),
            RequirementIdPrefixes::forFramework('NIST-CSF'),
        );
        self::assertSame(
            RequirementIdPrefixes::forFramework('ISO-22301'),
            RequirementIdPrefixes::forFramework('ISO22301'),
        );
        self::assertSame(
            RequirementIdPrefixes::forFramework('NIS2UMSUCG'),
            RequirementIdPrefixes::forFramework('NIS2-UmsuCG'),
        );
    }

    #[Test]
    public function unknownCodeFallsBackToCodeAndSeparatorFreeForm(): void
    {
        self::assertSame(['MY-FW', 'MYFW'], RequirementIdPrefixes::forFramework('MY-FW'));
    }
}
