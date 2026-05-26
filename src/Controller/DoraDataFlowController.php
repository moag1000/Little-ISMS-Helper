<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\DoraDataFlow;
use App\Form\DoraDataFlowType;
use App\Repository\DoraDataFlowRepository;
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
 * CRUD controller for {@see DoraDataFlow} — DORA RoI Art. 28 RT_03 data-flows.
 *
 * Module-gated on `nis2_dora` via {@see ModuleGatedControllerTrait}. Every
 * action redirects to the dashboard with a flash when the module is
 * inactive — the entity is regulator-specific and has no value outside
 * DORA scope.
 *
 * Routes are non-locale-prefixed under /dora/data-flow/ to mirror the
 * existing `/dora/*` family ({@see DoraComplianceController},
 * {@see DoraRegisterExportController}). All routes are POST-protected for
 * mutations and CSRF-token-validated.
 */
class DoraDataFlowController extends AbstractController
{
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly DoraDataFlowRepository $dataFlowRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {
    }

    #[Route('/dora/data-flow', name: 'app_dora_data_flow_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $tenant = $this->security->getUser()?->getTenant();
        $flows = $tenant !== null ? $this->dataFlowRepository->findByTenant($tenant) : [];

        return $this->render('dora_data_flow/index.html.twig', [
            'flows' => $flows,
        ]);
    }

    #[Route('/dora/data-flow/new', name: 'app_dora_data_flow_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $flow = new DoraDataFlow();
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && method_exists($user, 'getTenant') && $user->getTenant() !== null) {
            $flow->setTenant($user->getTenant());
        }

        $form = $this->createForm(DoraDataFlowType::class, $flow);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($flow);
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('dora_data_flow.success.created', [], 'dora_data_flow')
            );

            return $this->redirectToRoute('app_dora_data_flow_show', ['id' => $flow->getId()]);
        }

        return $this->render('dora_data_flow/new.html.twig', [
            'flow' => $flow,
            'form' => $form,
        ]);
    }

    #[Route('/dora/data-flow/{id}', name: 'app_dora_data_flow_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(DoraDataFlow $flow): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        return $this->render('dora_data_flow/show.html.twig', [
            'flow' => $flow,
        ]);
    }

    #[Route('/dora/data-flow/{id}/edit', name: 'app_dora_data_flow_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, DoraDataFlow $flow): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        $form = $this->createForm(DoraDataFlowType::class, $flow);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('dora_data_flow.success.updated', [], 'dora_data_flow')
            );

            return $this->redirectToRoute('app_dora_data_flow_show', ['id' => $flow->getId()]);
        }

        return $this->render('dora_data_flow/edit.html.twig', [
            'flow' => $flow,
            'form' => $form,
        ]);
    }

    #[Route('/dora/data-flow/{id}/delete', name: 'app_dora_data_flow_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, DoraDataFlow $flow): Response
    {
        if ($redirect = $this->checkModuleActive('nis2_dora')) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('delete' . $flow->getId(), (string) $request->request->get('_token'))) {
            $this->entityManager->remove($flow);
            $this->entityManager->flush();

            $this->addFlash(
                'success',
                $this->translator->trans('dora_data_flow.success.deleted', [], 'dora_data_flow')
            );
        }

        return $this->redirectToRoute('app_dora_data_flow_index');
    }
}
