<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Property\Factory\PropertyMetadataFactoryInterface;
use ApiPlatform\Metadata\Property\Factory\PropertyNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Property\PropertyNameCollection;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\ResourceMetadataCollection;
use ApiPlatform\Metadata\Resource\ResourceNameCollection;
use App\Entity\ApiToken;
use App\Entity\Tenant;
use App\Entity\User;
use App\Mcp\ApiResourceGateway;
use App\Mcp\McpSession;
use App\Mcp\McpToolException;
use App\Mcp\PersonalDataPolicy;
use App\Security\Persona\PersonaResolver;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use App\Attribute\ModuleField;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * The write rules of the assistant on top of the API operation: tenant-owned
 * resources only, write-group fields only, never status / tenant / ids,
 * never Delete, never a replacing PUT, partial updates as merge-patch.
 */
final class ApiResourceGatewayWriteTest extends TestCase
{
    /** @var list<Request> */
    private array $sent = [];

    #[Test]
    public function createAndUpdateRunTheResourcesOwnOperations(): void
    {
        $gateway = $this->gateway();
        $resources = $gateway->resources($this->session());

        self::assertTrue($resources['Note']['create']);
        self::assertTrue($resources['Note']['update']);
        self::assertSame(['body', 'title'], $gateway->writableFields($this->session(), 'Note'));

        $gateway->write($this->session(), 'Note', null, ['title' => 'Neu']);
        $gateway->write($this->session(), 'Note', 7, ['body' => 'Nur dieses Feld']);

        self::assertSame('POST', $this->sent[0]->getMethod());
        self::assertSame('/notes', $this->sent[0]->getPathInfo());
        self::assertSame('{"title":"Neu"}', $this->sent[0]->getContent());
        self::assertSame('PATCH', $this->sent[1]->getMethod());
        self::assertSame('/notes/7', $this->sent[1]->getPathInfo());
        self::assertSame('application/merge-patch+json', $this->sent[1]->headers->get('Content-Type'));
    }

    #[Test]
    public function statusTenantAndIdsAreRefusedBeforeTheApiIsCalled(): void
    {
        foreach (['status' => 'closed', 'tenant' => '/api/tenants/2', 'id' => 3, '@id' => '/notes/3'] as $key => $value) {
            try {
                $this->gateway()->write($this->session(), 'Note', 7, [$key => $value]);
                self::fail($key . ' must be refused');
            } catch (McpToolException $e) {
                self::assertStringContainsString('Not writable here', $e->getMessage());
            }
        }
        self::assertSame([], $this->sent);
    }

    #[Test]
    public function fieldsOutsideTheWriteGroupAreRefusedWithTheWritableList(): void
    {
        $this->expectException(McpToolException::class);
        $this->expectExceptionMessage('Writable fields: body, title');

        $this->gateway()->write($this->session(), 'Note', null, ['approvedBy' => 'Ich']);
    }

    #[Test]
    public function globalCataloguesAreNeverWritten(): void
    {
        $gateway = $this->gateway();
        self::assertFalse($gateway->resources($this->session())['Catalogue']['create']);

        $this->expectException(McpToolException::class);
        $gateway->write($this->session(), 'Catalogue', null, ['title' => 'x']);
    }

    #[Test]
    public function aReplacingPutAndDeleteAreNotUsed(): void
    {
        $resources = $this->gateway()->resources($this->session());

        self::assertFalse($resources['Replaced']['update'], 'standard PUT resets every field not sent');
        self::assertTrue($resources['Populated']['update'], 'PUT with standard_put off populates the record');
    }

    /** S4: an operation without write groups would accept every entity field. */
    #[Test]
    public function aResourceWithoutWriteGroupsIsNeverWritten(): void
    {
        $gateway = $this->gateway();
        self::assertFalse($gateway->resources($this->session())['Ungrouped']['create']);

        $this->expectException(McpToolException::class);
        $gateway->write($this->session(), 'Ungrouped', null, ['title' => 'x']);
    }

    /** S4: a field of a module that is off is neither offered nor accepted. */
    #[Test]
    public function fieldsOfAnInactiveModuleAreNotWritable(): void
    {
        $gateway = $this->gateway();
        self::assertNotContains('lossMagnitude', $gateway->writableFields($this->session(), 'Note'));

        $this->expectException(McpToolException::class);
        $gateway->write($this->session(), 'Note', null, ['lossMagnitude' => 5]);
    }

