<?php

declare(strict_types=1);

namespace App\Controller;

use App\Entity\GuidedTourStepOverride;
use App\Entity\User;
use App\Repository\GuidedTourStepOverrideRepository;
use App\Service\GuidedTourService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Sprint 13 / P5 — Admin-UI für Tenant-spezifische Tour-Content-Overrides.
 *
 *   GET  /admin/tours/content                 Übersicht aller Touren
 *   GET  /admin/tours/content/{tourId}        Step-Liste einer Tour mit Edit-Form
 *   POST /admin/tours/content/{tourId}/save   Bulk-Save aller Overrides einer Tour
 *   POST /admin/tours/content/{tourId}/reset  Alle Overrides dieser Tour löschen
 *
 * Default-Texte kommen aus `translations/guided_tour.{de,en}.yaml`. Admin
 * sieht beides nebeneinander und kann Override pro (Tour, Step, Locale)
 * speichern. Leer gespeichert = Override entfernen.
 *
 * Scope: tenant-spezifisch für normalen Admin, global (tenant=null) nur
 * für SUPER_ADMIN.
 */
#[IsGranted('ROLE_ADMIN')]
class AdminTourContentController extends AbstractController
{
    private const LOCALES = ['de', 'en'];

    public function __construct(
        private readonly GuidedTourService $tourService,
        private readonly GuidedTourStepOverrideRepository $overrideRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/admin/tours/content', name: 'admin_tour_content_index', methods: ['GET'])]
    public function index(): Response
    {
        $all = $this->tourService->allMeta();
        $tenant = $this->tenantContext->getCurrentTenant();

        // Count Overrides pro Tour für den Index-Badge
        $overrideCounts = [];
        foreach ($all as $meta) {
            $byStep = $this->overrideRepository->indexForTourAndTenant($tenant, $meta['id']);
            $overrideCounts[$meta['id']] = count($byStep);
        }

        return $this->render('admin/tour_content/index.html.twig', [
            'tours' => $all,
            'override_counts' => $overrideCounts,
            'scope_tenant' => $tenant,
            'can_set_global' => $this->isGranted('ROLE_SUPER_ADMIN'),
        ]);
    }

    #[Route('/admin/tours/content/{tourId}', name: 'admin_tour_content_edit', methods: ['GET'], requirements: ['tourId' => '[a-z_]+'])]
    public function edit(string $tourId): Response
    {
        if (!in_array($tourId, GuidedTourService::ALL_TOURS, true)) {
            throw $this->createNotFoundException();
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $steps = $this->tourService->stepsFor($tourId);
        $indexed = $this->overrideRepository->indexForTourAndTenant($tenant, $tourId);

        $rows = [];
        foreach ($steps as $step) {
            foreach (self::LOCALES as $locale) {
                $key = $step['id'] . '|' . $locale;
                $override = $indexed[$key] ?? null;

                $defaultTitle = $this->translator->trans($step['title_key'], [], 'guided_tour', $locale);
                $defaultBody = $this->translator->trans($step['body_key'], [], 'guided_tour', $locale);

                $rows[] = [
                    'step_id' => $step['id'],
                    'locale' => $locale,
                    'default_title' => $defaultTitle,
                    'default_body' => $defaultBody,
                    'override_title' => $override?->getTitleOverride(),
                    'override_body' => $override?->getBodyOverride(),
                    'override_tenant_scope' => $override?->getTenant() !== null,
                    'updated_at' => $override?->getUpdatedAt(),
                    'updated_by' => $override?->getUpdatedByEmail(),
                ];
            }
        }

        return $this->render('admin/tour_content/edit.html.twig', [
            'tour_id' => $tourId,
            'tour_meta' => $this->tourService->metaFor($tourId),
            'rows' => $rows,
            'scope_tenant' => $tenant,
            'can_set_global' => $this->isGranted('ROLE_SUPER_ADMIN'),
        ]);
    }

    #[Route('/admin/tours/content/{tourId}/save', name: 'admin_tour_content_save', methods: ['POST'], requirements: ['tourId' => '[a-z_]+'])]
    public function save(string $tourId, Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_tour_content_' . $tourId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!in_array($tourId, GuidedTourService::ALL_TOURS, true)) {
            throw $this->createNotFoundException();
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $useGlobal = $this->isGranted('ROLE_SUPER_ADMIN') && $request->request->getBoolean('scope_global');
        $scopeTenant = $useGlobal ? null : $tenant;

        /** @var User|null $user */
        $user = $this->getUser();
        $userEmail = $user?->getUserIdentifier();

        $titles = (array) $request->request->all('title');
        $bodies = (array) $request->request->all('body');

        $changed = 0;
        $removed = 0;

        foreach ($titles as $key => $title) {
            [$stepId, $locale] = explode('|', (string) $key) + [null, null];
            if ($stepId === null || !in_array($locale, self::LOCALES, true)) {
                continue;
            }

            $title = trim((string) $title);
            $body = trim((string) ($bodies[$key] ?? ''));

            $existing = $this->overrideRepository->findOneBy([
                'tenant' => $scopeTenant,
                'tourId' => $tourId,
                'stepId' => $stepId,
                'locale' => $locale,
            ]);

            if ($title === '' && $body === '') {
                if ($existing instanceof GuidedTourStepOverride) {
                    $this->entityManager->remove($existing);
                    $removed++;
                }
                continue;
            }

            if (!$existing instanceof GuidedTourStepOverride) {
                $existing = (new GuidedTourStepOverride())
                    ->setTenant($scopeTenant)
                    ->setTourId($tourId)
                    ->setStepId($stepId)
                    ->setLocale($locale);
                $this->entityManager->persist($existing);
            }

            $existing
                ->setTitleOverride($title !== '' ? $title : null)
                ->setBodyOverride($body !== '' ? $body : null)
                ->setUpdatedByEmail($userEmail)
                ->touchUpdatedAt();
            $changed++;
        }

        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.tour_content.flash.saved', [
            '%changed%' => $changed,
            '%removed%' => $removed,
        ], 'admin'));

        return $this->redirectToRoute('admin_tour_content_edit', ['tourId' => $tourId]);
    }

    #[Route('/admin/tours/content/{tourId}/reset', name: 'admin_tour_content_reset', methods: ['POST'], requirements: ['tourId' => '[a-z_]+'])]
    public function resetTour(string $tourId, Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->isCsrfTokenValid('admin_tour_content_reset_' . $tourId, $token)) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }
        if (!in_array($tourId, GuidedTourService::ALL_TOURS, true)) {
            throw $this->createNotFoundException();
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        $existing = $this->overrideRepository->findBy([
            'tenant' => $tenant,
            'tourId' => $tourId,
        ]);
        foreach ($existing as $row) {
            $this->entityManager->remove($row);
        }
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('admin.tour_content.flash.reset', [
            '%count%' => count($existing),
        ], 'admin'));

        return $this->redirectToRoute('admin_tour_content_edit', ['tourId' => $tourId]);
    }
}
