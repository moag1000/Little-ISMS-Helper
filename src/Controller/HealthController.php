<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public liveness probe — always returns 200 "OK".
 *
 * Intentionally minimal: no DB-call, no DI beyond framework defaults, no
 * locale-routing. Used by Docker HEALTHCHECK and orchestrators (k8s, Compose
 * --wait) to verify nginx + php-fpm + Symfony front-controller are reachable.
 *
 * Deeper health (DB, migrations, schema-drift) is exposed under
 * /admin/monitoring/health which is auth-gated.
 */
final class HealthController
{
    #[Route('/health', name: 'app_health', methods: ['GET'])]
    public function __invoke(): Response
    {
        return new Response('OK', Response::HTTP_OK, ['Content-Type' => 'text/plain']);
    }
}
