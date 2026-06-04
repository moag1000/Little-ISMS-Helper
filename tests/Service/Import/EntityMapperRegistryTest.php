<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Service\Import\EntityMapperRegistry;
use App\Service\Import\Mapper\EntityMapperInterface;
use App\Service\Import\Schema\EntityImportSchema;
use App\Service\Import\Schema\ImportSchemaProviderInterface;
use App\Service\Import\Schema\ImportSchemaRegistry;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class EntityMapperRegistryTest extends TestCase
{
    /**
     * Helper: create a stub mapper that supports only the given entity type.
     */
    private function makeMapper(string $supportedType): EntityMapperInterface
    {
        /** @var MockObject&EntityMapperInterface $mapper */
        $mapper = $this->createMock(EntityMapperInterface::class);
        $mapper->method('supportsEntityType')
               ->willReturnCallback(fn (string $t): bool => $t === $supportedType);

        return $mapper;
    }

    /**
     * Build a real ImportSchemaRegistry backed by stub providers for the given
     * entity types (the generic supported-type set is now schema-driven).
     */
    private function makeSchemaRegistry(string ...$types): ImportSchemaRegistry
    {
        $providers = array_map(
            fn (string $type): ImportSchemaProviderInterface => new class ($type) implements ImportSchemaProviderInterface {
                public function __construct(private readonly string $type)
                {
                }

                public function supports(string $entityType): bool
                {
                    return $entityType === $this->type;
                }

                public function getSchema(): EntityImportSchema
                {
                    return new EntityImportSchema($this->type, \stdClass::class, []);
                }
            },
            $types,
        );

        return new ImportSchemaRegistry(
            $providers,
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ModuleConfigurationService::class),
        );
    }

    #[Test]
    public function getMapperForReturnsCorrectMapper(): void
    {
        $assetMapper    = $this->makeMapper('Asset');
        $supplierMapper = $this->makeMapper('Supplier');

        $registry = new EntityMapperRegistry([$assetMapper, $supplierMapper]);

        self::assertSame($assetMapper, $registry->getMapperFor('Asset'));
        self::assertSame($supplierMapper, $registry->getMapperFor('Supplier'));
    }

    #[Test]
    public function getMapperForThrowsWhenNoMapperRegistered(): void
    {
        $registry = new EntityMapperRegistry([]);

        $this->expectException(\App\Exception\Import\ImportFailedException::class);
        $this->expectExceptionMessageMatches('/Import of type "Risk" failed: No import mapper registered/');

        $registry->getMapperFor('Risk');
    }

    #[Test]
    public function getMapperForThrowsIncludesSupportedTypesInMessage(): void
    {
        $registry = new EntityMapperRegistry([], $this->makeSchemaRegistry('Asset'));

        try {
            $registry->getMapperFor('Missing');
            self::fail('Expected ImportFailedException');
        } catch (\App\Exception\Import\ImportFailedException $e) {
            self::assertStringContainsString('Asset', $e->getMessage());
        }
    }

    #[Test]
    public function hasMapperForReturnsTrueWhenRegistered(): void
    {
        $registry = new EntityMapperRegistry([$this->makeMapper('Asset')]);

        self::assertTrue($registry->hasMapperFor('Asset'));
    }

    #[Test]
    public function hasMapperForReturnsFalseWhenNotRegistered(): void
    {
        $registry = new EntityMapperRegistry([$this->makeMapper('Asset')]);

        self::assertFalse($registry->hasMapperFor('Supplier'));
    }

    #[Test]
    public function getSupportedEntityTypesReturnsAllRegisteredTypes(): void
    {
        $registry = new EntityMapperRegistry([], $this->makeSchemaRegistry('Asset', 'Supplier', 'Control'));

        $types = $registry->getSupportedEntityTypes();

        self::assertContains('Asset', $types);
        self::assertContains('Supplier', $types);
        self::assertContains('Control', $types);
    }

    #[Test]
    public function getSupportedEntityTypesReturnsEmptyWhenNoMappers(): void
    {
        $registry = new EntityMapperRegistry([]);
        self::assertSame([], $registry->getSupportedEntityTypes());
    }

    #[Test]
    public function registryAcceptsEmptyIterator(): void
    {
        $registry = new EntityMapperRegistry(new \ArrayIterator([]));
        self::assertFalse($registry->hasMapperFor('Asset'));
    }
}
