<?php

declare(strict_types=1);

namespace App\Risk;

use App\Entity\Tenant;
use App\Service\RiskApprovalConfigResolver;
use App\Service\TenantContext;
use Symfony\Component\DependencyInjection\Attribute\Autoconfigure;

/**
 * The risk scale of the current organisation (ISO 27001 6.1.2 a/b): band
 * edges and what each probability and impact level means.
 *
 * Registered with {@see RiskMatrixThresholds} at kernel boot, so every
 * classify() — in requests, async jobs, workers and commands that set the
 * tenant context — uses the scale of the organisation in scope. Values come
 * from Risikokriterien ({@see \App\Entity\RiskApprovalConfig}) through
 * {@see RiskApprovalConfigResolver} (holding ceiling: a subsidiary is never
 * laxer than its holding).
 */
#[Autoconfigure(public: true)]
class RiskScaleProvider
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly RiskApprovalConfigResolver $resolver,
    ) {
    }

    /** @return array{critical: int, high: int, medium: int}|null null = no organisation in scope */
    public function currentEdges(): ?array
    {
        $tenant = $this->tenantContext->getCurrentTenant();

        return $tenant instanceof Tenant ? $this->edgesFor($tenant) : null;
    }

    /** @return array{critical: int, high: int, medium: int} */
    public function edgesFor(Tenant $tenant): array
    {
        $view = $this->resolver->resolveFor($tenant);

        return ['critical' => $view->bandCriticalMin, 'high' => $view->bandHighMin, 'medium' => $view->bandMediumMin];
    }

    /**
     * What the levels 1–5 mean in this organisation, per axis. Empty strings
     * for levels without an own description (the generic label applies).
     *
     * @return array{probability: array<int, string>, impact: array<int, string>}
     */
    public function criteria(?Tenant $tenant = null): array
    {
        $tenant ??= $this->tenantContext->getCurrentTenant();
        $empty = array_fill(1, RiskMatrixThresholds::LEVELS, '');
        if (!$tenant instanceof Tenant) {
            return ['probability' => $empty, 'impact' => $empty];
        }
        $view = $this->resolver->resolveFor($tenant);

        return [
            'probability' => array_replace($empty, array_intersect_key($view->probabilityCriteria, $empty)),
            'impact' => array_replace($empty, array_intersect_key($view->impactCriteria, $empty)),
        ];
    }
}
