<?php

declare(strict_types=1);

namespace App\Controller\Import;

use App\Service\ModuleConfigurationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Datenschutz bulk-import hub.
 *
 * A single landing page that surfaces every GDPR/privacy entity that can be
 * bulk-imported (VVT/Art. 30, DSR, Consent) as cards, each linking into the
 * shared import wizard. Saves migration work by making all privacy imports
 * discoverable in one place instead of scattered nav links.
 *
 * Module-gated: the whole hub requires the `privacy` module.
 */
// @no-methods-required — class-level path prefix, methods declared per action
#[Route('/privacy/imports')]
#[IsGranted('ROLE_MANAGER')]
final class PrivacyImportHubController extends AbstractController
{
    /**
     * Importable privacy entity types → their import-wizard slug + i18n keys.
     */
    private const IMPORTS = [
        ['slug' => 'processing_activity',   'icon' => 'nav-process',          'label' => 'nav.bulk_import.processing_activities',   'desc' => 'nav.bulk_import.processing_activities_desc'],
        ['slug' => 'data_subject_request',  'icon' => 'nav-people',           'label' => 'nav.bulk_import.data_subject_requests',   'desc' => 'nav.bulk_import.data_subject_requests_desc'],
        ['slug' => 'consent',               'icon' => 'nav-clipboard-check',  'label' => 'nav.bulk_import.consents',                'desc' => 'nav.bulk_import.consents_desc'],
    ];

    public function __construct(
        private readonly ModuleConfigurationService $moduleService,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_privacy_import_hub', methods: ['GET'])]
    public function index(): Response
    {
        if (!$this->moduleService->isModuleActive('privacy')) {
            $this->addFlash('warning', $this->translator->trans('common.module_not_active', [], 'messages'));
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('privacy_import/hub.html.twig', [
            'imports' => self::IMPORTS,
        ]);
    }
}
