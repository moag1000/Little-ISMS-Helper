<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Entity\TransferImpactAssessment;
use App\Repository\TransferImpactAssessmentRepository;
use App\Service\TenantContext;
use App\Util\ListPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * All transfer impact assessments of the organisation on one list (GDPR
 * Art. 46, Schrems II), beside the per-processing-activity list, and a stable
 * record link for reminders (`/tia/{id}` → the TIA under its processing activity).
 */
#[IsGranted('ROLE_USER')]
#[RequiresModule('privacy')]
final class TransferImpactAssessmentOverviewController extends AbstractController
{
    public function __construct(
        private readonly TransferImpactAssessmentRepository $tiaRepository,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/tia', name: 'app_tia_overview', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $tenant = $this->tenantContext->getCurrentTenant();
        $tias = $tenant === null ? [] : $this->tiaRepository->findByTenant($tenant);
        $status = (string) $request->query->get('status', '');
        if (in_array($status, ['draft', 'assessed', 'review_due'], true)) {
            $today = new \DateTimeImmutable('today');
            $tias = array_values(array_filter($tias, static fn(TransferImpactAssessment $t): bool => $status === 'review_due'
                ? $t->getStatus() === 'assessed' && $t->getNextReviewDate() !== null && $t->getNextReviewDate() <= $today
                : $t->getStatus() === $status));
        }

        return $this->render('tia/overview.html.twig', [
            'status' => $status,
            'page' => ListPage::fromRequest(
                $tias,
                $request,
                static fn(TransferImpactAssessment $t): array => [
                    $t->getRecipientName(),
                    $t->getDestinationCountry(),
                    $t->getProcessingActivity()?->getName(),
                ],
            ),
        ]);
    }

    /** Record link of a TIA (reminders, search): its page under the processing activity. */
    #[Route('/tia/{id}', name: 'app_tia_open', requirements: ['id' => '\d+'], methods: ['GET'])]
    public function open(int $id): Response
    {
        $tia = $this->tiaRepository->find($id);
        $tenant = $this->tenantContext->getCurrentTenant();
        if (!$tia instanceof TransferImpactAssessment || $tenant === null || $tia->getTenant() !== $tenant) {
            throw $this->createNotFoundException('TIA not found.');
        }
        $activity = $tia->getProcessingActivity();
        if ($activity === null) {
            // The processing activity was deleted; the TIA stays as evidence on the list.
            return $this->redirectToRoute('app_tia_overview', ['q' => $tia->getRecipientName()]);
        }

        return $this->redirectToRoute('app_tia_show', ['paId' => $activity->getId(), 'id' => $tia->getId()]);
    }
}
