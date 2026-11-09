<?php

declare(strict_types=1);

namespace App\Tests\Service\Sso;

use App\Service\Sso\SsoClaimPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

/**
 * S14: `email_verified` vouches for the `email` claim only. An account found
 * through another (mapped) claim is linked only when that claim carries the
 * same address — a verified `email` must not vouch for an unverified `upn`.
 */
final class SsoClaimPolicyMatchedEmailTest extends TestCase
{
    #[Test]
    public function verifiedFlagCoversOnlyTheEmailClaim(): void
    {
        $claims = ['email' => 'jane@example.org', 'upn' => 'boss@example.org', 'email_verified' => true];

        self::assertTrue(SsoClaimPolicy::isEmailVerified($claims));
        self::assertTrue(SsoClaimPolicy::isEmailVerified($claims, 'JANE@example.org'));
        self::assertFalse(SsoClaimPolicy::isEmailVerified($claims, 'boss@example.org'));
        self::assertFalse(SsoClaimPolicy::isEmailVerified(['upn' => 'boss@example.org', 'email_verified' => true], 'boss@example.org'));

        $this->expectException(CustomUserMessageAuthenticationException::class);
        SsoClaimPolicy::assertEmailVerifiedForLinking($claims, 'boss@example.org');
    }
}
