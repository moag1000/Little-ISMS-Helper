<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Background runs (CLI / cron) have no TenantContext: AuditLogger::withTenant()
 * lets them stamp their audit rows with an explicit tenant.
 */
#[AllowMockObjectsWithoutExpectations]
final class AuditLoggerTenantPinTest extends TestCase
{
    /** @var list<AuditLog> */
    private array $persisted = [];

    private function logger(?Tenant $contextTenant = null): AuditLogger
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof AuditLog) {
                $this->persisted[] = $o;
            }
        });

        $context = $this->createMock(TenantContext::class);
        $context->method('getCurrentTenant')->willReturn($contextTenant);

        return new AuditLogger($em, new RequestStack(), $this->createMock(Security::class), null, $context);
    }

    #[Test]
    public function rowsCarryThePassedTenantWhenContextIsEmpty(): void
    {
        $tenant = new Tenant();
        $logger = $this->logger();

        $logger->withTenant($tenant, static fn () => $logger->logCustom('retention.enforced', 'Asset', null, null, null, 'x', 'system'));

        self::assertCount(1, $this->persisted);
        self::assertSame($tenant, $this->persisted[0]->getTenant());
    }

    #[Test]
    public function withoutPinTheContextTenantIsUsedAndPinIsReleasedAfterwards(): void
    {
        $contextTenant = new Tenant();
        $pinned = new Tenant();
        $logger = $this->logger($contextTenant);

        $logger->withTenant($pinned, static fn () => $logger->logCustom('a', 'Asset', null, null, null, 'x', 'system'));
        $logger->logCustom('b', 'Asset', null, null, null, 'x', 'system');

        self::assertSame($pinned, $this->persisted[0]->getTenant());
        self::assertSame($contextTenant, $this->persisted[1]->getTenant());
    }

    #[Test]
    public function pinIsReleasedWhenTheCallbackThrows(): void
    {
        $logger = $this->logger();

        try {
            $logger->withTenant(new Tenant(), static function (): void {
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $logger->logCustom('after', 'Asset', null, null, null, 'x', 'system');

        self::assertNull($this->persisted[0]->getTenant());
    }
}
