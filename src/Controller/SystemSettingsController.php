<?php

namespace App\Controller;

use App\Entity\SystemSettings;
use App\Repository\SystemSettingsRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/admin/settings')]
#[IsGranted('ROLE_ADMIN')]
class SystemSettingsController extends AbstractController
{
    public function __construct(
        private SystemSettingsRepository $settingsRepository
    ) {
    }

    #[Route('', name: 'admin_settings_index', methods: ['GET'])]
    public function index(): Response
    {
        // Get all settings grouped by category
        $allSettings = $this->settingsRepository->getAllSettingsArray();

        return $this->render('admin/settings/index.html.twig', [
            'settings' => $allSettings,
        ]);
    }

    #[Route('/application', name: 'admin_settings_application', methods: ['GET', 'POST'])]
    public function application(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->handleApplicationSettingsSave($request);
            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_application');
        }

        $settings = [
            'default_locale' => $this->settingsRepository->getSetting('application', 'default_locale', 'de'),
            'supported_locales' => $this->settingsRepository->getSetting('application', 'supported_locales', ['de', 'en']),
            'items_per_page' => $this->settingsRepository->getSetting('application', 'items_per_page', 25),
            'timezone' => $this->settingsRepository->getSetting('application', 'timezone', 'Europe/Berlin'),
            'date_format' => $this->settingsRepository->getSetting('application', 'date_format', 'd.m.Y'),
            'datetime_format' => $this->settingsRepository->getSetting('application', 'datetime_format', 'd.m.Y H:i'),
        ];

        return $this->render('admin/settings/application.html.twig', [
            'settings' => $settings,
        ]);
    }

    #[Route('/security', name: 'admin_settings_security', methods: ['GET', 'POST'])]
    public function security(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->handleSecuritySettingsSave($request);
            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_security');
        }

        $settings = [
            'session_lifetime' => $this->settingsRepository->getSetting('security', 'session_lifetime', 3600),
            'remember_me_lifetime' => $this->settingsRepository->getSetting('security', 'remember_me_lifetime', 2592000),
            'password_min_length' => $this->settingsRepository->getSetting('security', 'password_min_length', 8),
            'require_2fa' => $this->settingsRepository->getSetting('security', 'require_2fa', false),
            'max_login_attempts' => $this->settingsRepository->getSetting('security', 'max_login_attempts', 5),
            'lockout_duration' => $this->settingsRepository->getSetting('security', 'lockout_duration', 900),
        ];

        return $this->render('admin/settings/security.html.twig', [
            'settings' => $settings,
        ]);
    }

    #[Route('/features', name: 'admin_settings_features', methods: ['GET', 'POST'])]
    public function features(Request $request): Response
    {
        if ($request->isMethod('POST')) {
            $this->handleFeatureSettingsSave($request);
            $this->addFlash('success', 'admin.settings.saved');
            return $this->redirectToRoute('admin_settings_features');
        }

        $settings = [
            'enable_dark_mode' => $this->settingsRepository->getSetting('features', 'enable_dark_mode', true),
            'enable_global_search' => $this->settingsRepository->getSetting('features', 'enable_global_search', true),
            'enable_quick_view' => $this->settingsRepository->getSetting('features', 'enable_quick_view', true),
            'enable_notifications' => $this->settingsRepository->getSetting('features', 'enable_notifications', true),
            'enable_audit_log' => $this->settingsRepository->getSetting('features', 'enable_audit_log', true),
        ];

        return $this->render('admin/settings/features.html.twig', [
            'settings' => $settings,
        ]);
    }

    private function handleApplicationSettingsSave(Request $request): void
    {
        $user = $this->getUser()?->getUserIdentifier();

        // Save application settings
        $this->settingsRepository->setSetting(
            'application',
            'default_locale',
            $request->request->get('default_locale', 'de'),
            false,
            'Default application locale',
            $user
        );

        $supportedLocales = $request->request->all('supported_locales') ?: ['de', 'en'];
        $this->settingsRepository->setSetting(
            'application',
            'supported_locales',
            $supportedLocales,
            false,
            'Supported application locales',
            $user
        );

        $this->settingsRepository->setSetting(
            'application',
            'items_per_page',
            (int) $request->request->get('items_per_page', 25),
            false,
            'Items per page in lists',
            $user
        );

        $this->settingsRepository->setSetting(
            'application',
            'timezone',
            $request->request->get('timezone', 'Europe/Berlin'),
            false,
            'Application timezone',
            $user
        );

        $this->settingsRepository->setSetting(
            'application',
            'date_format',
            $request->request->get('date_format', 'd.m.Y'),
            false,
            'Date format',
            $user
        );

        $this->settingsRepository->setSetting(
            'application',
            'datetime_format',
            $request->request->get('datetime_format', 'd.m.Y H:i'),
            false,
            'DateTime format',
            $user
        );
    }

    private function handleSecuritySettingsSave(Request $request): void
    {
        $user = $this->getUser()?->getUserIdentifier();

        $this->settingsRepository->setSetting(
            'security',
            'session_lifetime',
            (int) $request->request->get('session_lifetime', 3600),
            false,
            'Session lifetime in seconds',
            $user
        );

        $this->settingsRepository->setSetting(
            'security',
            'remember_me_lifetime',
            (int) $request->request->get('remember_me_lifetime', 2592000),
            false,
            'Remember me lifetime in seconds',
            $user
        );

        $this->settingsRepository->setSetting(
            'security',
            'password_min_length',
            (int) $request->request->get('password_min_length', 8),
            false,
            'Minimum password length',
            $user
        );

        $this->settingsRepository->setSetting(
            'security',
            'require_2fa',
            (bool) $request->request->get('require_2fa', false),
            false,
            'Require two-factor authentication',
            $user
        );

        $this->settingsRepository->setSetting(
            'security',
            'max_login_attempts',
            (int) $request->request->get('max_login_attempts', 5),
            false,
            'Maximum login attempts before lockout',
            $user
        );

        $this->settingsRepository->setSetting(
            'security',
            'lockout_duration',
            (int) $request->request->get('lockout_duration', 900),
            false,
            'Account lockout duration in seconds',
            $user
        );
    }

    private function handleFeatureSettingsSave(Request $request): void
    {
        $user = $this->getUser()?->getUserIdentifier();

        $features = [
            'enable_dark_mode' => 'Enable dark mode',
            'enable_global_search' => 'Enable global search',
            'enable_quick_view' => 'Enable quick view',
            'enable_notifications' => 'Enable notifications',
            'enable_audit_log' => 'Enable audit logging',
        ];

        foreach ($features as $key => $description) {
            $this->settingsRepository->setSetting(
                'features',
                $key,
                (bool) $request->request->get($key, false),
                false,
                $description,
                $user
            );
        }
    }
}
