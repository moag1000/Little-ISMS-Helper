<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module Management - Redirect to Admin Panel
 *
 * Module management has been centralized in the admin panel.
 * All routes redirect to their admin equivalents (GET aliases only; POST
 * actions live on the admin_modules_* routes — a 302 would turn them into GETs).
 */
#[IsGranted('ROLE_ADMIN')]
class ModuleManagementController extends AbstractController
{
    /**
     * Module Overview - Redirect to Admin
     */
    #[Route('/modules', name: 'module_management_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_modules_index');
    }

    /**
     * Module Details - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/details', name: 'module_management_details', methods: ['GET'])]
    public function details(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_details', ['moduleKey' => $moduleKey]);
    }

    /**
     * Export Module Data - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/export', name: 'module_management_export', methods: ['GET'])]
    public function export(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_export', ['moduleKey' => $moduleKey]);
    }
}
