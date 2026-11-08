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
use Symfony\Component\ExpressionLanguage\Expression;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Bulk action "Review bestätigen" of the risk, control (SoA), document and
 * supplier lists: confirms the periodic review of the selected records in
 * one step. The rules (who takes part, next date) live in
 * {@see PeriodicReviewService}; this controller only does CSRF, role,
 * module gate and tenant scope. Every user may call them; PeriodicReviewService
 * admits per record: risks and controls through the edit voter (managers and
 * owners), documents and suppliers for managers and whoever the review
 * reminder reaches (RECORD_RESPONSIBLE).
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
    // Owners confirm their own records: PeriodicReviewService checks the edit voter per record.
    #[IsGranted('ROLE_USER')]
    #[RequiresModule('risks')]
    public function risks(Request $request): JsonResponse
    {
        return $this->confirm($request, Risk::class, 'Risk');
    }

    #[Route('/soa/bulk-review', name: 'app_soa_bulk_review', methods: ['POST'])]
    // Owners confirm their own records: PeriodicReviewService checks the edit voter per record.
    #[IsGranted('ROLE_USER')]
    #[RequiresModule('controls')]
    public function controls(Request $request): JsonResponse
    {
        return $this->confirm($request, Control::class, 'Control');
    }

    #[Route('/document/bulk-review', name: 'app_document_bulk_review', methods: ['POST'])]
    // Managers and the responsible person per record: PeriodicReviewService::mayConfirm().
    #[IsGranted('ROLE_USER')]
    public function documents(Request $request): JsonResponse
    {
        return $this->confirm($request, Document::class, 'Document');
    }

    #[Route('/supplier/bulk-review', name: 'app_supplier_bulk_review', methods: ['POST'])]
    // Managers and the responsible person per record: PeriodicReviewService::mayConfirm().
    #[IsGranted('ROLE_USER')]
    #[RequiresModule('suppliers')]
    public function suppliers(Request $request): JsonResponse
    {
        return $this->confirm($request, Supplier::class, 'Supplier');
    }

    /**
     * Single-record "Review bestätigen" on the detail page: the review reminder
     * of a control or risk links there, and its owner confirms it in one step
     * (W1). Same rules as the bulk action; the `edit` voter admits managers and
     * whoever is responsible for the record.
     */
    #[Route('/soa/{id}/review', name: 'app_soa_review_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('edit', subject: 'control')]
    #[IsCsrfTokenValid(new Expression('"review_confirm_" ~ args["control"].getId()'))]
    #[RequiresModule('controls')]
    public function confirmControl(Control $control): Response
    {
        return $this->confirmOne($control, 'Control', 'app_soa_show');
    }

    #[Route('/risk/{id}/review', name: 'app_risk_review_confirm', requirements: ['id' => '\d+'], methods: ['POST'])]
    #[IsGranted('edit', subject: 'risk')]
    #[IsCsrfTokenValid(new Expression('"review_confirm_" ~ args["risk"].getId()'))]
    #[RequiresModule('risks')]
    public function confirmRisk(Risk $risk): Response
    {
        return $this->confirmOne($risk, 'Risk', 'app_risk_show');
    }

    private function confirmOne(Control|Risk $entity, string $entityType, string $showRoute): Response
    {
        $result = $this->periodicReviewService->confirm($entityType, [$entity]);
        $this->addFlash(
            $result['changed'] > 0 ? 'success' : 'warning',
            $result['changed'] > 0 ? 'periodic_review.flash.confirmed' : 'periodic_review.flash.not_reviewable',
        );

        return $this->redirectToRoute($showRoute, ['id' => $entity->getId()]);
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
