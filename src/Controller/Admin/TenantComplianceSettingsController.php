<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tenant;
use App\Form\Admin\TenantComplianceSettingsType;
use App\Repository\SystemSettingsRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Tier-1 Compliance Settings UI — combines tenant-specific fields
 * (locale, TZ, financial year, DPO contact, TLP) and global security
 * defaults (MFA, password, session) on a single admin page.
 */
#[Route('/admin/tenant/{tenantId}/compliance-settings', name: 'admin_tenant_compliance_settings_', requirements: ['tenantId' => '\d+'])]
#[IsGranted('ROLE_ADMIN')]
final class TenantComplianceSettingsController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly SystemSettingsRepository $systemSettings,
    ) {
    }

    #[Route('', name: 'edit', methods: ['GET', 'POST'])]
    public function edit(int $tenantId, Request $request): Response
    {
        $tenant = $this->em->getRepository(Tenant::class)->find($tenantId);
        if (!$tenant instanceof Tenant) {
            throw $this->createNotFoundException();
        }

        $form = $this->createForm(TenantComplianceSettingsType::class, $tenant);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->em->flush();
            $this->addFlash('success', 'admin.tenant_settings.saved');
            return $this->redirectToRoute('admin_tenant_compliance_settings_edit', [
                '_locale' => $request->getLocale(),
                'tenantId' => $tenant->getId(),
            ]);
        }

        // Globals (read-only display + edit-via SystemSettings page)
        $globals = [
            'mfa_required_roles' => $this->systemSettings->getSetting('security', 'mfa_required_roles', '[]'),
            'password_min_length' => (int) $this->systemSettings->getSetting('security', 'password_min_length', 12),
            'password_require_complexity' => $this->systemSettings->getSetting('security', 'password_require_complexity', 'true') === 'true',
            'password_rotation_days' => (int) $this->systemSettings->getSetting('security', 'password_rotation_days', 0),
            'session_timeout_minutes' => (int) $this->systemSettings->getSetting('security', 'session_timeout_minutes', 60),
        ];

        return $this->render('admin/tenant_compliance_settings/edit.html.twig', [
            'tenant' => $tenant,
            'form' => $form,
            'globals' => $globals,
        ]);
    }
}
