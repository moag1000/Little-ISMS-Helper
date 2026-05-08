<?php

declare(strict_types=1);

namespace App\Service\ComplianceWizard\Check\PolicyWizard;

use App\Entity\Tenant;

/**
 * Compliance-Wizard check-type contract for Policy-Wizard outputs.
 *
 * One implementation = one auditable assertion against the tenant's
 * generated policy-set (top-level present, topic-coverage, approval-chain,
 * acknowledgement-coverage, review-cadence, tailoring-fields). Each
 * implementation auto-tags via `_instanceof` in `services.yaml` and is
 * collected by the Compliance-Wizard for category aggregation.
 *
 * Why a class-per-check (not the legacy switch-case) — Compliance-Manager
 * review § "What worries me" #6: the wizard-generated policies must tick
 * the existing check-types on day one. Class-per-check keeps the unit-tests
 * surgical (one mocked dependency set per check) and lets us iterate on
 * the 24 ISO 27002 topic-checks via a single parameterised class.
 */
interface PolicyWizardCheckInterface
{
    /**
     * Stable machine key used in translations (`compliance_check.<id>.title`),
     * SoA evidence-links and regression tests. MUST start with `policy_`.
     */
    public function getCheckId(): string;

    /**
     * Standard discriminator (`iso27001`, `dora`, `bsi`, `bcm22301`, …) —
     * lets the wizard surface the check only inside the matching category.
     */
    public function getStandard(): string;

    /**
     * Run the assertion against the given tenant. A null tenant indicates
     * the global / system context; checks SHOULD return a passing (or
     * neutrally-skipped) result rather than throwing.
     */
    public function run(?Tenant $tenant): PolicyWizardCheckResult;
}
