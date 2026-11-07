<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Service\Deadline\DeadlineSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Every deadline source in src/ keeps the contract the inbox relies on:
 * unique slug, a bucket with DE + EN labels, supports() matching its own
 * record type and rejecting others.
 */
final class DeadlineSourceContractTest extends TestCase
{
    /** @return iterable<string, array{class-string<DeadlineSource>}> */
    public static function sources(): iterable
    {
        $root = dirname(__DIR__, 3) . '/src/';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            if (!preg_match('/implements\s+[^{]*\b(SourceAdapter|DeadlineSource)\b/', $code)) {
                continue;
            }
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($root)));
            if (class_exists($class) && is_subclass_of($class, DeadlineSource::class)) {
                yield substr((string) strrchr($class, '\\'), 1) => [$class];
            }
        }
    }

    #[Test]
    #[DataProvider('sources')]
    public function bucketHasLabelsInBothLanguages(string $class): void
    {
        $bucket = $this->instance($class)->profile()->bucket;
        self::assertNotSame('', $bucket);
        foreach (['de', 'en'] as $locale) {
            $messages = Yaml::parseFile(dirname(__DIR__, 3) . "/translations/my_day.$locale.yaml")['my_day'];
            self::assertArrayHasKey($bucket, $messages['section'], "my_day.section.$bucket ($locale)");
            self::assertArrayHasKey($bucket, $messages['kpi'], "my_day.kpi.$bucket ($locale)");
        }
    }

    #[Test]
    #[DataProvider('sources')]
    public function doesNotClaimForeignRecords(string $class): void
    {
        self::assertFalse($this->instance($class)->supports(new \stdClass()));
    }

    #[Test]
    public function slugsAreUnique(): void
    {
        $slugs = [];
        foreach (self::sources() as [$class]) {
            $slug = $this->instance($class)->slug();
            self::assertArrayNotHasKey($slug, $slugs, "$class reuses slug $slug of " . ($slugs[$slug] ?? ''));
            $slugs[$slug] = $class;
        }
        self::assertGreaterThanOrEqual(16, count($slugs));
    }

    /** @param class-string<DeadlineSource> $class */
    private function instance(string $class): DeadlineSource
    {
        $reflection = new \ReflectionClass($class);
        $args = [];
        foreach ($reflection->getConstructor()?->getParameters() ?? [] as $parameter) {
            $type = $parameter->getType();
            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
            } elseif ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->createStub($type->getName());
            } else {
                $args[] = null;
            }
        }

        return $reflection->newInstanceArgs($args);
    }
}
