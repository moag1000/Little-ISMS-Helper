<?php

declare(strict_types=1);

namespace App\Tests\Service\Register;

use App\Enum\RegisterType;
use App\Service\Register\RegisterLinkTypes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RegisterTypeTest extends TestCase
{
    #[Test]
    public function everyTypeNamesItsNormAndMaintainer(): void
    {
        foreach (RegisterType::cases() as $type) {
            self::assertNotSame('', $type->normReference(), $type->value);
            self::assertStringStartsWith('ROLE_', $type->maintainerRole(), $type->value);
            self::assertGreaterThan(0, $type->reviewMonths(), $type->value);
        }
    }

    #[Test]
    public function everyLinkFieldIsAKnownLinkKind(): void
    {
        foreach (RegisterType::cases() as $type) {
            foreach ($type->linkFields() as $field) {
                self::assertNotSame('', RegisterLinkTypes::classOf($field), $type->value . '.' . $field);
            }
        }
    }

    #[Test]
    public function categoriesOnlyForTypesThatUseThem(): void
    {
        foreach (RegisterType::cases() as $type) {
            if (!$type->uses('category')) {
                self::assertSame([], $type->categories(), $type->value);
            }
        }
    }

    #[Test]
    public function communicationIsReviewedQuarterlyAndLegalByCompliance(): void
    {
        self::assertSame(3, RegisterType::COMMUNICATION->reviewMonths());
        self::assertSame('ROLE_COMPLIANCE_MANAGER', RegisterType::LEGAL_REQUIREMENT->maintainerRole());
        self::assertSame('register.type.legal_requirement.field.reference', RegisterType::LEGAL_REQUIREMENT->labelKey('reference'));
        self::assertSame('register.field.frequency', RegisterType::COMMUNICATION->labelKey('frequency'));
    }
}
