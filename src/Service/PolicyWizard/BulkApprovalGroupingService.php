<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use BadMethodCallException;

/**
 * Groups policy documents pending top-management sign-off into bulk-
 * approval batches honoring the four hardcoded audit-defangs from
 * `docs/plans/policy-wizard/05-architecture.md` §9.2.1.
 *
 * Defangs (cannot be relaxed via TenantApprovalConfig):
 *   1. Top-level Information Security Policy (ISO 27001 Cl. 5.2) is
 *      hard-EXCLUDED from any bulk grouping — ceremonial individual
 *      sign-off only.
 *   2. DPO Charter is hard-EXCLUDED from any bulk grouping.
 *   3. Tenants whose `regulated_scope` includes any of {DORA, NIS2,
 *      KRITIS, BaFin-supervised} get `bulkApprovalDualSignoff=true`
 *      enforced; ISO-only tenants default to false. SUPER_ADMIN +
 *      audit-log entry required to override.
 *   4. Maximum batch size of 10 documents per top-mgmt session — bigger
 *      wizard runs split into multiple batches.
 *   5. Mandatory rationale ≥200 characters on submit.
 *
 * IMPLEMENTATION NOTE: this class is a stub for sprint W1. The actual
 * grouping logic ships in W2/W3 once Document↔PolicyTemplate linking
 * exists. The stub throws `BadMethodCallException` so the W1-D
 * `BulkApprovalDefangTest` fixture can `markTestSkipped()` cleanly
 * until the implementation lands.
 *
 * @phpstan-type BatchEntry array{
 *   document_id: int|null,
 *   policy_template: PolicyTemplate,
 *   excluded_from_bulk: bool,
 *   exclusion_reason: string|null,
 * }
 *
 * @phpstan-type BulkInbox array{
 *   excluded_singletons: list<BatchEntry>,
 *   bulk_batches: list<list<BatchEntry>>,
 *   dual_signoff_required: bool,
 *   max_batch_size: int,
 *   min_rationale_chars: int,
 * }
 */
class BulkApprovalGroupingService
{
    /**
     * Hardcoded audit-defang #4: ISO Cl. 5.2 ceremonial sign-off batch
     * size cap. Override forbidden.
     */
    public const MAX_BULK_BATCH_SIZE = 10;

    /**
     * Hardcoded audit-defang #5: minimum rationale length.
     */
    public const MIN_BULK_RATIONALE_CHARS = 200;

    /**
     * Policy-template `key_name` of the top-level Information Security
     * Policy (ISO 27001 Cl. 5.2). Hard-excluded from bulk batches.
     */
    public const TOP_LEVEL_POLICY_KEY = 'iso27001.information_security_policy';

    /**
     * Policy-template `key_name` of the DPO Charter (ISO 27701 / GDPR
     * Art. 38). Hard-excluded from bulk batches.
     */
    public const DPO_CHARTER_KEY = 'iso27701.dpo_charter';

    /**
     * Regulated-scope markers that trigger default `bulkApprovalDualSignoff=true`.
     */
    public const DUAL_SIGNOFF_REGULATED_SCOPES = ['dora', 'nis2', 'kritis', 'bafin'];

    /**
     * Compute a bulk-approval inbox grouping for the given pending
     * top-management sign-off entries.
     *
     * @param list<BatchEntry> $pendingEntries Documents currently in `top_mgmt_signoff` step.
     * @return BulkInbox
     */
    public function buildInbox(Tenant $tenant, array $pendingEntries): array
    {
        throw new BadMethodCallException(
            'BulkApprovalGroupingService::buildInbox not yet implemented — W2 ticket. '
            . 'See docs/plans/policy-wizard/05-architecture.md §9.2.1.',
        );
    }

    /**
     * Returns true when the policy-template must be excluded from any
     * bulk batch and approved individually. Implements defangs #1 and
     * #2 from §9.2.1.
     */
    public function isExcludedFromBulk(PolicyTemplate $template): bool
    {
        throw new BadMethodCallException(
            'BulkApprovalGroupingService::isExcludedFromBulk not yet implemented — W2 ticket.',
        );
    }

    /**
     * Returns the resolved `bulkApprovalDualSignoff` value for the
     * tenant. Honors defang #3: regulated scope cannot turn it off
     * without SUPER_ADMIN + audit-log entry.
     */
    public function resolveDualSignoffDefault(Tenant $tenant): bool
    {
        throw new BadMethodCallException(
            'BulkApprovalGroupingService::resolveDualSignoffDefault not yet implemented — W2 ticket.',
        );
    }

    /**
     * Validate a bulk-approval rationale string against defang #5.
     *
     * @return list<string> List of i18n-key violation reasons; empty when valid.
     */
    public function validateRationale(string $rationale): array
    {
        throw new BadMethodCallException(
            'BulkApprovalGroupingService::validateRationale not yet implemented — W2 ticket.',
        );
    }
}
