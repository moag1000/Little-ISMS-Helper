<?php

declare(strict_types=1);

namespace App\Tests\Serializer;

use App\Entity\Asset;
use App\Serializer\AssetNormalizer;
use App\Service\AssetRiskCalculator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;

/**
 * Regression: a cacheable getSupportedTypes() made the normalizer call itself
 * until PHP ran out of memory (GET /api/assets/{id}).
 */
final class AssetNormalizerTest extends TestCase
{
    #[Test]
    public function normalizesAssetWithoutRecursingIntoItself(): void
    {
        $serializer = new Serializer([
            new AssetNormalizer(new AssetRiskCalculator()),
            new ObjectNormalizer(new ClassMetadataFactory(new AttributeLoader())),
        ]);

        $asset = (new Asset())->setName('Server')
            ->setConfidentialityValue(3)->setIntegrityValue(2)->setAvailabilityValue(1);

        $data = $serializer->normalize($asset, 'json', ['groups' => ['asset:read']]);

        self::assertIsArray($data);
        self::assertSame('Server', $data['name']);
        self::assertArrayHasKey('riskScore', $data);
        self::assertArrayHasKey('protectionStatus', $data);
    }
}
