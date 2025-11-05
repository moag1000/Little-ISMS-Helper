<?php

namespace App\Controller;

use App\Repository\InternalAuditRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/audit')]
class AuditController extends AbstractController
{
    public function __construct(private InternalAuditRepository $auditRepository) {}

    #[Route('/', name: 'app_audit_index')]
    public function index(): Response
    {
        $audits = $this->auditRepository->findAll();
        $upcoming = $this->auditRepository->findUpcoming();

        return $this->render('audit/index.html.twig', [
            'audits' => $audits,
            'upcoming' => $upcoming,
        ]);
    }
}
