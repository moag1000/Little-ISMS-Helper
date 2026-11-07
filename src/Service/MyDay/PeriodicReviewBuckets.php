<?php

declare(strict_types=1);

namespace App\Service\MyDay;

use App\Entity\Tenant;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\ControlRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\SupplierRepository;
use DateTimeImmutable;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * My Day buckets for records whose periodic review date has passed
 * (control effectiveness, supplier reassessment, DPIA review, BC plan
 * review). Tenant-wide; who exactly is responsible for each record is
 * the record-owner question, not decided here.
 */
final readonly class PeriodicReviewBuckets
{
    public function __construct(
        private ControlRepository $controlRepo,
        private SupplierRepository $supplierRepo,
        private DataProtectionImpactAssessmentRepository $dpiaRepo,
        private BusinessContinuityPlanRepository $bcPlanRepo,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * Controls whose effectiveness test / review date has passed (ISO 27001 9.1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function controlEffectivenessOverdue(Tenant $tenant): array
    {
        $items = [];
        $today = new DateTimeImmutable('today');
        foreach ($this->controlRepo->findBy(['tenant' => $tenant]) as $control) {
            $next = $control->getNextEffectivenessTest() ?? $control->getNextReviewDate();
            if ($next === null || $next >= $today) {
                continue;
            }
            $items[] = [
                'priority'    => 'high',
                'due_date'    => $next,
                'link'        => $this->urls->generate('app_analytics_control_effectiveness'),
                'entity_type' => 'control',
                'tone'        => 'danger',
                'title'       => (string) ($control->getName() ?? ('Control #' . $control->getId())),
                'subtitle'    => 'effectiveness_overdue',
                'badge'       => 'overdue',
            ];
        }
        return $items;
    }

    /**
     * Suppliers whose reassessment date has passed (ISO 27001 A.5.22).
     *
     * @return array<int, array<string, mixed>>
     */
    public function supplierReassessmentDue(Tenant $tenant): array
    {
        $items = [];
        $today = new DateTimeImmutable('today');
        foreach ($this->supplierRepo->findBy(['tenant' => $tenant]) as $supplier) {
            $next = $supplier->getNextAssessmentDate();
            if ($next === null || $next >= $today) {
                continue;
            }
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => $next,
                'link'        => $this->urls->generate('app_supplier_show', ['id' => $supplier->getId()]),
                'entity_type' => 'supplier',
                'tone'        => 'warning',
                'title'       => (string) ($supplier->getName() ?? 'Supplier'),
                'subtitle'    => 'reassessment_due',
                'badge'       => 'overdue',
            ];
        }
        return $items;
    }

    /**
     * DPIAs whose review date has passed (GDPR Art. 35(11)). Draft/archived skip.
     *
     * @return array<int, array<string, mixed>>
     */
    public function dpiaReviewOverdue(Tenant $tenant): array
    {
        $items = [];
        $today = new DateTimeImmutable('today');
        foreach ($this->dpiaRepo->findBy(['tenant' => $tenant]) as $dpia) {
            $next = $dpia->getNextReviewDate();
            if ($next === null || $next >= $today) {
                continue;
            }
            if (in_array($dpia->getStatus(), ['draft', 'archived'], true)) {
                continue;
            }
            $items[] = [
                'priority'    => 'high',
                'due_date'    => $next,
                'link'        => $this->urls->generate('app_dpia_show', ['id' => $dpia->getId()]),
                'entity_type' => 'dpia',
                'tone'        => 'danger',
                'title'       => (string) ($dpia->getTitle() ?? 'DPIA'),
                'subtitle'    => 'review_overdue',
                'badge'       => 'overdue',
            ];
        }
        return $items;
    }

    /**
     * Business-continuity plans whose review date has passed (ISO 22301 8.4/9.1).
     *
     * @return array<int, array<string, mixed>>
     */
    public function bcPlanReviewOverdue(Tenant $tenant): array
    {
        $items = [];
        $today = new DateTimeImmutable('today');
        foreach ($this->bcPlanRepo->findBy(['tenant' => $tenant]) as $plan) {
            $next = $plan->getNextReviewDate();
            if ($next === null || $next >= $today) {
                continue;
            }
            $items[] = [
                'priority'    => 'medium',
                'due_date'    => $next,
                'link'        => $this->urls->generate('app_bc_plan_show', ['id' => $plan->getId()]),
                'entity_type' => 'bc_plan',
                'tone'        => 'warning',
                'title'       => (string) ($plan->getName() ?? 'BC plan'),
                'subtitle'    => 'review_overdue',
                'badge'       => 'overdue',
            ];
        }
        return $items;
    }
}
