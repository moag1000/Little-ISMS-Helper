<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\IctProviderLibraryRepository;
use App\Service\IctProviderLibraryService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F-NEU — browse the curated ICT-provider library and apply an entry to create
 * a pre-filled (DORA-relevant) Supplier for review.
 */
#[IsGranted('ROLE_MANAGER')]
final class IctProviderLibraryController extends AbstractController
{
    public function __construct(
        private readonly IctProviderLibraryRepository $repository,
        private readonly IctProviderLibraryService $service,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/ict-provider-library', name: 'ict_provider_library_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('ict_provider_library/index.html.twig', [
            'entries' => $this->repository->findAllOrdered(),
        ]);
    }

    #[Route('/ict-provider-library/{id}/apply', name: 'ict_provider_library_apply', methods: ['POST'], requirements: ['id' => '\d+'])]
    #[IsCsrfTokenValid('ict_provider_library_apply')]
    public function apply(int $id, Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $entry = $this->repository->find($id);
        if ($entry === null) {
            throw $this->createNotFoundException('Library entry not found.');
        }

        $supplier = $this->service->applyToTenant($entry, $tenant);
        $this->addFlash('success', $this->service->appliedFlash($entry));

        return $this->redirectToRoute('app_supplier_show', [
            'id'      => $supplier->getId(),
            '_locale' => $request->getLocale(),
        ]);
    }
}
