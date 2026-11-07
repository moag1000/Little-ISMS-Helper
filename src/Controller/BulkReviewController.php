<?php

declare(strict_types=1);

namespace App\Controller;

use App\Attribute\RequiresModule;
use App\Controller\Trait\BulkActionTrait;
use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Risk;
use App\Entity\Supplier;
use App\Service\Review\PeriodicReviewService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk action "Review bestätigen" of the risk, control (SoA), document and
 * supplier lists: confirms the periodic review of the selected records in
 * one step. The rules (who takes part, next date) live in
 * {@see PeriodicReviewService}; this controller only does CSRF, role,
 * module gate and tenant scope.
 *
 * Body: { ids: [..], _token } — shared `bulk_action` CSRF token.
 * Returns: { ok, changed, rejected: [{id, reason}], batch_id }.
 */
final class BulkReviewController extends AbstractController
{
    use BulkActionTrait;

    public function __construct(
        private readonly PeriodicReviewService $periodicReviewService,
        private readonly EntityManagerInterface $entityManager,
        private readonly TenantContext $tenantContext,
    ) {
    }

    #[Route('/risk/bulk-review', name: 'app_risk_bulk_review', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[RequiresModule('risks')]
    public function risks(Request $request): JsonResponse
    {
        return $this->confirm($request, Risk::class, 'Risk');
    }

    #[Route('/soa/bulk-review', name: 'app_soa_bulk_review', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[RequiresModule('controls')]
    public function controls(Request $request): JsonResponse
    {
        return $this->confirm($request, Control::class, 'Control');
    }

    #[Route('/document/bulk-review', name: 'app_document_bulk_review', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    public function documents(Request $request): JsonResponse
    {
        return $this->confirm($request, Document::class, 'Document');
    }

    #[Route('/supplier/bulk-review', name: 'app_supplier_bulk_review', methods: ['POST'])]
    #[IsGranted('ROLE_MANAGER')]
    #[RequiresModule('suppliers')]
    public function suppliers(Request $request): JsonResponse
    {
        return $this->confirm($request, Supplier::class, 'Supplier');
    }

    /**
     * @param class-string $class
     */
    private function confirm(Request $request, string $class, string $entityType): JsonResponse
    {
        try {
            $data = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $data = null;
        }
        $data = is_array($data) ? $data : [];

        if (!$this->isBulkCsrfTokenValid($request, $data)) {
            return $this->json(['ok' => false, 'error' => 'Invalid CSRF token'], Response::HTTP_FORBIDDEN);
        }

        $ids = [];
        foreach ((array) ($data['ids'] ?? []) as $rawId) {
            if (is_int($rawId) || (is_string($rawId) && ctype_digit($rawId))) {
                $ids[(int) $rawId] = (int) $rawId;
            }
        }
        unset($ids[0]);
        if ($ids === []) {
            return $this->json(['ok' => false, 'error' => 'No items selected'], Response::HTTP_BAD_REQUEST);
        }

        $entities = [];
        $rejected = [];
        foreach ($ids as $id) {
            $entity = $this->entityManager->find($class, $id);
            if ($entity === null) {
                $rejected[] = ['id' => $id, 'reason' => 'not_found'];
                continue;
            }
            if (!$this->isInBulkContextScope($entity, $this->tenantContext)) {
                $rejected[] = ['id' => $id, 'reason' => 'tenant_mismatch'];
                continue;
            }
            $entities[] = $entity;
        }

        try {
            $result = $this->periodicReviewService->confirm($entityType, $entities, $rejected);
        } catch (\Throwable) {
            return $this->json(
                ['ok' => false, 'changed' => 0, 'error' => 'review_failed'],
                Response::HTTP_INTERNAL_SERVER_ERROR,
            );
        }

        return $this->json($result, $result['changed'] === 0 ? Response::HTTP_BAD_REQUEST : Response::HTTP_OK);
    }
}
