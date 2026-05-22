<?php

declare(strict_types=1);

namespace App\Controller;

use App\Controller\Trait\BulkActionTrait;
use App\Entity\ThreatIntelligence;
use App\Enum\ThreatIntelligenceStatus;
use App\Form\ThreatIntelligenceType;
use App\Repository\ThreatIntelligenceRepository;
use App\Service\AuditLogger;
use App\Service\TenantContext;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Exception;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

class ThreatIntelligenceController extends AbstractController
{
    use BulkActionTrait;

    public function __construct(
        private readonly ThreatIntelligenceRepository $repository,
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
        private readonly Security $security,
        private readonly TenantContext $tenantContext,
        private readonly ?AuditLogger $auditLogger = null,
    ) {}

    #[Route('/threat-intelligence', name: 'app_threat_intelligence_index', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function index(Request $request): Response
    {
        $q          = trim((string) $request->query->get('q', ''));
        $severity   = $request->query->get('severity');
        $threatType = $request->query->get('threatType');
        $status     = $request->query->get('status');

        $user   = $this->security->getUser();
        $tenant = $user?->getTenant();

        $criteria = $tenant ? ['tenant' => $tenant] : [];
        $threats  = $this->repository->findBy($criteria, ['detectionDate' => 'DESC']);

        if ($severity !== null && $severity !== '') {
            $threats = array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getSeverity() === $severity);
        }

