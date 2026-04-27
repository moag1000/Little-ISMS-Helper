<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Entity\Tag;
use App\Entity\User;
use App\Form\Admin\TagType;
use App\Repository\TagRepository;
use App\Security\Voter\TagVoter;
use App\Service\BulkTagService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Admin CRUD + bulk endpoint for polymorphic tags (WS-5, Anhang C ENT-1).
 *
 * Routes: intentionally NOT prefixed with `/{_locale<...>}` because the global
 * routing configuration already handles locale prefixing (see existing admin
 * controllers such as LoaderFixerController).
 */
#[Route('/admin/tags', name: 'admin_tag_')]
#[IsGranted('ROLE_ADMIN')]
final class TagController extends AbstractController
{
    /**
     * Whitelist of entity classes the bulk endpoint is willing to tag.
     * Short form `Asset` or FQCN both accepted from the URL for convenience.
     */
    private const ALLOWED_ENTITY_CLASSES = [
        'Asset' => 'App\\Entity\\Asset',
        'Control' => 'App\\Entity\\Control',
        'Risk' => 'App\\Entity\\Risk',
        'Supplier' => 'App\\Entity\\Supplier',
    ];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagRepository $tagRepository,
        private readonly BulkTagService $bulkTagService,
        private readonly TenantContext $tenantContext,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $tags = $this->tagRepository->findVisibleFor($tenant);

        return $this->render('admin/tag/index.html.twig', [
            'tags' => $tags,
        ]);
    }

    #[Route('/new', name: 'new', methods: ['GET', 'POST'])]
    #[IsGranted(TagVoter::TAG_MANAGE)]
    public function new(Request $request): Response
    {

        $tag = new Tag();
        $tag->setTenant($this->tenantContext->getCurrentTenant());

        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($tag);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('tags.flash.created', [], 'tags'));
            return $this->redirectToRoute('admin_tag_index');
        }

        return $this->render('admin/tag/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'edit', methods: ['GET', 'POST'], requirements: ['id' => '\d+'])]
    public function edit(Tag $tag, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TagVoter::TAG_MANAGE, $tag);

        $form = $this->createForm(TagType::class, $tag);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('tags.flash.updated', [], 'tags'));
            return $this->redirectToRoute('admin_tag_index');
        }

        return $this->render('admin/tag/edit.html.twig', [
            'form' => $form->createView(),
            'tag' => $tag,
        ]);
    }

    #[Route('/{id}/delete', name: 'delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function delete(Tag $tag, Request $request): Response
    {
        $this->denyAccessUnlessGranted(TagVoter::TAG_MANAGE, $tag);

        if (!$this->isCsrfTokenValid('tag_delete_' . $tag->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('tags.flash.csrf_invalid', [], 'tags'));
            return $this->redirectToRoute('admin_tag_index');
        }

        $this->entityManager->remove($tag);
        $this->entityManager->flush();

        $this->addFlash('success', $this->translator->trans('tags.flash.deleted', [], 'tags'));
        return $this->redirectToRoute('admin_tag_index');
    }

    /**
     * Bulk-apply endpoint called from entity index pages.
     *
     * Request (JSON or form):
     *   entity_ids: int[]
     *   tag_ids:    int[]
     *
     * Returns JSON (for fetch-based Stimulus controllers) with counts.
     */
    #[Route('/bulk/{entityClass}', name: 'bulk_apply', methods: ['POST'], requirements: ['entityClass' => '[A-Za-z\\\\]+'])]
    #[IsGranted(TagVoter::TAG_APPLY)]
    public function bulkApply(string $entityClass, Request $request): Response
    {

        if (!$this->isCsrfTokenValid('tag_bulk_apply', (string) $request->headers->get('X-CSRF-Token', (string) $request->request->get('_token')))) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'csrf_invalid'],
                Response::HTTP_FORBIDDEN,
            );
        }

        $fqcn = $this->resolveEntityClass($entityClass);
        if ($fqcn === null) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'entity_class_not_allowed'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $payload = $this->parseBulkPayload($request);
        $entityIds = $payload['entity_ids'];
        $tagIds = $payload['tag_ids'];

        if ($entityIds === [] || $tagIds === []) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'empty_selection'],
                Response::HTTP_BAD_REQUEST,
            );
        }

        $repository = $this->entityManager->getRepository($fqcn);
        $entities = $repository->findBy(['id' => $entityIds]);

        if ($entities === []) {
            return new JsonResponse(
                ['ok' => false, 'error' => 'entities_not_found'],
                Response::HTTP_NOT_FOUND,
            );
        }

        /** @var User $actor */
        $actor = $this->getUser();
        $result = $this->bulkTagService->applyTags($entities, $tagIds, $actor);

        return new JsonResponse([
            'ok' => true,
            'entity_class' => $fqcn,
            'result' => $result,
        ]);
    }

    /**
     * @return array{entity_ids: list<int>, tag_ids: list<int>}
     */
    private function parseBulkPayload(Request $request): array
    {
        $entityIds = [];
        $tagIds = [];

        if (str_contains((string) $request->headers->get('Content-Type'), 'application/json')) {
            $json = json_decode((string) $request->getContent(), true);
            if (is_array($json)) {
                $entityIds = $json['entity_ids'] ?? [];
                $tagIds = $json['tag_ids'] ?? [];
            }
        } else {
            $entityIds = $request->request->all('entity_ids');
            $tagIds = $request->request->all('tag_ids');
        }

        $entityIds = array_values(array_unique(array_map('intval', (array) $entityIds)));
        $tagIds = array_values(array_unique(array_map('intval', (array) $tagIds)));

        // strip zeros
        $entityIds = array_values(array_filter($entityIds, static fn(int $v): bool => $v > 0));
        $tagIds = array_values(array_filter($tagIds, static fn(int $v): bool => $v > 0));

        return ['entity_ids' => $entityIds, 'tag_ids' => $tagIds];
    }

    private function resolveEntityClass(string $shortOrFqcn): ?string
    {
        $normalised = ltrim($shortOrFqcn, '\\');

        if (isset(self::ALLOWED_ENTITY_CLASSES[$normalised])) {
            return self::ALLOWED_ENTITY_CLASSES[$normalised];
        }

        if (in_array($normalised, self::ALLOWED_ENTITY_CLASSES, true)) {
            return $normalised;
        }

        return null;
    }
}
