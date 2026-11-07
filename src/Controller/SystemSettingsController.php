<?php

declare(strict_types=1);

namespace App\Controller;

use App\Form\ApplicationSettingsType;
use App\Form\SecuritySettingsType;
use App\Repository\SystemSettingsRepository;
use App\Security\Voter\TenantScopedAdminVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * System Settings — application / security / feature flags.
 *
 * Role-Scope: class-level
 * {@see TenantScopedAdminVoter::ADMIN_OWN_TENANT} — tenant admins configure
 * the settings scoped to their own tenant; SUPER_ADMIN passes through and
 * may additionally configure cross-tenant defaults. Existing method-level
 * `ADMIN_VIEW`/`ADMIN_EDIT` permission attributes stay authoritative for
 * read vs. write granularity.
 */
#[IsGranted(TenantScopedAdminVoter::ADMIN_OWN_TENANT)]
class SystemSettingsController extends AbstractController
{
    public function __construct(
        private readonly SystemSettingsRepository $systemSettingsRepository
    ) {
    }
    #[Route('/admin/settings', name: 'admin_settings_index', methods: ['GET'])]
    #[IsGranted('ADMIN_VIEW')]
    public function index(): Response
    {
        // Get all settings grouped by category
        $allSettings = $this->systemSettingsRepository->getAllSettingsArray();

        return $this->render('admin/settings/index.html.twig', [
            'settings' => $allSettings,
        ]);
    }
    #[Route('/admin/settings/application', name: 'admin_settings_application', methods: ['GET', 'POST'])]
    #[IsGranted('ADMIN_EDIT')]
    public function application(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(\App\Security\Voter\TenantScopedAdminVoter::ADMIN_GLOBAL_OP);
        }
        $settings = [
            'timezone' => $this->systemSettingsRepository->getSetting('application', 'timezone', 'Europe/Berlin'),
            'date_format' => $this->systemSettingsRepository->getSetting('application', 'date_format', 'd.m.Y'),
            'datetime_format' => $this->systemSettingsRepository->getSetting('application', 'datetime_format', 'd.m.Y H:i'),
        ];

        $form = $this->createForm(ApplicationSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $user = $this->getUser()?->getUserIdentifier();

            // Save application settings

            $this->systemSettingsRepository->setSetting(
                'application',
                'timezone',
                $formData['timezone'],
                false,
                'Application timezone',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'application',
                'date_format',
                $formData['date_format'],
                false,
                'Date format',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'application',
                'datetime_format',
                $formData['datetime_format'],
                false,
                'DateTime format',
                $user
            );

            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_application');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/settings/application.html.twig', [
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/admin/settings/security', name: 'admin_settings_security', methods: ['GET', 'POST'])]
    #[IsGranted('ADMIN_EDIT')]
    public function security(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->denyAccessUnlessGranted(\App\Security\Voter\TenantScopedAdminVoter::ADMIN_GLOBAL_OP);
        }
        $settings = [
            'password_min_length' => $this->systemSettingsRepository->getSetting('security', 'password_min_length', 8),
        ];

        $form = $this->createForm(SecuritySettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $user = $this->getUser()?->getUserIdentifier();

            $this->systemSettingsRepository->setSetting(
                'security',
                'password_min_length',
                $formData['password_min_length'],
                false,
                'Minimum password length',
                $user
            );

            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_security');
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('admin/settings/security.html.twig', [
            'form' => $form,
        ], new Response(status: $status));
    }
}
