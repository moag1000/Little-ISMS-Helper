<?php

declare(strict_types=1);

namespace App\Tests\Enum;

use App\Enum\MenuDensity;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MenuDensityTest extends TestCase
{
    #[Test]
    public function itHasThreeCases(): void
    {
        self::assertCount(3, MenuDensity::cases());
    }

    #[Test]
    public function basicValueIsBasic(): void
    {
        self::assertSame('basic', MenuDensity::BASIC->value);
    }

    #[Test]
    public function standardValueIsStandard(): void
    {
        self::assertSame('standard', MenuDensity::STANDARD->value);
    }

    #[Test]
    public function expertValueIsExpert(): void
    {
        self::assertSame('expert', MenuDensity::EXPERT->value);
    }

    #[Test]
    public function tryFromReturnsNullForInvalidValue(): void
    {
        self::assertNull(MenuDensity::tryFrom('invalid'));
    }

    #[Test]
    public function tryFromReturnsEnumCaseForValidValue(): void
    {
        self::assertSame(MenuDensity::EXPERT, MenuDensity::tryFrom('expert'));
    }
}