    private function gateway(): ApiResourceGateway
    {
        $names = $this->createStub(ResourceNameCollectionFactoryInterface::class);
        $names->method('create')->willReturn(new ResourceNameCollection([
            GatewayNote::class, GatewayCatalogue::class, GatewayReplaced::class, GatewayPopulated::class, GatewayUngrouped::class,
        ]));

        $write = ['groups' => ['x:write']];
        $metadata = $this->createStub(ResourceMetadataCollectionFactoryInterface::class);
        $metadata->method('create')->willReturnCallback(static fn (string $class): ResourceMetadataCollection => new ResourceMetadataCollection($class, [
            match ($class) {
                GatewayNote::class => new ApiResource(shortName: 'Note', operations: [
                    'note_get' => new Get(uriTemplate: '/notes/{id}', shortName: 'Note'),
                    'note_post' => new Post(uriTemplate: '/notes', shortName: 'Note', denormalizationContext: $write),
                    'note_put' => new Put(uriTemplate: '/notes/{id}', shortName: 'Note', denormalizationContext: $write),
                    'note_patch' => new Patch(uriTemplate: '/notes/{id}', shortName: 'Note', denormalizationContext: $write),
                    'note_delete' => new Delete(uriTemplate: '/notes/{id}', shortName: 'Note'),
                ]),
                GatewayCatalogue::class => new ApiResource(shortName: 'Catalogue', operations: [
                    'cat_post' => new Post(uriTemplate: '/catalogues', shortName: 'Catalogue', denormalizationContext: $write),
                ]),
                GatewayReplaced::class => new ApiResource(shortName: 'Replaced', operations: [
                    'rep_put' => new Put(uriTemplate: '/replaced/{id}', shortName: 'Replaced', denormalizationContext: $write),
                ]),
                GatewayUngrouped::class => new ApiResource(shortName: 'Ungrouped', operations: [
                    'ung_post' => new Post(uriTemplate: '/ungrouped', shortName: 'Ungrouped'),
                ]),
                default => new ApiResource(shortName: 'Populated', operations: [
                    'pop_put' => new Put(uriTemplate: '/populated/{id}', shortName: 'Populated', extraProperties: ['standard_put' => false], denormalizationContext: $write),
                ]),
            },
        ]));

        $properties = $this->createStub(PropertyNameCollectionFactoryInterface::class);
        $properties->method('create')->willReturn(new PropertyNameCollection(['id', 'tenant', 'status', 'title', 'body', 'approvedBy', 'lossMagnitude']));
        $property = $this->createStub(PropertyMetadataFactoryInterface::class);
        $property->method('create')->willReturnCallback(
            static fn (string $class, string $name): ApiProperty => (new ApiProperty())->withWritable(in_array($name, ['title', 'body', 'status', 'tenant', 'lossMagnitude'], true)),
        );

        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturnCallback(static fn (string $route, array $params = []): string => match ($route) {
            'note_post' => '/notes',
            default => '/notes/' . ($params['id'] ?? ''),
        });

        $kernel = $this->createStub(HttpKernelInterface::class);
        $kernel->method('handle')->willReturnCallback(function (Request $request): JsonResponse {
            $this->sent[] = $request;

            return new JsonResponse(['@context' => '/contexts/Note', 'id' => 7]);
        });

        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturnCallback(static fn (string $module): bool => $module !== 'risk_quant');

        $personalData = new PersonalDataPolicy(new PersonaResolver(new RoleHierarchy([])), dirname(__DIR__, 2));

        return new ApiResourceGateway($names, $metadata, $kernel, new RequestStack(), $urls, $modules, $properties, $property, $personalData);
    }

    private function session(): McpSession
    {
        return new McpSession(new User(), new Tenant(), new ApiToken(), 'https://isms.example');
    }
}

/** @internal tenant-owned test resource */
final class GatewayNote
{
    #[ModuleField('risk_quant')]
    private ?int $lossMagnitude = null;

    public function getTenant(): ?Tenant
    {
        return null;
    }

    public function setTenant(?Tenant $tenant): void
    {
    }
}

/** @internal global catalogue: no tenant */
final class GatewayCatalogue
{
}

/** @internal */
final class GatewayReplaced
{
    public function getTenant(): ?Tenant
    {
        return null;
    }

    public function setTenant(?Tenant $tenant): void
    {
    }
}

/** @internal */
final class GatewayPopulated
{
    public function getTenant(): ?Tenant
    {
        return null;
    }

    public function setTenant(?Tenant $tenant): void
    {
    }
}

/** @internal tenant-owned, but its Post names no denormalisation group */
final class GatewayUngrouped
{
    public function getTenant(): ?Tenant
    {
        return null;
    }

    public function setTenant(?Tenant $tenant): void
    {
    }
}
