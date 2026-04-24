<?php

declare(strict_types=1);

namespace App\Controller\Setup;

use App\Form\Setup\ExistingFrameworksType;
use App\Service\ComplianceFrameworkLoaderService;
use App\Service\Setup\ReuseEstimationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * WS-8: "Was hast du schon?"-Step im Setup-Wizard.
 * Standalone controller so the step survives edits to the big DeploymentWizardController.
 */
#[Route('/setup/existing-frameworks', name: 'setup_wizard_existing_frameworks')]
final class ExistingFrameworksController extends AbstractController
{
    public function __construct(
        private readonly ReuseEstimationService $estimation,
        private readonly ComplianceFrameworkLoaderService $frameworkLoader,
        private readonly CsrfTokenManagerInterface $csrf,
    ) {
    }

    #[Route('', name: '', methods: ['GET', 'POST'])]
    public function index(Request $request): Response
    {
        $form = $this->createForm(ExistingFrameworksType::class, null, [
            'available_frameworks' => $this->frameworkLoader->getAvailableFrameworks(),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $session = $request->getSession();
            $session->set('setup.existing_frameworks', (array) ($data['alreadyCertified'] ?? []));
            $session->set('setup.newly_added_frameworks', (array) ($data['newlyAdded'] ?? []));
            $session->set('setup.certification_dates', (array) ($data['certificationDates'] ?? []));

            $this->addFlash('success', 'setup_wizard.existing_frameworks.saved');
            return $this->redirectToRoute('setup_step8_compliance_frameworks');
        }

        return $this->render('setup_wizard/existing_frameworks.html.twig', [
            'form' => $form->createView(),
            'available_frameworks' => $this->frameworkLoader->getAvailableFrameworks(),
        ]);
    }

    #[Route('/skip', name: '_skip', methods: ['GET', 'POST'])]
    public function skip(Request $request): Response
    {
        $request->getSession()->set('setup.existing_frameworks_skipped', true);
        return $this->redirectToRoute('setup_step8_compliance_frameworks');
    }

    #[Route('/preview', name: '_preview', methods: ['POST'])]
    public function preview(Request $request): Response
    {
        $token = (string) $request->request->get('_token');
        if (!$this->csrf->isTokenValid(new CsrfToken('setup_existing_frameworks_preview', $token))) {
            return new JsonResponse(['error' => 'invalid_csrf'], Response::HTTP_BAD_REQUEST);
        }

        $existing = array_values(array_filter(array_map('strval', (array) $request->request->all('alreadyCertified'))));
        $new = array_values(array_filter(array_map('strval', (array) $request->request->all('newlyAdded'))));

        if ($existing === [] || $new === []) {
            return $this->render('setup_wizard/_existing_frameworks_preview.html.twig', [
                'rows' => [],
                'total_saved_days' => 0.0,
            ]);
        }

        $result = $this->estimation->estimate($existing, $new);

        return $this->render('setup_wizard/_existing_frameworks_preview.html.twig', [
            'rows' => $result['rows'] ?? $result,
            'total_saved_days' => $result['total_saved_days'] ?? 0.0,
        ]);
    }
}
