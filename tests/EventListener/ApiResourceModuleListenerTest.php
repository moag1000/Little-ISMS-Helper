<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Attribute\RequiresModule;
use App\Entity\BCExercise;
use App\Entity\BusinessContinuityPlan;
use App\Entity\InterestedParty;
use App\Entity\ChangeRequest;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Location;
use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\ThreatIntelligence;
use App\Entity\Training;
use App\EventListener\ApiResourceModuleListener;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * API Platform operations of module-owned entities must honour the module
 * switch just like the HTML controllers do (#1043).
 */
final class ApiResourceModuleListenerTest extends TestCase
{
    #[Test]
    public function inactiveModuleDeniesTheApiOperationWith403Json(): void
    {
        $event = $this->event(BusinessContinuityPlan::class);

        $this->listener([])->onRequest($event);

        $response = $event->getResponse();
        self::assertInstanceOf(JsonResponse::class, $response);
        self::assertSame(Response::HTTP_FORBIDDEN, $response->getStatusCode());
        $payload = json_decode((string) $response->getContent(), true);
        self::assertSame('module_not_active', $payload['error']);
        self::assertSame('bcm', $payload['module']);
    }

    #[Test]
    public function activeModuleLetsTheApiOperationThrough(): void
    {
        $event = $this->event(BusinessContinuityPlan::class);

        $this->listener(['bcm'])->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function resourceWithoutModuleAttributeIsUntouched(): void
    {
        $event = $this->event(Risk::class);

        $this->listener([])->onRequest($event);

        self::assertNull($event->getResponse());
    }

    #[Test]
    public function nonApiRequestsAreUntouched(): void
    {
        $event = new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            Request::create('/en/dashboard'),
            HttpKernelInterface::MAIN_REQUEST,
        );

        $this->listener([])->onRequest($event);

        self::assertNull($event->getResponse());
    }

    /**
     * Entities whose HTML controllers are module-gated must carry the same key
     * on the entity so the API cannot be used to bypass the switch.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function gatedEntities(): iterable
    {
        yield 'BCExercise' => [BCExercise::class, 'bcm'];
        yield 'BusinessContinuityPlan' => [BusinessContinuityPlan::class, 'bcm'];
        yield 'InterestedParty' => [InterestedParty::class, 'interested_parties'];
        yield 'Person' => [Person::class, 'persons'];
        yield 'Supplier' => [Supplier::class, 'suppliers'];
        yield 'Training' => [Training::class, 'training'];
        yield 'Location' => [Location::class, 'locations'];
        yield 'ChangeRequest' => [ChangeRequest::class, 'change_requests'];
        yield 'ThreatIntelligence' => [ThreatIntelligence::class, 'vulnerability_intel'];
        yield 'ComplianceRequirementFulfillment' => [ComplianceRequirementFulfillment::class, 'compliance'];
    }

    /**
     * config/modules.yaml names the entities of each module. An API resource
     * that only optional modules list carries the gate of one of them (its
     * owning module); the other modules that list it own fields, gated by
     * #[ModuleField] (scripts/quality/check_api_module_fields.py).
     *
     * Exception: the ISO 27001 backbone stays ungated on the API exactly as
     * its HTML controllers do (docs/MODULE_GATING_GUIDE.md) — the API never
     * allows less than the UI either.
     */
    #[Test]
    public function everyApiResourceOfAnOptionalModuleIsGatedByItsOwningModule(): void
    {
        $config = \Symfony\Component\Yaml\Yaml::parseFile(dirname(__DIR__, 2) . '/config/modules.yaml');
        $listing = [];
        foreach ($config['modules'] as $key => $module) {
            foreach ($module['entities'] ?? [] as $entity) {
                // null = a required module lists it: always available.
                $listing[$entity] = ($module['required'] ?? false) || (array_key_exists($entity, $listing) && $listing[$entity] === null)
                    ? null
                    : [...($listing[$entity] ?? []), $key];
            }
        }

        $backbone = ['Asset', 'Risk', 'Control', 'Incident', 'InternalAudit'];
        $checked = 0;
        foreach ($listing as $entity => $modules) {
            if (in_array($entity, $backbone, true)) {
                continue;
            }
            $class = 'App\\Entity\\' . $entity;
            if ($modules === null || !class_exists($class)
                || (new ReflectionClass($class))->getAttributes(\ApiPlatform\Metadata\ApiResource::class) === []) {
                continue;
            }
            $keys = array_map(
                static fn ($a): string => $a->newInstance()->module,
                (new ReflectionClass($class))->getAttributes(RequiresModule::class),
            );
            self::assertNotSame([], array_intersect($modules, $keys), sprintf(
                '%s is an API resource of module(s) %s but gated by none of them.',
                $class,
                implode(', ', $modules),
            ));
            ++$checked;
        }
        self::assertGreaterThanOrEqual(6, $checked);
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('gatedEntities')]
    public function moduleOwnedEntitiesDeclareTheirModule(string $class, string $module): void
    {
        $keys = array_map(
            static fn ($a): string => $a->newInstance()->module,
            (new ReflectionClass($class))->getAttributes(RequiresModule::class),
        );

        self::assertSame([$module], $keys);
    }

    /**
     * @param list<string> $activeModules
     */
    private function listener(array $activeModules): ApiResourceModuleListener
    {
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActive')->willReturnCallback(
            static fn (string $key): bool => in_array($key, $activeModules, true),
        );
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturn('module not active');

        return new ApiResourceModuleListener($modules, $translator);
    }

    #[Test]
    public function subRequestsAreGatedToo(): void
    {
        $event = $this->event(BCExercise::class, HttpKernelInterface::SUB_REQUEST);

        $this->listener([])->onRequest($event);

        self::assertSame(Response::HTTP_FORBIDDEN, $event->getResponse()?->getStatusCode());
    }

    private function event(string $resourceClass, int $type = HttpKernelInterface::MAIN_REQUEST): RequestEvent
    {
        $request = Request::create('/api/resources');
        $request->attributes->set('_api_resource_class', $resourceClass);

        return new RequestEvent(
            $this->createStub(HttpKernelInterface::class),
            $request,
            $type,
        );
    }
}
