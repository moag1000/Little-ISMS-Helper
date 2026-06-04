<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public readiness probe — reports whether this instance can serve traffic.
 *
 * Unlike the liveness probe (/health — always 200), readiness checks live
 * dependencies: DB connectivity and the async job transport if messenger mode
 * is active. Orchestrators (k8s, Compose --wait, load-balancers) use this to
 * hold traffic back from an instance that cannot reach its backing services.
 *
 * Tenant-disclosure safety
 * ─────────────────────────
 * The response contains ONLY boolean-style ok/fail status strings per check.
 * No row counts, no tenant IDs, no schema information, no user data of any
 * kind is returned. The DB probe is a bare "SELECT 1" that produces no
 * application-level information.
 *
 * Route is intentionally locale-INdependent (no /{_locale} prefix) — mirrors
 * /health; infra endpoints must not require locale negotiation.
 */
final class ReadinessController
{
    public function __construct(
        private readonly Connection $connection,
        #[Autowire('%app.async_job.runner%')]
        private readonly string $asyncJobRunner,
    ) {
    }

    #[Route('/readyz', name: 'app_readyz', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $checks = [];
        $ready = true;

        // ── DB probe ──────────────────────────────────────────────────────────
        // SELECT 1 is the canonical no-op connectivity check: it exercises the
        // full TCP/socket → auth → query path without touching any application
        // table. Returns no tenant data whatsoever.
        try {
            $this->connection->fetchOne('SELECT 1');
            $checks['db'] = 'ok';
        } catch (\Throwable) {
            $checks['db'] = 'fail';
            $ready = false;
        }

        // ── Queue / transport probe ───────────────────────────────────────────
        // Only relevant when the Messenger runner is active. In the default
        // "in_request" mode there is no background transport to probe — report
        // n/a so operators know the check was intentionally skipped, not missed.
        if ($this->asyncJobRunner === 'messenger') {
            // Probe: can we reach the messenger_messages table?  A cheap COUNT
            // on queue_name='async' with LIMIT 0 exercises the transport without
            // reading any message content or tenant-linked data.
            try {
                $this->connection->fetchOne(
                    "SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' LIMIT 1"
                );
                $checks['queue'] = 'ok';
            } catch (\Throwable) {
                $checks['queue'] = 'fail';
                $ready = false;
            }
        } else {
            // in_request runner: transport is the current PHP process — always ready
            // if we got this far (DB already probed above).
            $checks['queue'] = 'n/a';
        }

        $status = $ready ? 'ready' : 'not_ready';
        $httpStatus = $ready ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE;

        return new JsonResponse(
            ['status' => $status, 'checks' => $checks],
            $httpStatus,
        );
    }
}
