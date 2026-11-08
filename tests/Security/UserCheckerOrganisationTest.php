<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Tenant;
use App\Entity\User;
use App\Security\UserChecker;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\DisabledException;

/**
 * W3: a deactivated organisation shuts out its members on every way in
 * (form, SSO, remember-me, API token — all firewalls carry the UserChecker,
 * see ApiTokenBoundaryTest), except the instance operator.
 */
final class UserCheckerOrganisationTest extends TestCase
{
    private function member(bool $orgActive, array $roles = ['ROLE_ADMIN']): User
    {
        $tenant = (new Tenant())->setName('Org')->setCode('org');
        $tenant->setIsActive($orgActive);

        return (new User())->setEmail('a@example.com')->setRoles($roles)->setIsActive(true)->setTenant($tenant);
    }

    #[Test]
    public function aMemberOfADeactivatedOrganisationIsRefused(): void
    {
        $this->expectException(DisabledException::class);
        $this->expectExceptionMessage('Organisation is disabled.');

        (new UserChecker())->checkPreAuth($this->member(false));
    }

    #[Test]
    public function anActiveOrganisationLetsItsMembersIn(): void
    {
        (new UserChecker())->checkPreAuth($this->member(true));
        (new UserChecker())->checkPostAuth($this->member(true));

        $this->addToAssertionCount(2);
    }

    #[Test]
    public function theInstanceOperatorKeepsAccessToReactivateIt(): void
    {
        self::assertNull(UserChecker::refusal($this->member(false, ['ROLE_SUPER_ADMIN'])));
    }

    #[Test]
    public function aDeactivatedAccountIsStillRefusedFirst(): void
    {
        $user = $this->member(true)->setIsActive(false);

        self::assertSame('Account is disabled.', UserChecker::refusal($user));
    }
}
