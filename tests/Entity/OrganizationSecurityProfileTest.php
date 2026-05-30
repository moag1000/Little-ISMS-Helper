<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\OrganizationSecurityProfile;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class OrganizationSecurityProfileTest extends TestCase
{
    #[Test]
    public function it_stores_and_returns_parameter_values(): void
    {
        $profile = new OrganizationSecurityProfile();
        $profile->setValue('mfa_scope', 'all');

        self::assertSame('all', $profile->getValue('mfa_scope'));
        self::assertNull($profile->getValue('unset_param'));
        self::assertSame(['mfa_scope' => 'all'], $profile->getValues());
    }

    #[Test]
    public function it_stores_org_context_flags(): void
    {
        $profile = new OrganizationSecurityProfile();
        $profile->setFlag('has_works_council', true);

        self::assertTrue($profile->getFlag('has_works_council'));
        self::assertFalse($profile->getFlag('has_dpo'));
    }
}
