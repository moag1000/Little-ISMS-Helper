<?php

namespace App\Controller;

use App\Entity\Location;
use App\Form\LocationType;
use App\Repository\LocationRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/location')]
class LocationController extends AbstractController
{
    public function __construct(
        private LocationRepository $locationRepository,
        private EntityManagerInterface $entityManager
    ) {}

    #[Route('/', name: 'app_location_index')]
    #[IsGranted('ROLE_USER')]
    public function index(): Response
    {
        $locations = $this->locationRepository->findAll();
        $topLevel = $this->locationRepository->findTopLevel();

        return $this->render('location/index.html.twig', [
            'locations' => $locations,
            'top_level' => $topLevel,
        ]);
    }

    #[Route('/new', name: 'app_location_new')]
    #[IsGranted('ROLE_USER')]
    public function new(Request $request): Response
    {
        $location = new Location();
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($location);
            $this->entityManager->flush();

            $this->addFlash('success', 'Location created successfully.');
            return $this->redirectToRoute('app_location_show', ['id' => $location->getId()]);
        }

        return $this->render('location/new.html.twig', [
            'location' => $location,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_location_show', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function show(Location $location): Response
    {
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

    #[Route('/{id}/edit', name: 'app_location_edit', requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, Location $location): Response
    {
        $form = $this->createForm(LocationType::class, $location);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->flush();

            $this->addFlash('success', 'Location updated successfully.');
            return $this->redirectToRoute('app_location_show', ['id' => $location->getId()]);
        }

        return $this->render('location/edit.html.twig', [
            'location' => $location,
            'form' => $form,
        ]);
    }

    #[Route('/{id}/delete', name: 'app_location_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, Location $location): Response
    {
        if ($this->isCsrfTokenValid('delete'.$location->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($location);
            $this->entityManager->flush();

            $this->addFlash('success', 'Location deleted successfully.');
        }

        return $this->redirectToRoute('app_location_index');
    }
}
