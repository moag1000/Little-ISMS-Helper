<?php

namespace App\Serializer;

use ArrayObject;
use App\Entity\Asset;
use App\Service\AssetRiskCalculator;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;

/**
 * Custom Normalizer for Asset entities
 *
 * Adds computed properties (risk score, protection status) during serialization
 * without polluting the entity with business logic.
 *
 * Symfony 7 Best Practice:
 * - Business logic in dedicated service (AssetRiskCalculator)
 * - Entity remains a pure data model
 * - Normalizer bridges between service and API output
 *
 * This approach provides:
 * - Better testability (service can be unit tested)
 * - Cleaner separation of concerns
 * - Reusable risk calculation logic
 */
class AssetNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const string ALREADY_CALLED = 'ASSET_NORMALIZER_ALREADY_CALLED';

    public function __construct(
        #[Autowire(service: AssetRiskCalculator::class)]
        private readonly AssetRiskCalculator $assetRiskCalculator
    ) {
    }

    /**
     * {@inheritdoc}
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|ArrayObject|null
    {
        // Prevent infinite recursion
        $context[self::ALREADY_CALLED] = true;

        // Get the standard normalized data
        $data = $this->normalizer->normalize($object, $format, $context);

        // Add computed properties only for 'asset:read' group
        if ($this->shouldAddComputedProperties($context)) {
            /** @var Asset $object */
            $data['riskScore'] = $this->assetRiskCalculator->calculateRiskScore($object);
            $data['isHighRisk'] = $this->assetRiskCalculator->isHighRisk($object);
            $data['protectionStatus'] = $this->assetRiskCalculator->getProtectionStatus($object);
        }

        return $data;
    }

    /**
     * {@inheritdoc}
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        // Only apply to Asset entities
        if (!$data instanceof Asset) {
            return false;
        }
        // Avoid infinite recursion
        return !isset($context[self::ALREADY_CALLED]);
    }

    /**
     * {@inheritdoc}
     */
    public function getSupportedTypes(?string $format): array
    {
        return [
            Asset::class => true,
        ];
    }

    /**
     * Check if computed properties should be added based on serialization groups
     */
    private function shouldAddComputedProperties(array $context): bool
    {
        if (!isset($context['groups'])) {
            return true; // Add by default if no groups specified
        }

        // Add computed properties when 'asset:read' group is present
        return in_array('asset:read', $context['groups'], true);
    }
}
