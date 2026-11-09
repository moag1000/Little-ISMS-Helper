<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\ApiToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ApiTokenManager;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * F6 — token mint/revoke (DB-free, mocked EM + audit logger).
 */
#[AllowMockObjectsWithoutExpectations]
final class ApiTokenManagerTest extends TestCase
{
    private function manager(EntityManagerInterface $em): ApiTokenManager
    {
        return new ApiTokenManager($em, $this->createMock(AuditLogger::class));
    }

    #[Test]
    public function mintReturns64CharPlaintextAndPersists(): void
    {
        $user = new User();
        $user->setTenant(new Tenant());

        $em = $this->createMock(EntityManagerInterface::class);
        $persisted = null;
        $em->expects(self::once())->method('persist')->willReturnCallback(function (object $e) use (&$persisted): void {
            $persisted = $e;
        });
        $em->expects(self::once())->method('flush');

        $plain = $this->manager($em)->mint($user, 'CI token', 30);

        self::assertSame(64, strlen($plain)); // 32 bytes hex
        self::assertInstanceOf(ApiToken::class, $persisted);
        self::assertSame(hash('sha256', $plain), $persisted->getTokenHash());
        self::assertSame('CI token', $persisted->getLabel());
        self::assertNotNull($persisted->getExpiresAt());
    }

    /**
     * S14: every token expires — no value or one above the limit becomes the
     * limit (365 days); old tokens without an expiry end a year after creation.
     */
    #[Test]
    public function everyTokenExpiresWithinTheMaximumLifetime(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $captured = [];
        $em->method('persist')->willReturnCallback(function (object $e) use (&$captured): void {
            $captured[] = $e;
        });

        $this->manager($em)->mint(new User(), '', null);
        $this->manager($em)->mint(new User(), 'long', 5000);

        $limit = (new \DateTimeImmutable())->modify('+' . ApiToken::MAX_LIFETIME_DAYS . ' days +1 minute');
        foreach ($captured as $token) {
            self::assertInstanceOf(ApiToken::class, $token);
            self::assertNotNull($token->getExpiresAt());
            self::assertLessThan($limit, $token->getExpiresAt());
        }
        self::assertSame('API token', $captured[0]->getLabel()); // empty label → default

        $legacy = new ApiToken(); // minted before the limit, no expiry stored
        (new \ReflectionProperty(ApiToken::class, 'createdAt'))->setValue($legacy, new \DateTimeImmutable('-400 days'));
        self::assertFalse($legacy->isValid(new \DateTimeImmutable()));
        self::assertTrue((new ApiToken())->isValid(new \DateTimeImmutable()));
    }

    #[Test]
    public function revokeFlagsAndFlushes(): void
    {
        $token = new ApiToken();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');

        $this->manager($em)->revoke($token);

        self::assertTrue($token->isRevoked());
    }
}
