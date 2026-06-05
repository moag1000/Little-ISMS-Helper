<?php

declare(strict_types=1);

namespace App\Controller;

use App\Repository\SupplierQuestionnaireRepository;
use App\Repository\SupplierRepository;
use App\Service\SupplierQuestionnaireService;
use App\Service\TenantContext;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * F23 — admin side of the outbound supplier questionnaire: list sent
 * questionnaires + their responses, and send a new one to a supplier.
 */
#[IsGranted('ROLE_MANAGER')]
final class SupplierQuestionnaireController extends AbstractController
{
    /** Default DORA/ISO supplier due-diligence question set. */
    private const DEFAULT_QUESTIONS = [
        ['id' => 'iso27001', 'text' => 'Are you certified to ISO/IEC 27001? If so, please state the certificate scope and validity.'],
        ['id' => 'subprocessors', 'text' => 'Do you use sub-processors / subcontractors for the service provided to us? Please list them.'],
        ['id' => 'data_location', 'text' => 'In which countries is our data stored or processed?'],
        ['id' => 'incident_sla', 'text' => 'What is your security-incident notification SLA towards customers?'],
        ['id' => 'bcm', 'text' => 'Do you maintain a tested business-continuity / disaster-recovery plan (RTO/RPO)?'],
        ['id' => 'exit', 'text' => 'How is data return and deletion handled on contract termination (DORA Art. 28(8))?'],
    ];

    public function __construct(
        private readonly SupplierQuestionnaireRepository $repository,
        private readonly SupplierRepository $supplierRepository,
        private readonly SupplierQuestionnaireService $service,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/supplier-questionnaire', name: 'supplier_questionnaire_index', methods: ['GET'])]
    public function index(): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        return $this->render('supplier_questionnaire/index.html.twig', [
            'questionnaires' => $this->repository->findByTenant($tenant),
            'suppliers'      => $this->supplierRepository->findBy(['tenant' => $tenant], ['name' => 'ASC']),
        ]);
    }

    #[Route('/supplier-questionnaire/create', name: 'supplier_questionnaire_create', methods: ['POST'])]
    #[IsCsrfTokenValid('supplier_questionnaire_create')]
    public function create(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant === null) {
            throw $this->createNotFoundException('No tenant context.');
        }

        $supplierId = (int) $request->request->get('supplier_id');
        $supplier = $this->supplierRepository->find($supplierId);
        if ($supplier === null || $supplier->getTenant()?->getId() !== $tenant->getId()) {
            throw $this->createNotFoundException('Supplier not found.');
        }

        $title = trim((string) $request->request->get('title')) ?: 'Security questionnaire';
        $questionnaire = $this->service->createAndSend($tenant, $supplier, $title, self::DEFAULT_QUESTIONS);

        $link = $this->generateUrl(
            'public_supplier_questionnaire_show',
            ['token' => $questionnaire->getPublicToken()],
            \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL,
        );
        $this->addFlash('success', $link);

        return $this->redirectToRoute('supplier_questionnaire_index', ['_locale' => $request->getLocale()]);
    }
}
