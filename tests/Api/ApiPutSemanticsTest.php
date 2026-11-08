<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Resource\Factory\ResourceMetadataCollectionFactoryInterface;
use ApiPlatform\Metadata\Resource\Factory\ResourceNameCollectionFactoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Every PUT operation populates the stored record (standard_put: false). With
 * API Platform's default the Doctrine PersistProcessor copies every property of
 * a freshly deserialized object onto the stored one — fields outside the write
 * groups included — so a PUT cleared the tenant and reset the lifecycle status.
 */
final class ApiPutSemanticsTest extends KernelTestCase
{
    #[Test]
    public function everyPutOperationUpdatesTheStoredRecordInPlace(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $names = $container->get(ResourceNameCollectionFactoryInterface::class)->create();
        $metadata = $container->get(ResourceMetadataCollectionFactoryInterface::class);

        $puts = 0;
        foreach ($names as $class) {
            foreach ($metadata->create($class) as $resource) {
                foreach ($resource->getOperations() ?? [] as $name => $operation) {
                    if (!$operation instanceof Put) {
                        continue;
                    }
                    ++$puts;
                    self::assertFalse(
                        $operation->getExtraProperties()['standard_put'] ?? true,
                        sprintf('%s (%s): PUT must run with standard_put: false.', $class, $name),
                    );
                }
            }
        }

        self::assertGreaterThan(0, $puts, 'no PUT operation found — metadata discovery is broken');
    }
}
