<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\IdentityProvider;
use App\Entity\IdentityProviderUserMapping;
use App\Entity\User;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityProviderUserMappingTest extends TestCase
{
    #[Test]
    public function defaultsAreNull(): void
    {
        $m = new IdentityProviderUserMapping();
        self::assertNull($m->getId());
        self::assertNull($m->getIdpClaimsSnapshot());
        self::assertNull($m->getLastSyncedAt());
        self::assertNull($m->getFirstLoggedInAt());
        self::assertSame(0, $m->getSuccessfulLoginCount());
    }

    #[Test]
    public function settersRoundTrip(): void
    {
        $idp  = new IdentityProvider();
        $user = new User();
        $now  = new DateTimeImmutable();
        $m    = new IdentityProviderUserMapping();
        $m->setIdentityProvider($idp);
        $m->setUser($user);
        $m->setIdpUserId('sub-abc-123');
        $m->setIdpClaimsSnapshot(['email' => 'alice@example.com', 'groups' => ['isms-admin']]);
        $m->setLastSyncedAt($now);
        $m->setFirstLoggedInAt($now);

        self::assertSame($idp, $m->getIdentityProvider());
        self::assertSame($user, $m->getUser());
        self::assertSame('sub-abc-123', $m->getIdpUserId());
        self::assertSame(['email' => 'alice@example.com', 'groups' => ['isms-admin']], $m->getIdpClaimsSnapshot());
        self::assertSame($now, $m->getLastSyncedAt());
        self::assertSame($now, $m->getFirstLoggedInAt());
    }

    #[Test]
    public function incrementSuccessfulLoginCountWorks(): void
    {
        $m = new IdentityProviderUserMapping();
        self::assertSame(0, $m->getSuccessfulLoginCount());
        $m->incrementSuccessfulLoginCount();
        $m->incrementSuccessfulLoginCount();
        self::assertSame(2, $m->getSuccessfulLoginCount());
    }

    #[Test]
    public function setSuccessfulLoginCountWorks(): void
    {
        $m = new IdentityProviderUserMapping();
        $m->setSuccessfulLoginCount(42);
        self::assertSame(42, $m->getSuccessfulLoginCount());
    }
}
