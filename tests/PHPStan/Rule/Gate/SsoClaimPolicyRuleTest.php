<?php

declare(strict_types=1);

namespace App\Tests\PHPStan\Rule\Gate;

use PHPStan\Rules\Rule;
use PHPUnit\Framework\Attributes\Test;

/**
 * Gate 93 (PHP part): SSO authenticators call SsoClaimPolicy themselves (point 1 of T3: Azure).
 *
 * @extends GateRuleTestCase<SsoClaimPolicyRule>
 */
final class SsoClaimPolicyRuleTest extends GateRuleTestCase
{
    protected function getRule(): Rule
    {
        return new SsoClaimPolicyRule([
            'App\\Security\\GateFixture\\GoodAuthenticator' => ['policy' => ['allowedAzureTenants', 'assertAzureTenant']],
            'App\\Security\\GateFixture\\CommentOnlyAuthenticator' => ['policy' => ['allowedAzureTenants', 'assertAzureTenant']],
            'App\\Security\\GateFixture\\FlowAuthenticator' => ['flow' => ['App\\Security\\GateFixture\\FixtureFlow', 'handleCallback']],
            'App\\Security\\GateFixture\\FlowSkippedAuthenticator' => ['flow' => ['App\\Security\\GateFixture\\FixtureFlow', 'handleCallback']],
            'App\\Security\\GateFixture\\BareCheckAuthenticator' => ['policy' => ['isEmailVerified']],
            'App\\Security\\GateFixture\\DeadCheckAuthenticator' => ['policy' => ['isEmailVerified']],
            'App\\Security\\GateFixture\\UsedCheckAuthenticator' => ['policy' => ['isEmailVerified']],
            'App\\Security\\GateFixture\\MergingAuthenticator' => [],
        ]);
    }

    #[Test]
    public function authenticatorsCallThePolicyThemselves(): void
    {
        $this->assertFixture('src/Security/GateFixture/SsoAuthenticators.php');
    }

    #[Test]
    public function claimsAreNotMergedByHand(): void
    {
        $this->assertFixture('src/Service/Sso/GateFixtureClaims.php');
    }

    /** The real map names classes that exist, so a renamed authenticator cannot drop out of the gate. */
    #[Test]
    public function everyListedClassExists(): void
    {
        foreach (array_keys(SsoClaimPolicyRule::CLAIM_CHECKS) as $class) {
            self::assertTrue(class_exists($class), $class);
        }
    }
}
