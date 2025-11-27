<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Module Management - Redirect to Admin Panel
 *
 * Module management has been centralized in the admin panel.
 * All routes redirect to their admin equivalents.
 */
#[IsGranted('ROLE_ADMIN')]
class ModuleManagementController extends AbstractController
{
    /**
     * Module Overview - Redirect to Admin
     */
    #[Route('/modules/', name: 'module_management_index')]
    public function index(): Response
    {
        return $this->redirectToRoute('admin_modules_index');
    }

    /**
     * Activate Module - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/activate', name: 'module_management_activate', methods: ['POST'])]
    public function activate(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_activate', ['moduleKey' => $moduleKey]);
    }

    /**
     * Deactivate Module - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/deactivate', name: 'module_management_deactivate', methods: ['POST'])]
    public function deactivate(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_deactivate', ['moduleKey' => $moduleKey]);
    }

    /**
     * Module Details - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/details', name: 'module_management_details')]
    public function details(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_details', ['moduleKey' => $moduleKey]);
    }

    /**
     * Import Module Data - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/import-data', name: 'module_management_import_data', methods: ['POST'])]
    public function importData(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_import_data', ['moduleKey' => $moduleKey]);
    }

    /**
     * Export Module Data - Redirect to Admin
     */
    #[Route('/modules/{moduleKey}/export', name: 'module_management_export')]
    public function export(string $moduleKey): Response
    {
        return $this->redirectToRoute('admin_modules_export', ['moduleKey' => $moduleKey]);
    }
}
