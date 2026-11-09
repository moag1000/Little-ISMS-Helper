<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\State\SerializerContextBuilderInterface;
use App\Api\ModuleFieldContextBuilder;
use App\Entity\Incident;
use App\EventListener\ApiModuleFieldFilterListener;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * S24: a field of a switched-off module is no filter or sort key either
 * (`?notificationRequired=true` told which incidents carry the hidden NIS-2 flag).
 */
final class ApiModuleFieldFilterTest extends TestCase
{
    #[Test]
    public function aFilterOrSortOnAnInactiveModulesFieldIsRefused(): void
    {
        self::assertSame(400, $this->answerStatus(['notificationRequired' => 'true'], active: ['incidents'])); // nis2_dora off
        self::assertSame(400, $this->answerStatus(['order' => ['notificationRequired' => 'asc']], active: ['incidents']));
    }

    #[Test]
    public function otherFiltersAndActiveModulesPass(): void
    {
        self::assertNull($this->answerStatus(['title' => 'x', 'order' => ['detectedAt' => 'desc']], active: ['incidents']));
        self::assertNull($this->answerStatus(['notificationRequired' => 'true'], active: ['incidents', 'nis2_dora', 'controls', 'privacy']));
    }

    /**
     * @param array<string, mixed> $query
     * @param list<string>         $active
     */
    private function answerStatus(array $query, array $active): ?int
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturnCallback(static fn (string $key): bool => in_array($key, $active, true));
        $listener = new ApiModuleFieldFilterListener(new ModuleFieldContextBuilder($this->createStub(SerializerContextBuilderInterface::class), $modules));

        $request = Request::create('/api/incidents', 'GET', $query);
        $request->attributes->set('_api_resource_class', Incident::class);
        $event = new RequestEvent($this->createStub(HttpKernelInterface::class), $request, HttpKernelInterface::MAIN_REQUEST);
        $listener->onRequest($event);

        return $event->getResponse()?->getStatusCode();
    }
}
