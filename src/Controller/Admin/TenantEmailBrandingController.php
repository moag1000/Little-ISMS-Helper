<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Form\Admin\TenantEmailBrandingType;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use App\Service\TenantEmailBrandingResolver;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Phase 8L.F3 — Admin-UI für E-Mail-Branding (Absender, Logo, Footer).
 */
#[Route('/admin/email-branding')]
#[IsGranted('ROLE_ADMIN')]
class TenantEmailBrandingController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
        private readonly AuditLogger $auditLogger,
        private readonly TenantEmailBrandingResolver $resolver,
    ) {
    }

    #[Route('', name: 'app_admin_tenant_email_branding', methods: ['GET', 'POST'])]
    public function edit(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $oldValues = [
            'from_name' => $tenant->getEmailFromName(),
            'from_address' => $tenant->getEmailFromAddress(),
            'logo_url' => $tenant->getEmailLogoUrl(),
            'footer_text' => $tenant->getEmailFooterText(),
            'support_address' => $tenant->getEmailSupportAddress(),
        ];

        $form = $this->createForm(TenantEmailBrandingType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();
            $this->resolver->invalidate($tenant);

            $newValues = [
                'from_name' => $tenant->getEmailFromName(),
                'from_address' => $tenant->getEmailFromAddress(),
                'logo_url' => $tenant->getEmailLogoUrl(),
                'footer_text' => $tenant->getEmailFooterText(),
                'support_address' => $tenant->getEmailSupportAddress(),
            ];

            $this->auditLogger->logUpdate(
                'Tenant',
                $tenant->getId(),
                $oldValues,
                $newValues,
                'E-Mail-Branding aktualisiert.',
            );

            $this->addFlash('success', 'tenant.email.saved');
            return $this->redirectToRoute('app_admin_tenant_email_branding');
        }

        $effective = $this->resolver->resolveFor($tenant);

        return $this->render('admin/tenant_email_branding/edit.html.twig', [
            'form' => $form,
            'tenant' => $tenant,
            'effective' => $effective,
        ]);
    }
}
