<?php

declare(strict_types=1);

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LicenseController extends AbstractController
{
    #[Route('/about/licenses', name: 'app_licenses', methods: ['GET'])]
    public function index(): Response
    {
        $noticeFile = $this->getParameter('kernel.project_dir') . '/NOTICE.md';

        if (!file_exists($noticeFile)) {
            throw new NotFoundHttpException('NOTICE.md file not found');
        }

        $noticeContent = file_get_contents($noticeFile);

        // Parse composer.json for project info
        $composerFile = $this->getParameter('kernel.project_dir') . '/composer.json';
        $composerData = json_decode(file_get_contents($composerFile), true);

        return $this->render('license/index.html.twig', [
            'notice_content' => $noticeContent,
            'project_name' => $composerData['description'] ?? 'Little ISMS Helper',
            'project_license' => $composerData['license'] ?? 'proprietary',
        ]);
    }

    /** Legacy URL; the report lives in the admin area (single copy of the page). */
    #[Route('/about/licenses/report', name: 'app_licenses_report', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function report(): RedirectResponse
    {
        return $this->redirectToRoute('admin_licensing_report', [], Response::HTTP_MOVED_PERMANENTLY);
    }

    /** Legacy URL; the summary lives in the admin area (single copy of the page). */
    #[Route('/about/licenses/summary', name: 'app_licenses_summary', methods: ['GET'])]
    public function summary(): RedirectResponse
    {
        return $this->redirectToRoute('admin_licensing_summary', [], Response::HTTP_MOVED_PERMANENTLY);
    }
}
