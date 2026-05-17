<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\Guard;

use App\Entity\Document;
use App\Entity\Tenant;
use App\Lifecycle\Guard\TenantGuard;
use App\Service\TenantContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

class TenantGuardTest extends TestCase
{
    public function testBlocksCrossTenant(): void
    {
        $reqTenant = $this->mockTenant(1);
        $subjTenant = $this->mockTenant(2);
        $doc = $this->mockDocument($subjTenant);

        $tenantCtx = $this->createStub(TenantContext::class);
        $tenantCtx->method('getCurrentTenant')->willReturn($reqTenant);

        $event = $this->makeEvent($doc);
        (new TenantGuard($tenantCtx))->onGuard($event);

        $this->assertTrue($event->isBlocked());
    }

    public function testPassesSameTenant(): void
    {
        $tenant = $this->mockTenant(1);
        $doc = $this->mockDocument($tenant);

        $tenantCtx = $this->createStub(TenantContext::class);
        $tenantCtx->method('getCurrentTenant')->willReturn($tenant);

        $event = $this->makeEvent($doc);
        (new TenantGuard($tenantCtx))->onGuard($event);

        $this->assertFalse($event->isBlocked());
    }

    public function testBlocksWhenNoCurrentTenant(): void
    {
        $doc = $this->mockDocument($this->mockTenant(1));

        $tenantCtx = $this->createStub(TenantContext::class);
        $tenantCtx->method('getCurrentTenant')->willReturn(null);

        $event = $this->makeEvent($doc);
        (new TenantGuard($tenantCtx))->onGuard($event);

        $this->assertTrue($event->isBlocked());
    }

    private function mockTenant(int $id): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getId')->willReturn($id);
        return $t;
    }

    private function mockDocument(Tenant $tenant): Document
    {
        $d = $this->createStub(Document::class);
        $d->method('getTenant')->willReturn($tenant);
        return $d;
    }

    private function makeEvent(Document $doc): GuardEvent
    {
        return new GuardEvent(
            $doc,
            new Marking(['draft' => 1]),
            new Transition('submit_for_review', ['draft'], ['in_review']),
            $this->createStub(WorkflowInterface::class),
        );
    }
}
