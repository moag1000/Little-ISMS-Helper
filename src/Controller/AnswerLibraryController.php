<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\CurrentUserTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\AnswerLibraryEntry;
use App\Form\AnswerLibraryEntryType;
use App\Repository\AnswerLibraryEntryRepository;
use App\Service\AnswerLibraryService;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * F44 — Inbound Security-Questionnaire Answer Library controller.
 *
 * Routes are locale-prefixed (/{_locale}/answer-library/…) via the
 * `app_routes` group in config/routes.yaml.
 *
 * Module gate: answer_library — checked at the top of every action.
 * Minimum role: ROLE_USER (read/use), ROLE_MANAGER (create/edit/delete).
 */
#[Route('/answer-library', name: 'app_answer_library_')]
#[IsGranted('ROLE_USER')]
class AnswerLibraryController extends AbstractController
{
    use CurrentUserTrait;
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly AnswerLibraryService          $answerLibraryService,
        private readonly AnswerLibraryEntryRepository  $repository,
        private readonly TenantContext                 $tenantContext,
        private readonly ModuleConfigurationService    $moduleService,
        private readonly TranslatorInterface           $translator,
    ) {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Index — list + search / filter
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('answer_library')) {
            return $redirect;
        }

        $tenant  = $this->tenantContext->getCurrentTenant();
        $keyword = (string) $request->query->get('q', '');
        $category = (string) $request->query->get('category', '');

        $entries = $tenant
            ? $this->answerLibraryService->search($tenant, $keyword, $category ?: null)
            : [];

        $totalEntries = $tenant ? $this->repository->countByTenant($tenant) : 0;
        $totalReuses  = $tenant ? $this->repository->sumUseCountByTenant($tenant) : 0;

        return $this->render('answer_library/index.html.twig', [
            'entries'       => $entries,
            'keyword'       => $keyword,
            'category'      => $category,
            'categories'    => AnswerLibraryEntry::VALID_CATEGORIES,
            'total_entries' => $totalEntries,
            'total_reuses'  => $totalReuses,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // New — ROLE_MANAGER required for mutations
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('answer_library')) {
            return $redirect;
        }

        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            $this->addFlash('danger', $this->translator->trans('common.tenant_required', [], 'messages'));
            return $this->redirectToRoute('app_answer_library_index', ['_locale' => $request->getLocale()]);
        }

        $entry = new AnswerLibraryEntry();
        $form  = $this->createForm(AnswerLibraryEntryType::class, $entry);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Resolve tags from the unmapped text field (comma-separated input)
            $rawTags = (string) $form->get('tags')->getData();
            $tags    = $this->parseTags($rawTags);

            $this->answerLibraryService->createEntry(
                tenant:    $tenant,
                createdBy: $this->currentUser(),
                question:  $entry->getQuestion(),
                answer:    $entry->getAnswer(),
                category:  $entry->getCategory(),
                tags:      $tags,
            );

            $this->addFlash('success', $this->translator->trans('answer_library.flash.created', [], 'answer_library'));
            return $this->redirectToRoute('app_answer_library_index', ['_locale' => $request->getLocale()]);
        }

        return $this->render('answer_library/new.html.twig', [
            'form'  => $form,
            'entry' => $entry,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Edit
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, AnswerLibraryEntry $entry): Response
    {
        if ($redirect = $this->checkModuleActive('answer_library')) {
            return $redirect;
        }

        $this->assertTenantOwnership($entry);

        $form = $this->createForm(AnswerLibraryEntryType::class, $entry);

        // Pre-fill the unmapped tags field with the current values
        $form->get('tags')->setData(implode(', ', $entry->getTags()));

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $rawTags = (string) $form->get('tags')->getData();
            $tags    = $this->parseTags($rawTags);

            $this->answerLibraryService->updateEntry(
                entry:    $entry,
                question: $entry->getQuestion(),
                answer:   $entry->getAnswer(),
                category: $entry->getCategory(),
                tags:     $tags,
            );

            $this->addFlash('success', $this->translator->trans('answer_library.flash.updated', [], 'answer_library'));
            return $this->redirectToRoute('app_answer_library_index', ['_locale' => $request->getLocale()]);
        }

        return $this->render('answer_library/edit.html.twig', [
            'form'  => $form,
            'entry' => $entry,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Show
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(AnswerLibraryEntry $entry): Response
    {
        if ($redirect = $this->checkModuleActive('answer_library')) {
            return $redirect;
        }

        $this->assertTenantOwnership($entry);

        return $this->render('answer_library/show.html.twig', [
            'entry' => $entry,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Use / Copy — records reuse and returns the answer text (JSON or redirect)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Records reuse of an answer entry (increments useCount + FTE metric).
     *
     * Accepts both JSON (XHR copy-button) and form-POST (non-JS fallback).
     * POST-only to prevent accidental GET-triggered reuse via browser prefetch.
     */
    #[Route('/{id}/use', name: 'use', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('answer_library_use_{id}')]
    public function use(Request $request, AnswerLibraryEntry $entry): Response
    {
        if ($redirect = $this->checkModuleActive('answer_library')) {
            return $redirect;
        }

        $this->assertTenantOwnership($entry);

        $this->answerLibraryService->recordReuse($entry, $this->currentUser());

        // XHR / JSON response — returns the answer text for copy-to-clipboard
        if ($request->isXmlHttpRequest() || $request->getPreferredFormat() === 'json') {
            return new JsonResponse([
                'success'   => true,
                'answer'    => $entry->getAnswer(),
                'use_count' => $entry->getUseCount(),
            ]);
        }

        $this->addFlash('success', $this->translator->trans('answer_library.flash.reused', [], 'answer_library'));
        return $this->redirectToRoute('app_answer_library_index', ['_locale' => $request->getLocale()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Delete
    // ─────────────────────────────────────────────────────────────────────────

    #[Route('/{id}', name: 'delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_MANAGER')]
    #[IsCsrfTokenValid('delete_{id}')]
    public function delete(Request $request, AnswerLibraryEntry $entry): Response
    {
        if ($redirect = $this->checkModuleActive('answer_library')) {
            return $redirect;
        }

        $this->assertTenantOwnership($entry);

        $this->answerLibraryService->deleteEntry($entry);

        $this->addFlash('success', $this->translator->trans('answer_library.flash.deleted', [], 'answer_library'));
        return $this->redirectToRoute('app_answer_library_index', ['_locale' => $request->getLocale()]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Assert that the entry belongs to the current tenant.
     * Throws 403 on mismatch — prevents cross-tenant data leakage.
     */
    private function assertTenantOwnership(AnswerLibraryEntry $entry): void
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        if ($tenant === null || $entry->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createAccessDeniedException(
                $this->translator->trans('common.access_denied', [], 'messages')
            );
        }
    }

    /**
     * Parse a comma-separated tag string into a clean list.
     *
     * @return list<string>
     */
    private function parseTags(string $rawTags): array
    {
        if ($rawTags === '') {
            return [];
        }

        return array_values(
            array_unique(
                array_filter(
                    array_map('trim', explode(',', $rawTags)),
                    static fn (string $s): bool => $s !== '',
                )
            )
        );
    }
}
