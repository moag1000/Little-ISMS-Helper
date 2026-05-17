<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle\Guard;

use App\Entity\Document;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Guard\ModuleGateGuard;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Workflow\Event\GuardEvent;
use Symfony\Component\Workflow\Marking;
use Symfony\Component\Workflow\Transition;
use Symfony\Component\Workflow\WorkflowInterface;

class ModuleGateGuardTest extends TestCase
{
    public function testBlocksWhenModuleInactive(): void
    {
        $guard = $this->makeGuard(moduleKey: 'documents', isActive: false);
        $event = $this->makeEvent();

        $guard->onGuard($event);

        $this->assertTrue($event->isBlocked());
    }

    public function testPassesWhenModuleActive(): void
    {
        $guard = $this->makeGuard(moduleKey: 'documents', isActive: true);
        $event = $this->makeEvent();

        $guard->onGuard($event);

        $this->assertFalse($event->isBlocked());
    }

    public function testPassesWhenNoModuleSpecified(): void
    {
        $guard = $this->makeGuard(moduleKey: null, isActive: false);
        $event = $this->makeEvent();

        $guard->onGuard($event);

        $this->assertFalse($event->isBlocked());
    }

    private function makeGuard(?string $moduleKey, bool $isActive): ModuleGateGuard
    {
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('get')->willReturn($moduleKey);

        $modSvc = $this->createStub(ModuleConfigurationService::class);
        $modSvc->method('isModuleActive')->willReturn($isActive);

        return new ModuleGateGuard($resolver, $modSvc);
    }

    private function makeEvent(): GuardEvent
    {
        return new GuardEvent(
            new Document(),
            new Marking(['approved' => 1]),
            new Transition('publish', ['approved'], ['published']),
            $this->createStub(WorkflowInterface::class),
        );
    }
}