        if ($threatType !== null && $threatType !== '') {
            $threats = array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getThreatType() === $threatType);
        }

        if ($status !== null && $status !== '') {
            $threats = array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getStatus() === $status);
        }

        if ($q !== '') {
            $needle  = mb_strtolower($q);
            $threats = array_filter($threats, function (ThreatIntelligence $t) use ($needle): bool {
                $haystack = mb_strtolower(
                    ($t->getTitle() ?? '')
                    . ' ' . ($t->getDescription() ?? '')
                    . ' ' . ($t->getThreatType() ?? '')
                    . ' ' . ($t->getCveId() ?? '')
                    . ' ' . ($t->getSource() ?? '')
                );
                return str_contains($haystack, $needle);
            });
        }

        $threats = array_values($threats);

        $stats = [
            'total'    => count($threats),
            'critical' => count(array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getSeverity() === 'critical')),
            'high'     => count(array_filter($threats, fn(ThreatIntelligence $t): bool => $t->getSeverity() === 'high')),
            'open'     => count(array_filter($threats, fn(ThreatIntelligence $t): bool => !in_array($t->getStatus(), [ThreatIntelligenceStatus::Closed->value, ThreatIntelligenceStatus::Mitigated->value], true))),
        ];

        return $this->render('threat_intelligence/index.html.twig', [
            'threats' => $threats,
            'stats'   => $stats,
        ]);
    }

    #[Route('/threat-intelligence/new', name: 'app_threat_intelligence_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function new(Request $request): Response
    {
        $threat = new ThreatIntelligence();
        $tenant = $this->tenantContext->getCurrentTenant();
        if ($tenant !== null) {
            $threat->setTenant($tenant);
        }

        $form = $this->createForm(ThreatIntelligenceType::class, $threat);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->entityManager->persist($threat);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('threat.action.created_flash', [], 'threat'));
            return $this->redirectToRoute('app_threat_intelligence_show', ['id' => $threat->getId()]);
        }

        return $this->render('threat_intelligence/new.html.twig', [
            'threat' => $threat,
            'form'   => $form,
        ]);
    }

    #[Route('/threat-intelligence/{id}', name: 'app_threat_intelligence_show', requirements: ['id' => '\d+'], methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function show(ThreatIntelligence $threat): Response
    {
        return $this->render('threat_intelligence/show.html.twig', [
            'threat' => $threat,
        ]);
    }

    #[Route('/threat-intelligence/{id}/edit', name: 'app_threat_intelligence_edit', requirements: ['id' => '\d+'], methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function edit(Request $request, ThreatIntelligence $threat): Response
    {
        $form = $this->createForm(ThreatIntelligenceType::class, $threat);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $threat->setUpdatedAt(new DateTimeImmutable());
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('threat.action.updated_flash', [], 'threat'));
            return $this->redirectToRoute('app_threat_intelligence_show', ['id' => $threat->getId()]);
        }

        return $this->render('threat_intelligence/edit.html.twig', [
            'threat' => $threat,
            'form'   => $form,
        ]);
    }

    #[Route('/threat-intelligence/{id}/delete', name: 'app_threat_intelligence_delete', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function delete(Request $request, ThreatIntelligence $threat): Response
    {
        if ($this->isCsrfTokenValid('delete' . $threat->getId(), $request->request->get('_token'))) {
            $this->entityManager->remove($threat);
            $this->entityManager->flush();

            $this->addFlash('success', $this->translator->trans('threat.action.deleted_flash', [], 'threat'));
        }

        return $this->redirectToRoute('app_threat_intelligence_index');
    }

    /**
     * Dependency-check endpoint for the Aurora bulk-delete-confirmation modal.
     * ThreatIntelligence entries have no blocking FK relations — returns empty dependencies.
     */
    #[Route('/threat-intelligence/bulk-delete-check', name: 'app_threat_intelligence_bulk_delete_check', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDeleteCheck(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true) ?? [];
        $ids = (array) ($data['ids'] ?? []);
        return new JsonResponse(['dependencies' => [], 'checked_count' => count($ids)]);
    }

    #[Route('/threat-intelligence/bulk-delete', name: 'app_threat_intelligence_bulk_delete', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function bulkDelete(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids)) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->security->getUser()?->getTenant();
        $deleted = 0;
        $errors = [];

        foreach ($ids as $id) {
            try {
                $threat = $this->repository->find($id);
                if (!$threat) {
                    $errors[] = "ThreatIntelligence ID $id not found";
                    continue;
                }
                if ($tenant && $threat->getTenant() !== $tenant) {
                    $errors[] = "ThreatIntelligence ID $id does not belong to your organization";
                    continue;
                }
                $this->entityManager->remove($threat);
                $deleted++;
            } catch (Exception $e) {
                $errors[] = "Error deleting ThreatIntelligence ID $id: " . $e->getMessage();
            }
        }

        if ($deleted > 0) {
            $this->entityManager->flush();
        }

        return $this->json([
            'success' => $deleted > 0,
            'deleted' => $deleted,
            'errors' => $errors,
            'message' => "$deleted threat intelligence items deleted successfully",
        ]);
    }

    /**
     * Bulk CSV export of selected threat intelligence items.
     * ISO 27001 Cl. 7.5.3 — audit-logged via BulkActionTrait.
     */
    #[Route('/threat-intelligence/bulk-export', name: 'app_threat_intelligence_bulk_export', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function bulkExport(Request $request): StreamedResponse|Response
    {
        $data = json_decode($request->getContent(), true);
        if (!$this->isCsrfTokenValid('bulk_action', (string) ($data['_token'] ?? ''))) {
            return $this->json(['error' => 'Invalid CSRF token'], 403);
        }
        $ids  = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            return $this->json(['error' => 'No items selected'], 400);
        }

        $tenant = $this->tenantContext->getCurrentTenant();

        $threats = [];
        foreach ($ids as $rawId) {
            $threat = $this->repository->find((int) $rawId);
            if ($threat === null) {
                continue;
            }
            if ($tenant !== null && $threat->getTenant() !== $tenant) {
                continue;
            }
            $threats[] = $threat;
        }

        if ($threats === []) {
            return $this->json(['error' => 'No exportable threat intelligence items'], 404);
        }

        $headers = ['ID', 'Title', 'Type', 'Severity', 'Status', 'Source'];

        return $this->streamCsvExport(
            $threats,
            $headers,
            static function (ThreatIntelligence $t): array {
                return [
                    (string) $t->getId(),
                    (string) $t->getTitle(),
                    (string) $t->getThreatType(),
                    (string) $t->getSeverity(),
                    (string) $t->getStatus(),
                    (string) $t->getSource(),
                ];
            },
            'threat-intelligence-export',
            'ThreatIntelligence',
            $this->auditLogger,
        );
    }
}
