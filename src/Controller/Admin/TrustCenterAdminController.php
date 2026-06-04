<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F43 Trust-Center — admin UI for managing public compliance-posture sharing.
 *
 * ROLE_ADMIN-gated. Allows the tenant admin to:
 *  - Enable/disable public posture sharing (generates a token on first enable).
 *  - Regenerate the sharing token (invalidates the old URL immediately).
 *  - View the current public link.
 *
 * All actions are logged via AuditLogger for ISO 27001 Clause 7.5.3 compliance.
 */
#[Route('/admin/trust-center')]
#[IsGranted('ROLE_ADMIN')]
class TrustCenterAdminController extends AbstractController
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly EntityManagerInterface $entityManager,
        private readonly AuditLogger $auditLogger,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {}

    #[Route('', name: 'admin_trust_center_settings', methods: ['GET'])]
    public function settings(): Response
    {
        $tenant = $this->requireTenant();

        $publicUrl = null;
        if ($tenant->isPublicPostureEnabled() && $tenant->getPublicPostureToken() !== null) {
            $publicUrl = $this->urlGenerator->generate(
                'app_public_posture',
                ['token' => $tenant->getPublicPostureToken()],
                UrlGeneratorInterface::ABSOLUTE_URL
            );
        }

        return $this->render('admin/trust_center/settings.html.twig', [
            'tenant'    => $tenant,
            'publicUrl' => $publicUrl,
        ]);
    }

    /**
     * Enable public posture sharing for this tenant.
     * Generates a cryptographically random 48-character token on first enable.
     */
    #[Route('/enable', name: 'admin_trust_center_enable', methods: ['POST'])]
    #[IsCsrfTokenValid('trust_center_enable')]
    public function enable(Request $request): Response
    {
        $tenant = $this->requireTenant();

        if ($tenant->getPublicPostureToken() === null) {
            $tenant->setPublicPostureToken($this->generateToken());
        }

        $tenant->setPublicPostureEnabled(true);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'trust_center.share_enabled',
            entityType: Tenant::class,
            entityId: $tenant->getId(),
            newValues: ['publicPostureEnabled' => true],
        );

        $this->addFlash('success', 'trust_center.admin.flash.enabled');
        return $this->redirectToRoute('admin_trust_center_settings');
    }

    /**
     * Disable public posture sharing for this tenant.
     * The token is preserved in DB so it can be re-enabled without URL change,
     * but the enabled flag is false so the controller returns 404.
     */
    #[Route('/disable', name: 'admin_trust_center_disable', methods: ['POST'])]
    #[IsCsrfTokenValid('trust_center_disable')]
    public function disable(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $tenant->setPublicPostureEnabled(false);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'trust_center.share_disabled',
            entityType: Tenant::class,
            entityId: $tenant->getId(),
            newValues: ['publicPostureEnabled' => false],
        );

        $this->addFlash('success', 'trust_center.admin.flash.disabled');
        return $this->redirectToRoute('admin_trust_center_settings');
    }

    /**
     * Regenerate the sharing token, immediately invalidating the old public URL.
     * Only available when sharing is currently enabled.
     */
    #[Route('/regenerate', name: 'admin_trust_center_regenerate', methods: ['POST'])]
    #[IsCsrfTokenValid('trust_center_regenerate')]
    public function regenerate(Request $request): Response
    {
        $tenant = $this->requireTenant();

        $tenant->setPublicPostureToken($this->generateToken());
        // Ensure sharing stays enabled after regeneration
        $tenant->setPublicPostureEnabled(true);
        $this->entityManager->flush();

        $this->auditLogger->logCustom(
            action: 'trust_center.token_regenerated',
            entityType: Tenant::class,
            entityId: $tenant->getId(),
            newValues: ['tokenRegenerated' => true, 'publicPostureEnabled' => true],
        );

        $this->addFlash('success', 'trust_center.admin.flash.token_regenerated');
        return $this->redirectToRoute('admin_trust_center_settings');
    }

    /**
     * Generate a cryptographically secure random token (48 hex chars = 192 bits).
     */
    private function generateToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    private function requireTenant(): Tenant
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }
        return $tenant;
    }
}
