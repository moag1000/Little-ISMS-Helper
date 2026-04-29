<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\IdentityProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IdentityProviderTest extends TestCase
{
    #[Test]
    public function emailDomainMatchingHandlesAtPrefix(): void
    {
        $p = (new IdentityProvider())
            ->setSlug('acme')
            ->setName('Acme')
            ->setClientId('id')
            ->setDomainBindings(['acme.com', '@acme.de']);

        self::assertTrue($p->matchesEmailDomain('alice@acme.com'));
        self::assertTrue($p->matchesEmailDomain('bob@ACME.de'));
        self::assertFalse($p->matchesEmailDomain('charlie@other.com'));
        self::assertFalse($p->matchesEmailDomain(null));
    }

    #[Test]
    public function emptyBindingMatchesUnlessEnforced(): void
    {
        $p = (new IdentityProvider())
            ->setSlug('any')
            ->setName('Any')
            ->setClientId('id')
            ->setDomainBindings([]);

        $p->setDomainBindingMode(IdentityProvider::DOMAIN_MODE_OPTIONAL);
        self::assertTrue($p->matchesEmailDomain('alice@x.com'));

        $p->setDomainBindingMode(IdentityProvider::DOMAIN_MODE_ENFORCE);
        self::assertFalse($p->matchesEmailDomain('alice@x.com'));
    }

    #[Test]
    public function isGlobalReflectsTenantAbsence(): void
    {
        $p = new IdentityProvider();
        self::assertTrue($p->isGlobal());
    }
}
