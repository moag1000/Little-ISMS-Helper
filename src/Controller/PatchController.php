<?php

namespace App\Controller;

use App\Entity\Patch;
use App\Form\PatchType;
use App\Repository\PatchRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/patch')]
#[IsGranted('ROLE_USER')]
class PatchController extends AbstractController
{
    public function __construct(
        private PatchRepository $patchRepository,
        private EntityManagerInterface $entityManager,
        private TranslatorInterface $translator,
        private Security $security
    ) {}

    #[Route('/', name: 'app_patch_index')]
    public function index(Request $request): Response
    {
        // Get current user's tenant
        $user = $this->security->getUser();
        $tenant = $user?->getTenant();

        // Get view filter parameter
        $view = $request->query->get('view', 'inherited'); // Default: inherited

        // Get patches based on view filter
        if ($tenant) {
            switch ($view) {
                case 'own':
                    $patches = $this->patchRepository->findByTenant($tenant);
                    break;
                case 'subsidiaries':
                    $patches = $this->patchRepository->findByTenantIncludingSubsidiaries($tenant);
                    break;
                case 'inherited':
                default:
                    $patches = $this->patchRepository->findByTenantIncludingParent($tenant);
                    break;
            }

            $inheritanceInfo = [
                'hasParent' => $tenant->getParent() !== null,
                'hasSubsidiaries' => $tenant->getSubsidiaries()->count() > 0,
                'currentView' => $view
            ];
        } else {
            $patches = $this->patchRepository->findAll();
            $inheritanceInfo = [
                'hasParent' => false,
                'hasSubsidiaries' => false,
                'currentView' => 'own'
            ];
        }

        // Statistics
        $deploymentStats = $this->patchRepository->getDeploymentStatistics();
        $pendingPatches = array_filter($patches, fn($p) => in_array($p->getStatus(), ['available', 'tested', 'approved']));

        return $this->render('patch/index.html.twig', [
            'patches' => $patches,
            'deployment_stats' => $deploymentStats,
            'pending_count' => count($pendingPatches),
            'inheritanceInfo' => $inheritanceInfo,
            'currentTenant' => $tenant,
        ]);
    }

    #[Route('/new', name: 'app_patch_new')]
    public function new(Request $request): Response
    {
        $patch = new Patch();
        $form = $this->createForm(PatchType::class, $patch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($patch);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.created'));
            return $this->redirectToRoute('app_patch_show', ['id' => $patch->getId()]);
        }

        return $this->render('patch/new.html.twig', [
            'patch' => $patch,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_patch_show', requirements: ['id' => '\d+'])]
    public function show(Patch $patch): Response
    {
        return $this->render('patch/show.html.twig', [
            'patch' => $patch,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_patch_edit', requirements: ['id' => '\d+'])]
    public function edit(Request $request, Patch $patch): Response
    {
        $form = $this->createForm(PatchType::class, $patch);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.updated'));
            return $this->redirectToRoute('app_patch_show', ['id' => $patch->getId()]);
        }

        return $this->render('patch/edit.html.twig', [
            'patch' => $patch,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_patch_delete', methods: ['POST'])]
    public function delete(Request $request, Patch $patch): Response
    {
        if ($this->isCsrfTokenValid('delete'.$patch->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($patch);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('patch.success.deleted'));
        }

        return $this->redirectToRoute('app_patch_index');
    }
}
