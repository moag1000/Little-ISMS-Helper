<?php

declare(strict_types=1);

namespace App\Serializer;

use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\DependencyInjection\Attribute\AutowireDecorated;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Dates a client sends to the REST API / the assistant (MCP) land in the server's own time zone (#1207).
 *
 * The date columns are stored without a zone and read back in PHP's default time zone. The stock
 * DateTimeNormalizer keeps the offset of the incoming string ("…T08:00:00Z" → UTC), the column then keeps the
 * UTC wall time, and the next read labels it with the default zone ("08:00+01:00"): the instant moved by the
 * zone's offset, whatever zone the client used. Converting on the way in makes write and read agree, so a
 * client gets back the instant it sent (with the right offset).
 */
#[AsDecorator('serializer.normalizer.datetime')]
final class DefaultTimezoneDateTimeNormalizer implements NormalizerInterface, DenormalizerInterface
{
    public function __construct(
        #[AutowireDecorated]
        private readonly NormalizerInterface&DenormalizerInterface $inner,
    ) {
    }

    public function getSupportedTypes(?string $format): array
    {
        return $this->inner->getSupportedTypes($format);
    }

    public function normalize(mixed $data, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        return $this->inner->normalize($data, $format, $context);
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsNormalization($data, $format, $context);
    }

    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        $date = $this->inner->denormalize($data, $type, $format, $context);
        if (!$date instanceof \DateTimeInterface) {
            return $date;
        }
        $zone = new \DateTimeZone(date_default_timezone_get());

        return $date instanceof \DateTimeImmutable ? $date->setTimezone($zone) : \DateTime::createFromInterface($date)->setTimezone($zone);
    }

    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return $this->inner->supportsDenormalization($data, $type, $format, $context);
    }
}
