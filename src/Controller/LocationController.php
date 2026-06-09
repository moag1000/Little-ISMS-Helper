<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Component\Security\Core\User\UserInterface;
use App\Controller\Trait\InPageFormTrait;
use App\Controller\Trait\ModuleGatedControllerTrait;
use App\Entity\Location;
use App\Form\LocationType;
use App\Repository\LocationRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class LocationController extends AbstractController
{
    use InPageFormTrait;
    use ModuleGatedControllerTrait;

    public function __construct(
        private readonly LocationRepository $locationRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly ModuleConfigurationService $moduleService,
    ) {}
    #[Route('/location', name: 'app_location_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        // Locations & Infrastructure — locations module (ISO 27001 A.7 Physical Controls)
        if ($redirect = $this->checkModuleActive('locations')) {
            return $redirect;
        }

        $tenant = $this->security->getUser()?->getTenant();
        $locations = $tenant ? $this->locationRepository->findBy(['tenant' => $tenant]) : [];
        $topLevel = $this->locationRepository->findTopLevel();

        return $this->render('location/index.html.twig', [
            'locations' => $locations,
            'top_level' => $topLevel,
        ]);
    }
    #[Route('/location/new', name: 'app_location_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        if ($redirect = $this->checkModuleActive('locations')) {
            return $redirect;
        }

        $location = new Location();

        // Set tenant from current user
        $user = $this->security->getUser();
        if ($user instanceof UserInterface && $user->getTenant()) {
            $location->setTenant($user->getTenant());
        }

        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($location);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('location.success.created', [], 'messages'));

            // In-drawer create → Turbo Stream that appends the new row (the
            // drawer controller closes the panel on the successful submit).
            if ($this->isTurboFrameRequest($request)) {
                return $this->turboStreamSave($location, isNew: true);
            }
            return $this->redirectToRoute('app_location_show', ['id' => $location->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('location/_drawer_form.html.twig', [
                'location' => $location,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('location/new.html.twig', [
            'location' => $location,
            'form' => $form,
        ], new Response(status: $status));
    }
    #[Route('/location/{id}', name: 'app_location_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(Request $request, Location $location): Response
    {
        if ($redirect = $this->checkModuleActive('locations')) {
            return $redirect;
        }

        // In-drawer → slim read-only detail; direct URL → full page (fallback).
        if ($this->isTurboFrameRequest($request)) {
            return $this->render('location/_drawer_detail.html.twig', [
                'location' => $location,
            ]);
        }

        $childLocations = $location->getChildLocations();
        $accessLogs = $location->getAccessLogs();
        $assets = $location->getAssets();

        return $this->render('location/show.html.twig', [
            'location' => $location,
            'child_locations' => $childLocations,
            'access_logs' => $accessLogs,
            'assets' => $assets,
        ]);
    }
    #[Route('/location/{id}/edit', name: 'app_location_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Location $location): Response
    {
        if ($redirect = $this->checkModuleActive('locations')) {
            return $redirect;
        }

        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('location.success.updated', [], 'messages'));

            if ($this->isTurboFrameRequest($request)) {
                return $this->turboStreamSave($location, isNew: false);
            }
            return $this->redirectToRoute('app_location_show', ['id' => $location->getId()]);
        }

        $status = ($form->isSubmitted() && !$form->isValid())
            ? Response::HTTP_UNPROCESSABLE_ENTITY
            : Response::HTTP_OK;

        if ($this->isTurboFrameRequest($request)) {
            return $this->render('location/_drawer_form.html.twig', [
                'location' => $location,
                'form' => $form,
            ], new Response(status: $status));
        }

        return $this->render('location/edit.html.twig', [
            'location' => $location,
            'form' => $form,
        ], new Response(status: $status));
    }

    /** Render the row-update Turbo Stream after a successful in-drawer save. */
    private function turboStreamSave(Location $location, bool $isNew): Response
    {
        return $this->render('location/_stream_save.html.twig', [
            'location' => $location,
            'is_new' => $isNew,
        ], new Response(headers: ['Content-Type' => 'text/vnd.turbo-stream.html']));
    }
    #[Route('/location/{id}/delete', name: 'app_location_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Location $location): Response
    {
        if ($redirect = $this->checkModuleActive('locations')) {
            return $redirect;
        }

        if ($this->isCsrfTokenValid('delete'.$location->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($location);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('location.success.deleted', [], 'messages'));
        }

        return $this->redirectToRoute('app_location_index');
    }
}
