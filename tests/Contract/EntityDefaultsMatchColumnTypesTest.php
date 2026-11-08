<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * S6 side finding: a constructor default that does not fit its column type
 * only breaks when nobody overwrites it — the HTML form always does, a REST
 * or MCP create without that field does not and answers 500 (ThreatIntelligence
 * and ChangeRequest set a DateTimeImmutable on a mutable `date` column).
 *
 * Every entity is built with its constructor and each initialised date
 * value is compared with its column type.
 */
final class EntityDefaultsMatchColumnTypesTest extends KernelTestCase
{
    private const array MUTABLE = ['date', 'datetime', 'datetimetz', 'time'];
    private const array IMMUTABLE = ['date_immutable', 'datetime_immutable', 'datetimetz_immutable', 'time_immutable'];

    #[Test]
    public function constructorDatesFitTheirColumns(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $problems = [];
        $checked = 0;
        foreach ($em->getMetadataFactory()->getAllMetadata() as $meta) {
            $ref = $meta->getReflectionClass();
            $constructor = $ref->getConstructor();
            if ($ref->isAbstract() || ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0)) {
                continue;
            }
            $record = $ref->newInstance();
            foreach ($meta->getFieldNames() as $field) {
                $type = $meta->getTypeOfField($field);
                $property = $meta->getReflectionProperty($field);
                if ($property === null || !$property->isInitialized($record)) {
                    continue;
                }
                $value = $property->getValue($record);
                if (!$value instanceof \DateTimeInterface) {
                    continue;
                }
                ++$checked;
                if (in_array($type, self::MUTABLE, true) && $value instanceof DateTimeImmutable) {
                    $problems[] = sprintf('%s::$%s is a mutable "%s" column but defaults to DateTimeImmutable', $meta->getName(), $field, $type);
                }
                if (in_array($type, self::IMMUTABLE, true) && !$value instanceof DateTimeImmutable) {
                    $problems[] = sprintf('%s::$%s is an immutable "%s" column but defaults to %s', $meta->getName(), $field, $type, $value::class);
                }
            }
        }

        self::assertSame([], $problems, implode("\n", $problems));
        self::assertGreaterThan(20, $checked);
    }
}
