<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Document;
use App\Entity\DocumentVersion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\DocumentVersionVoter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

/**
 * F4 — DocumentVersion immutability: confirm DELETE is blocked at Voter level.
 *
 * This test exercises the DocumentVersionVoter directly to verify that:
 *  1. VIEW is granted for same-tenant users.
 *  2. DOWNLOAD is granted for same-tenant users.
 *  3. DELETE is ALWAYS denied, regardless of role.
 *
 * Uses pure PHPUnit TestCase (no kernel boot) — the voter has no framework
 * dependencies and the isolation makes the test faster and more reliable.
 */
class DocumentVersionImmutabilityTest extends TestCase
{

    #[Test]
    public function testDeleteIsAlwaysDeniedByVoter(): void
    {
        $voter = new DocumentVersionVoter();

        $tenant = new Tenant();
        $tenant->setName('Immutability Test Tenant');

        $user = new User();
        $user->setRoles(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN']);
        $user->setTenant($tenant);
        $user->setEmail('admin@test.com');

        $version = new DocumentVersion();
        $version->setTenant($tenant);
        $version->setPublishedAt(new DateTimeImmutable());

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        // DELETE must be denied — voter returns VOTE_DENY (false)
        $result = $voter->vote($token, $version, [DocumentVersionVoter::DELETE]);

        // Voter::VOTE_DENY = -1
        self::assertSame(-1, $result, 'DocumentVersionVoter must deny DELETE on published DocumentVersion.');
    }

    #[Test]
    public function testViewIsGrantedForSameTenantUser(): void
    {
        $voter = new DocumentVersionVoter();

        $tenant = new Tenant();
        $tenant->setName('View Test Tenant');
        // Assign ID via reflection (no DB needed)
        $ref = new \ReflectionClass($tenant);
        $prop = $ref->getProperty('id');

        $prop->setValue($tenant, 1);

        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setTenant($tenant);
        $user->setEmail('user@test.com');

        $version = new DocumentVersion();
        $version->setTenant($tenant);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $result = $voter->vote($token, $version, [DocumentVersionVoter::VIEW]);

        // Voter::VOTE_GRANT = 1
        self::assertSame(1, $result, 'DocumentVersionVoter must grant VIEW for same-tenant users.');
    }

    #[Test]
    public function testDownloadIsGrantedForSameTenantUser(): void
    {
        $voter = new DocumentVersionVoter();

        $tenant = new Tenant();
        $tenant->setName('Download Test Tenant');
        $ref = new \ReflectionClass($tenant);
        $prop = $ref->getProperty('id');

        $prop->setValue($tenant, 2);

        $user = new User();
        $user->setRoles(['ROLE_USER']);
        $user->setTenant($tenant);
        $user->setEmail('dl@test.com');

        $version = new DocumentVersion();
        $version->setTenant($tenant);

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $result = $voter->vote($token, $version, [DocumentVersionVoter::DOWNLOAD]);

        self::assertSame(1, $result, 'DocumentVersionVoter must grant DOWNLOAD for same-tenant users.');
    }

    #[Test]
    public function testDeleteIsDeniedEvenForSuperAdmin(): void
    {
        $voter = new DocumentVersionVoter();

        $tenant = new Tenant();
        $tenant->setName('Super Admin Delete Test');
        $ref = new \ReflectionClass($tenant);
        $prop = $ref->getProperty('id');

        $prop->setValue($tenant, 3);

        $user = new User();
        $user->setRoles(['ROLE_SUPER_ADMIN']);
        $user->setTenant($tenant);
        $user->setEmail('super@test.com');

        $version = new DocumentVersion();
        $version->setTenant($tenant);
        $version->setPublishedAt(new DateTimeImmutable());

        $token = new UsernamePasswordToken($user, 'main', $user->getRoles());

        $result = $voter->vote($token, $version, [DocumentVersionVoter::DELETE]);

        self::assertSame(-1, $result, 'DELETE must be denied even for SUPER_ADMIN — versions are immutable evidence.');
    }

}
