<?php

declare(strict_types=1);

namespace App\Tests\Service\Setup;

use App\Service\Setup\DatabaseProvisioner;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadataFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for DatabaseProvisioner.
 *
 * Most public methods (runFreshSchemaInstall, runMigrations, dropAndRecreate) require
 * a live PDO/MySQL connection and invoke raw DDL via the native connection.
 * These are integration-only paths and are annotated with @coverage-skip below.
 *
 * Testable here:
 *   - runFreshSchemaInstall() → early-return branch when metadata is empty
 *   - All other paths: @coverage-skip — require live MySQL/PDO connection with DROP DATABASE privs
 *
 * @coverage-skip runFreshSchemaInstall (PDO branch), runMigrations, dropAndRecreate
 *   All require a live MySQL PDO connection. Integration coverage provided by
 *   setup-wizard Playwright E2E tests (var/screenshots/admin/fresh-install step).
 */
#[AllowMockObjectsWithoutExpectations]
final class DatabaseProvisionerTest extends TestCase
{
    private MockObject $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManagerInterface::class);
    }

    #[Test]
    public function run_fresh_schema_install_returns_failure_when_no_entity_metadata(): void
    {
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willReturn([]);

        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        $provisioner = new DatabaseProvisioner($this->entityManager, '/tmp');

        $result = $provisioner->runFreshSchemaInstall();

        self::assertFalse($result['success']);
        self::assertStringContainsString('No entity metadata', $result['message']);
    }

    #[Test]
    public function run_fresh_schema_install_returns_failure_when_metadata_factory_throws(): void
    {
        $metadataFactory = $this->createMock(ClassMetadataFactory::class);
        $metadataFactory->method('getAllMetadata')->willThrowException(new \RuntimeException('Doctrine not configured'));

        $this->entityManager->method('getMetadataFactory')->willReturn($metadataFactory);

        $provisioner = new DatabaseProvisioner($this->entityManager, '/tmp');

        $result = $provisioner->runFreshSchemaInstall();

        self::assertFalse($result['success']);
    }
}
