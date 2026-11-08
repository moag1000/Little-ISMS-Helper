<?php

declare(strict_types=1);

namespace App\Tests\Api;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * What `api:openapi:export` does — build the OpenAPI document and normalize it —
 * must run without deprecations from API Platform or from our own resource
 * metadata. phpunit only displays deprecations, so this test makes the export
 * path fail loudly when a resource uses an API that is on its way out.
 */
final class OpenApiExportDeprecationTest extends KernelTestCase
{
    #[Test]
    public function exportingTheOpenApiDocumentTriggersNoDeprecation(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $deprecations = [];
        set_error_handler(static function (int $level, string $message) use (&$deprecations): bool {
            if (str_contains($message, 'ApiPlatform') || str_contains($message, 'api-platform')
                || str_contains($message, 'App\\')) {
                $deprecations[] = $message;
            }

            return true;
        }, E_USER_DEPRECATED | E_DEPRECATED);

        try {
            $openApi = $container->get(OpenApiFactoryInterface::class)->__invoke();
            /** @var NormalizerInterface $normalizer */
            $normalizer = $container->get('serializer');
            $document = $normalizer->normalize($openApi, 'json', ['spec_version' => '3.1.0']);
        } finally {
            restore_error_handler();
        }

        self::assertIsArray($document);
        self::assertArrayHasKey('/api/management_decisions', $document['paths'] ?? []);
        self::assertArrayHasKey('/api/isms_register_entries', $document['paths'] ?? []);
        self::assertArrayHasKey('/api/compliance_requirement_fulfillments', $document['paths'] ?? []);
        $deprecations = array_values(array_unique($deprecations));
        self::assertSame([], $deprecations, "OpenAPI export triggers deprecations:\n" . implode("\n", $deprecations));
    }
}
