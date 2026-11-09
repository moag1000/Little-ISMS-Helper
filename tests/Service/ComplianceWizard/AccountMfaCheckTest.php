<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard;

use App\Entity\MfaToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ComplianceWizard\AccountMfaCheck;
use App\Service\Responsibility\ResponsibilityResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** NIS-2 Art. 21 (2) j: the tool's own privileged accounts as a second signal beside control 8.5. */
final class AccountMfaCheckTest extends TestCase
{
    #[Test]
    public function shareOfLocalAdminAccountsWithASecondFactor(): void
    {
        $withMfa = $this->user('local');
        $withMfa->addMfaToken(new MfaToken());
        $result = $this->check([$withMfa, $this->user('local'), $this->user('azure')]);

        self::assertSame(50.0, $result['score']);
        self::assertSame(1, $result['details']['sso'], 'SSO accounts leave the factor to the identity provider');
        self::assertSame('wizard.gap.accounts_without_mfa', $result['gap']['title'] ?? null);
    }

    #[Test]
    public function securityOfficersCountAsPrivileged(): void
    {
        $resolver = $this->createMock(ResponsibilityResolver::class);
        $resolver->expects(self::once())->method('usersReaching')
            ->with(self::isInstanceOf(Tenant::class), 'ROLE_ADMIN', 'ROLE_CISO', 'ROLE_ISB')
            ->willReturn([]);

        (new AccountMfaCheck($resolver))->check(['type' => 'account_mfa'], new Tenant());
    }

    #[Test]
    public function noLocalAdminAccountIsNoData(): void
    {
        $result = $this->check([$this->user('azure')]);

        self::assertSame(0.0, $result['score']);
        self::assertSame('no_data', $result['details']['reason']);
    }

    /** @param list<User> $admins */
    private function check(array $admins): array
    {
        $resolver = $this->createStub(ResponsibilityResolver::class);
        $resolver->method('usersReaching')->willReturn($admins);

        return (new AccountMfaCheck($resolver))->check(['type' => 'account_mfa'], new Tenant());
    }

    private function user(string $provider): User
    {
        return (new User())->setAuthProvider($provider)->setIsActive(true);
    }
}
