<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\DoraSubcontractor;
use App\Form\DoraSubcontractorType;
use App\Repository\DoraSubcontractorRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * CRUD controller for DORA Art. 28 subcontractor-chain entries (RT_04).
 *
 * Gated behind the `nis2_dora` module; only tenants with active DORA scope see
 * these routes.
 */
class DoraSubcontractorController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly DoraSubcontractorRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    #[Route('/dora/subcontractor', name: 'app_dora_subcontractor_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->security->getUser()?->getTenant();
        $subcontractors = $tenant
            ? $this->repository->findByTenant($tenant)
            : [];

        // Group flat list into supplier → tier-2 roots → recursive children
        // so the template can render a forest of trees, one per prime.
        $tree = $this->buildTree($subcontractors);

        return $this->render('dora_subcontractor/index.html.twig', [
            'subcontractors' => $subcontractors,
            'tree' => $tree,
        ]);
    }

    #[Route('/dora/subcontractor/new', name: 'app_dora_subcontractor_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $subcontractor = new DoraSubcontractor();

        $user = $this->security->getUser();
        if ($user instanceof UserInterface && method_exists($user, 'getTenant') && $user->getTenant()) {
            $subcontractor->setTenant($user->getTenant());
        }

        $form = $this->createForm(DoraSubcontractorType::class, $subcontractor);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($subcontractor);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'dora_subcontractor.success.created',
                [],
                'dora'
            ));

            return $this->redirectToRoute('app_dora_subcontractor_show', ['id' => $subcontractor->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('dora_subcontractor/new.html.twig', [
            'subcontractor' => $subcontractor,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/dora/subcontractor/{id}', name: 'app_dora_subcontractor_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(DoraSubcontractor $subcontractor): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        return $this->render('dora_subcontractor/show.html.twig', [
            'subcontractor' => $subcontractor,
        ]);
    }

    #[Route('/dora/subcontractor/{id}/edit', name: 'app_dora_subcontractor_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, DoraSubcontractor $subcontractor): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $form = $this->createForm(DoraSubcontractorType::class, $subcontractor);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $subcontractor->setUpdatedAt(new \DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'dora_subcontractor.success.updated',
                [],
                'dora'
            ));

            return $this->redirectToRoute('app_dora_subcontractor_show', ['id' => $subcontractor->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        return $this->render('dora_subcontractor/edit.html.twig', [
            'subcontractor' => $subcontractor,
            'form' => $form,
        ], new Response(status: $status));
    }

    #[Route('/dora/subcontractor/{id}/delete', name: 'app_dora_subcontractor_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, DoraSubcontractor $subcontractor): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('delete' . $subcontractor->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($subcontractor);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans(
                'dora_subcontractor.success.deleted',
                [],
                'dora'
            ));
        }

        return $this->redirectToRoute('app_dora_subcontractor_index');
    }

    /**
     * Groups a flat subcontractor list into a per-supplier forest of trees.
     *
     * @param DoraSubcontractor[] $subcontractors
     * @return array<int, array{supplier: \App\Entity\Supplier, roots: array<int, array{node: DoraSubcontractor, children: array<int, mixed>}>}>
     */
    private function buildTree(array $subcontractors): array
    {
        // Bucket by parent-supplier id.
        $bySupplier = [];
        $byParentId = [];
        foreach ($subcontractors as $sub) {
            $supplier = $sub->getParentSupplier();
            if ($supplier === null) {
                continue;
            }
            $supplierId = $supplier->getId();
            if ($supplierId === null) {
                continue;
            }
            $bySupplier[$supplierId] ??= ['supplier' => $supplier, 'items' => []];
            $bySupplier[$supplierId]['items'][] = $sub;

            $parentId = $sub->getParentSubcontractor()?->getId() ?? 0;
            $byParentId[$parentId][] = $sub;
        }

        $forest = [];
        foreach ($bySupplier as $supplierId => $bucket) {
            $roots = array_values(array_filter(
                $bucket['items'],
                static fn(DoraSubcontractor $s): bool => $s->getParentSubcontractor() === null,
            ));
            $forest[] = [
                'supplier' => $bucket['supplier'],
                'roots' => array_map(
                    fn(DoraSubcontractor $r) => $this->collectChildren($r, $byParentId),
                    $roots,
                ),
            ];
        }

        return $forest;
    }

    /**
     * Recursively materializes a subtree rooted at $node.
     *
     * @param array<int, list<DoraSubcontractor>> $byParentId
     * @return array{node: DoraSubcontractor, children: array<int, mixed>}
     */
    private function collectChildren(DoraSubcontractor $node, array $byParentId): array
    {
        $nodeId = $node->getId() ?? 0;
        $childNodes = $byParentId[$nodeId] ?? [];

        return [
            'node' => $node,
            'children' => array_map(
                fn(DoraSubcontractor $c) => $this->collectChildren($c, $byParentId),
                $childNodes,
            ),
        ];
    }
}
