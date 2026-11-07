<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\IsmsRegisterEntry;
use App\Entity\ManagementDecision;
use App\Lifecycle\EntityTypeRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Symfony\Component\Serializer\Attribute\Groups;

/**
 * Contract of every REST resource, DB-free:
 *
 * - it names its serialization groups, so the API never falls back to "all
 *   properties" (that leaked linked User and Tenant objects of BC plans,
 *   BC exercises, change requests and interested parties);
 * - a lifecycle-managed entity exposes its status read-only — transitions go
 *   through LifecycleService (roles, four-eyes, top management, audit log),
 *   exactly as the HTML forms keep the status field unmapped;
 * - the norm records added with the package waves are read-only resources.
 */
final class ApiResourceContractTest extends TestCase
{
    private const array READ_ONLY_RESOURCES = [
        ManagementDecision::class,
        IsmsRegisterEntry::class,
        ComplianceRequirementFulfillment::class,
    ];

    /** @return iterable<string, array{class-string}> */
    public static function apiResources(): iterable
    {
        foreach (glob(dirname(__DIR__, 2) . '/src/Entity/*.php') ?: [] as $file) {
            $class = 'App\\Entity\\' . basename($file, '.php');
            if (!class_exists($class)) {
                continue;
            }
            if ((new ReflectionClass($class))->getAttributes(ApiResource::class) !== []) {
                yield (new ReflectionClass($class))->getShortName() => [$class];
            }
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('apiResources')]
    public function everyResourceNamesItsSerializationGroups(string $class): void
    {
        $resource = $this->resource($class);

        self::assertNotEmpty(
            $resource->getNormalizationContext()['groups'] ?? [],
            sprintf('%s: #[ApiResource] needs normalizationContext groups — without them every property is serialized.', $class),
        );

        $writes = array_filter(
            iterator_to_array($resource->getOperations() ?? []),
            static fn ($op): bool => !$op instanceof Get && !$op instanceof GetCollection,
        );
        if ($writes !== []) {
            self::assertNotEmpty(
                $resource->getDenormalizationContext()['groups'] ?? [],
                sprintf('%s: a writable resource needs denormalizationContext groups.', $class),
            );
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('apiResources')]
    public function lifecycleStatusIsReadOnlyOnTheApi(string $class): void
    {
        if (!in_array($class, EntityTypeRegistry::managedEntityClasses(), true)) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $ref = new ReflectionClass($class);
        foreach (['status', 'statusValue'] as $property) {
            if (!$ref->hasProperty($property)) {
                continue;
            }
            foreach ($ref->getProperty($property)->getAttributes(Groups::class) as $attribute) {
                foreach ($attribute->newInstance()->groups as $group) {
                    self::assertStringEndsNotWith(
                        ':write',
                        $group,
                        sprintf('%s::$%s is in write group "%s" — status changes must go through LifecycleService.', $class, $property, $group),
                    );
                }
            }
        }
        self::assertTrue(true);
    }

    /** @return iterable<string, array{class-string}> */
    public static function readOnlyResources(): iterable
    {
        foreach (self::READ_ONLY_RESOURCES as $class) {
            yield (new ReflectionClass($class))->getShortName() => [$class];
        }
    }

    /** @param class-string $class */
    #[Test]
    #[DataProvider('readOnlyResources')]
    public function normRecordsAreReadOnly(string $class): void
    {
        foreach ($this->resource($class)->getOperations() ?? [] as $operation) {
            self::assertTrue(
                $operation instanceof Get || $operation instanceof GetCollection,
                sprintf('%s must stay read-only on the API, found %s.', $class, $operation::class),
            );
            self::assertNotNull($operation->getSecurity(), sprintf('%s: every operation needs a security expression.', $class));
        }
    }

    #[Test]
    public function managementDecisionsAreForReviewersAndAbove(): void
    {
        foreach ($this->resource(ManagementDecision::class)->getOperations() ?? [] as $operation) {
            self::assertStringContainsString('ROLE_AUDITOR', (string) $operation->getSecurity());
        }
    }

    /** @param class-string $class */
    private function resource(string $class): ApiResource
    {
        $attributes = (new ReflectionClass($class))->getAttributes(ApiResource::class);
        self::assertNotEmpty($attributes, $class . ' is not an API resource');

        return $attributes[0]->newInstance();
    }
}
