<?php

namespace App\Controller;

use App\Form\ApplicationSettingsType;
use App\Form\FeatureSettingsType;
use App\Form\SecuritySettingsType;
use App\Repository\SystemSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

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
        $settings = [
            'default_locale' => $this->systemSettingsRepository->getSetting('application', 'default_locale', 'de'),
            'supported_locales' => $this->systemSettingsRepository->getSetting('application', 'supported_locales', ['de', 'en']),
            'items_per_page' => $this->systemSettingsRepository->getSetting('application', 'items_per_page', 25),
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
                'default_locale',
                $formData['default_locale'],
                false,
                'Default application locale',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'application',
                'supported_locales',
                $formData['supported_locales'],
                false,
                'Supported application locales',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'application',
                'items_per_page',
                $formData['items_per_page'],
                false,
                'Items per page in lists',
                $user
            );

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

        return $this->render('admin/settings/application.html.twig', [
            'form' => $form,
        ]);
    }
    #[Route('/admin/settings/security', name: 'admin_settings_security', methods: ['GET', 'POST'])]
    #[IsGranted('ADMIN_EDIT')]
    public function security(Request $request): Response
    {
        $settings = [
            'session_lifetime' => $this->systemSettingsRepository->getSetting('security', 'session_lifetime', 3600),
            'remember_me_lifetime' => $this->systemSettingsRepository->getSetting('security', 'remember_me_lifetime', 2592000),
            'password_min_length' => $this->systemSettingsRepository->getSetting('security', 'password_min_length', 8),
            'require_2fa' => $this->systemSettingsRepository->getSetting('security', 'require_2fa', false),
            'max_login_attempts' => $this->systemSettingsRepository->getSetting('security', 'max_login_attempts', 5),
            'lockout_duration' => $this->systemSettingsRepository->getSetting('security', 'lockout_duration', 900),
        ];

        $form = $this->createForm(SecuritySettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $user = $this->getUser()?->getUserIdentifier();

            $this->systemSettingsRepository->setSetting(
                'security',
                'session_lifetime',
                $formData['session_lifetime'],
                false,
                'Session lifetime in seconds',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'security',
                'remember_me_lifetime',
                $formData['remember_me_lifetime'],
                false,
                'Remember me lifetime in seconds',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'security',
                'password_min_length',
                $formData['password_min_length'],
                false,
                'Minimum password length',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'security',
                'require_2fa',
                $formData['require_2fa'],
                false,
                'Require two-factor authentication',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'security',
                'max_login_attempts',
                $formData['max_login_attempts'],
                false,
                'Maximum login attempts before lockout',
                $user
            );

            $this->systemSettingsRepository->setSetting(
                'security',
                'lockout_duration',
                $formData['lockout_duration'],
                false,
                'Account lockout duration in seconds',
                $user
            );

            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_security');
        }

        return $this->render('admin/settings/security.html.twig', [
            'form' => $form,
        ]);
    }
    #[Route('/admin/settings/features', name: 'admin_settings_features', methods: ['GET', 'POST'])]
    #[IsGranted('ADMIN_EDIT')]
    public function features(Request $request): Response
    {
        $settings = [
            'enable_dark_mode' => $this->systemSettingsRepository->getSetting('features', 'enable_dark_mode', true),
            'enable_global_search' => $this->systemSettingsRepository->getSetting('features', 'enable_global_search', true),
            'enable_quick_view' => $this->systemSettingsRepository->getSetting('features', 'enable_quick_view', true),
            'enable_notifications' => $this->systemSettingsRepository->getSetting('features', 'enable_notifications', true),
            'enable_audit_log' => $this->systemSettingsRepository->getSetting('features', 'enable_audit_log', true),
        ];

        $form = $this->createForm(FeatureSettingsType::class, $settings);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $formData = $form->getData();
            $user = $this->getUser()?->getUserIdentifier();

            $features = [
                'enable_dark_mode' => 'Enable dark mode',
                'enable_global_search' => 'Enable global search',
                'enable_quick_view' => 'Enable quick view',
                'enable_notifications' => 'Enable notifications',
                'enable_audit_log' => 'Enable audit logging',
            ];

            foreach ($features as $key => $description) {
                $this->systemSettingsRepository->setSetting(
                    'features',
                    $key,
                    $formData[$key],
                    false,
                    $description,
                    $user
                );
            }

            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_features');
        }

        return $this->render('admin/settings/features.html.twig', [
            'form' => $form,
        ]);
    }
}
