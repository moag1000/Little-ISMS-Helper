<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline\Configured;

use App\Service\Deadline\Configured\ConfiguredDeadlineSources;
use App\Service\Deadline\Configured\DeadlineTypeDefinition;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Entity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every entry of config/deadline_types.yaml is a working deadline source:
 * a tenant-owned entity, readable date and holder fields, expressions that
 * parse and run on a fresh record and My Day labels in DE + EN. Slugs are
 * checked against the class sources in DeadlineSourceContractTest.
 */
final class DeadlineTypesConfigTest extends TestCase
{
    /** @return array<string, array<string, mixed>> */
    private static function types(): array
    {
        return Yaml::parseFile(dirname(__DIR__, 4) . '/config/deadline_types.yaml')['parameters']['app.deadline_types'];
    }

    /** @return iterable<string, array{string}> */
    public static function slugs(): iterable
    {
        foreach (array_keys(self::types()) as $slug) {
            yield $slug => [$slug];
        }
    }

    #[Test]
    #[DataProvider('slugs')]
    public function entityIsTenantOwned(string $slug): void
    {
        $definition = DeadlineTypeDefinition::fromArray($slug, self::types()[$slug]);
        $class = new \ReflectionClass($definition->entity);
        self::assertNotEmpty($class->getAttributes(Entity::class), "$slug: {$definition->entity} is no Doctrine entity");
        self::assertTrue($class->hasProperty('tenant'), "$slug: {$definition->entity} has no tenant");
    }

    #[Test]
    #[DataProvider('slugs')]
    public function fieldPathsHaveGetters(string $slug): void
    {
        $definition = DeadlineTypeDefinition::fromArray($slug, self::types()[$slug]);
        $paths = [...$definition->holders, ...$definition->deputies];
        foreach ($definition->inform as $inform) {
            if ($inform !== 'self' && !str_starts_with($inform, 'ROLE_')) {
                $paths[] = $inform;
            }
        }
        if ($definition->due !== null) {
            $paths[] = $definition->due;
        }
        foreach ($paths as $path) {
            $found = false;
            foreach (['get', 'is', 'has'] as $prefix) {
                $found = $found || method_exists($definition->entity, $prefix . ucfirst($path));
            }
            self::assertTrue($found, "$slug: {$definition->entity} has no getter for \"$path\"");
        }
    }

    #[Test]
    #[DataProvider('slugs')]
    public function expressionsRunOnAFreshRecord(string $slug): void
    {
        $source = ConfiguredDeadlineSources::fromFile($this->createStub(EntityManagerInterface::class))->get($slug);
        self::assertNotNull($source);
        $class = $source->definition->entity;
        $record = new $class();

        self::assertIsString($source->titleOf($record));
        self::assertIsBool($source->isCompleted($record));
        $source->dueDateOf($record);
        $source->responsibleOf($record);
        self::assertTrue($source->supports($record));
        self::assertFalse($source->supports(new \stdClass()));
    }

    #[Test]
    #[DataProvider('slugs')]
    public function bucketHasLabelsInBothLanguages(string $slug): void
    {
        $bucket = (string) self::types()[$slug]['bucket'];
        foreach (['de', 'en'] as $locale) {
            $messages = Yaml::parseFile(dirname(__DIR__, 4) . "/translations/my_day.$locale.yaml")['my_day'];
            self::assertArrayHasKey($bucket, $messages['section'], "my_day.section.$bucket ($locale)");
            self::assertArrayHasKey($bucket, $messages['kpi'], "my_day.kpi.$bucket ($locale)");
        }
    }

    #[Test]
    public function unknownKeysAreRejected(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        DeadlineTypeDefinition::fromArray('x', ['label' => 'X', 'entity' => \App\Entity\Risk::class, 'due' => null, 'title' => "'x'", 'bucket' => 'b', 'lead' => 3]);
    }
}
