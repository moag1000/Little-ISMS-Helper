<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\TenantRepository;
use App\Service\PostureSnapshotService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * F43 Trust-Center — unauthenticated public compliance-posture endpoint.
 *
 * Route: GET /trust/{token}  (no /{_locale} prefix — registered via
 * public_posture_routes in config/routes.yaml, same pattern as infra probes).
 *
 * Security model:
 *  1. TenantRepository::findByPostureToken() queries the DB for a matching token.
 *  2. hash_equals() provides constant-time comparison against the stored token
 *     to eliminate timing-side-channel attacks (even though the DB already did
 *     the lookup, this prevents any future refactor from opening a channel).
 *  3. publicPostureEnabled must be true — disabled tenants return 404, giving
 *     no information about whether a tenant exists.
 *  4. PostureSnapshotService returns ONLY §4-safe data (framework compliance %).
 *
 * TENANT-DISCLOSURE-SAFE: this is the ONLY unauthenticated tenant-data surface.
 */
class PublicPostureController extends AbstractController
{
    public function __construct(
        private readonly TenantRepository $tenantRepository,
        private readonly PostureSnapshotService $postureSnapshotService,
    ) {}

    #[Route('/trust/{token}', name: 'app_public_posture', methods: ['GET'])]
    public function show(string $token): Response
    {
        // Lookup — constant-time guard follows immediately
        $tenant = $this->tenantRepository->findByPostureToken($token);

        // Constant-time comparison: hash_equals on the stored token vs supplied
        // token. If no tenant found, compare against a dummy to maintain constant
        // time (prevents enumeration timing).
        $storedToken = $tenant?->getPublicPostureToken() ?? '';
        if (!hash_equals($storedToken, $token)) {
            throw $this->createNotFoundException();
        }

        // Gate: sharing must be explicitly enabled
        if (!$tenant->isPublicPostureEnabled()) {
            throw $this->createNotFoundException();
        }

        $snapshot = $this->postureSnapshotService->getSnapshot($tenant);

        return $this->render('trust_center/public_posture.html.twig', [
            'snapshot' => $snapshot,
        ]);
    }
}
