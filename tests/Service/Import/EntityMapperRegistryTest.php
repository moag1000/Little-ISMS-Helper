<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Tenant;
use App\Service\Import\EntityMapperRegistry;
use App\Service\Import\Mapper\EntityMapperInterface;
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
        $registry = new EntityMapperRegistry([$this->makeMapper('Asset')]);

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
        $registry = new EntityMapperRegistry([
            $this->makeMapper('Asset'),
            $this->makeMapper('Supplier'),
            $this->makeMapper('Control'),
        ]);

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
