<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderRoleMapping;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityProviderRoleMappingTest extends TestCase
{
    #[Test]
    public function defaultsAreCorrect(): void
    {
        $m = new IdentityProviderRoleMapping();
        self::assertTrue($m->isActive());
        self::assertSame(0, $m->getPriority());
        self::assertSame([], $m->getAssignedPermissions());
        self::assertNull($m->getId());
        self::assertSame('ROLE_USER', $m->getAssignedRole());
    }

    #[Test]
    public function settersRoundTrip(): void
    {
        $idp = new IdentityProvider();
        $m = new IdentityProviderRoleMapping();
        $m->setIdentityProvider($idp);
        $m->setClaimKey('groups');
        $m->setClaimValueExpression('isms-admin');
        $m->setAssignedRole('ROLE_ADMIN');
        $m->setAssignedPermissions(['perm_risk_write']);
        $m->setPriority(10);
        $m->setIsActive(false);
        $m->setAuditDescription('Test mapping');

        self::assertSame($idp, $m->getIdentityProvider());
        self::assertSame('groups', $m->getClaimKey());
        self::assertSame('isms-admin', $m->getClaimValueExpression());
        self::assertSame('ROLE_ADMIN', $m->getAssignedRole());
        self::assertSame(['perm_risk_write'], $m->getAssignedPermissions());
        self::assertSame(10, $m->getPriority());
        self::assertFalse($m->isActive());
        self::assertSame('Test mapping', $m->getAuditDescription());
    }

    #[Test]
    public function identityProviderCanAddAndRemoveRoleMappings(): void
    {
        $idp = new IdentityProvider();
        $m   = new IdentityProviderRoleMapping();
        $idp->addRoleMapping($m);
        self::assertSame($idp, $m->getIdentityProvider());
        self::assertCount(1, $idp->getRoleMappings());

        $idp->removeRoleMapping($m);
        self::assertCount(0, $idp->getRoleMappings());
    }

    #[Test]
    public function matchesExactString(): void
    {
        $m = new IdentityProviderRoleMapping();
        $m->setClaimKey('department');
        $m->setClaimValueExpression('security');
        $m->setAssignedRole('ROLE_ADMIN');

        self::assertTrue($m->matches('security'));
        self::assertFalse($m->matches('marketing'));
    }

    #[Test]
    public function matchesGlobPattern(): void
    {
        $m = new IdentityProviderRoleMapping();
        $m->setClaimKey('groups');
        $m->setClaimValueExpression('isms-*');
        $m->setAssignedRole('ROLE_MANAGER');

        self::assertTrue($m->matches('isms-auditor'));
        self::assertTrue($m->matches('isms-admin'));
        self::assertFalse($m->matches('other-group'));
    }

    #[Test]
    public function matchesArrayClaimValue(): void
    {
        $m = new IdentityProviderRoleMapping();
        $m->setClaimKey('groups');
        $m->setClaimValueExpression('isms-admin');
        $m->setAssignedRole('ROLE_ADMIN');

        self::assertTrue($m->matches(['viewer', 'isms-admin', 'other']));
        self::assertFalse($m->matches(['viewer', 'other']));
    }

    #[Test]
    public function inactiveRuleNeverMatches(): void
    {
        $m = new IdentityProviderRoleMapping();
        $m->setClaimKey('groups');
        $m->setClaimValueExpression('isms-admin');
        $m->setAssignedRole('ROLE_ADMIN');
        $m->setIsActive(false);

        self::assertFalse($m->matches('isms-admin'));
    }
}
