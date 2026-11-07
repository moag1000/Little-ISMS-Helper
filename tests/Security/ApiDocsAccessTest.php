<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * No access_control rule covered /api, so the OpenAPI schema (every
 * resource, field and filter) and the entrypoint were public.
 */
final class ApiDocsAccessTest extends WebTestCase
{
    #[Test]
    public function apiDocsNeedALogin(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/docs.jsonopenapi');

        self::assertNotSame(200, $client->getResponse()->getStatusCode(), 'The OpenAPI schema is not public.');
        self::assertStringNotContainsString('"openapi"', (string) $client->getResponse()->getContent());
    }
}
