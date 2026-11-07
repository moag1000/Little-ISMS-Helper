<?php

declare(strict_types=1);

namespace App\Tests\Job;

use App\Entity\Tag;
use App\Entity\Tenant;
use App\Exception\Security\InsufficientPrivilegeException;
use App\Job\JobContext;
use App\Job\ReassignEntityJob;
use App\Service\DataRepair\RepairTenantScope;
use App\Service\Job\JobStatusService;
use App\Service\Job\JobTenantScope;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * A tenant admin may only move entities inside its own tenant tree; the job
 * re-checks the scope it was dispatched with (fail-closed without a scope).
 */
final class ReassignEntityJobScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Tenant $tenantA;
    private Tenant $tenantB;
    private Tenant $childOfA;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->em->beginTransaction();

        $this->tenantA = $this->tenant('A');
        $this->tenantB = $this->tenant('B');
        $this->childOfA = $this->tenant('A-child');
        $this->tenantA->addSubsidiary($this->childOfA);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        if ($this->em->getConnection()->isTransactionActive()) {
            $this->em->rollback();
        }
        parent::tearDown();
    }

    #[Test]
    public function tenantScopeCannotMoveIntoForeignTenant(): void
    {
        $tag = $this->tag($this->tenantA);

        $this->expectException(InsufficientPrivilegeException::class);
        $this->runJob($tag, $this->tenantB, [JobTenantScope::ARG_TENANT_ID => $this->tenantA->getId()]);
    }

    #[Test]
    public function tenantScopeCannotMoveForeignEntity(): void
    {
        $tag = $this->tag($this->tenantB);

        $this->expectException(InsufficientPrivilegeException::class);
        $this->runJob($tag, $this->tenantA, [JobTenantScope::ARG_TENANT_ID => $this->tenantA->getId()]);
    }

    #[Test]
    public function tenantScopeCannotClaimAnOrphan(): void
    {
        $tag = $this->tag(null);

        $this->expectException(InsufficientPrivilegeException::class);
        $this->runJob($tag, $this->tenantA, [JobTenantScope::ARG_TENANT_ID => $this->tenantA->getId()]);
    }

    #[Test]
    public function tenantScopeMayMoveInsideItsTree(): void
    {
        $tag = $this->tag($this->tenantA);

        $this->runJob($tag, $this->childOfA, [JobTenantScope::ARG_TENANT_ID => $this->tenantA->getId()]);

        self::assertSame($this->childOfA->getId(), $tag->getTenant()?->getId());
    }

    #[Test]
    public function allTenantsScopeMayMoveAnywhere(): void
    {
        $tag = $this->tag(null);

        $this->runJob($tag, $this->tenantB, [JobTenantScope::ARG_ALL_TENANTS => true]);

        self::assertSame($this->tenantB->getId(), $tag->getTenant()?->getId());
    }

    #[Test]
    public function missingScopeFailsClosed(): void
    {
        $tag = $this->tag($this->tenantA);

        $this->expectException(\Throwable::class);
        $this->runJob($tag, $this->tenantB, null);
    }

    /** @param array<string, mixed>|null $scope */
    private function runJob(Tag $tag, Tenant $target, ?array $scope): void
    {
        $args = ['entityType' => 'tag', 'entityId' => $tag->getId(), 'tenantId' => $target->getId()];
        if ($scope !== null) {
            $args[RepairTenantScope::ARG] = $scope;
        }
        $ctx = new JobContext($this->uuid4(), self::getContainer()->get(JobStatusService::class), $args);
        self::getContainer()->get(ReassignEntityJob::class)->run($ctx);
    }

    private function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function tenant(string $label): Tenant
    {
        $t = (new Tenant())->setName('Reassign ' . $label . uniqid())->setCode('rs_' . uniqid());
        $this->em->persist($t);
        $this->em->flush();

        return $t;
    }

    private function tag(?Tenant $tenant): Tag
    {
        $tag = (new Tag())->setName('t ' . uniqid('', true))->setType('custom')->setColor('secondary');
        $tag->setTenant($tenant);
        $this->em->persist($tag);
        $this->em->flush();

        return $tag;
    }
}
