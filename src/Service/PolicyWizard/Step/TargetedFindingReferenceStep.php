<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard\Step;

use App\Entity\AuditFinding;
use App\Entity\WizardRun;
use App\Repository\AuditFindingRepository;
use App\Service\PolicyWizard\WizardStepKeys;

/**
 * Targeted Re-Run Step 2 — optional audit-finding reference.
 *
 * P1 ISB: surfaces in audit log so future auditors can see "this 3-
 * policy fix was triggered by Finding NCR-2026-04".
 *
 * Form-Audit (May 2026): the field now offers a TomSelect picker over
 * the tenant's open AuditFinding entities. Users may also type a free-
 * text reference (external system / paper finding) — the picker has
 * `create: true`. Picked finding IDs are persisted with an
 * `AUDIT_FINDING:<id>` prefix so {@see WizardOrchestrator} can write a
 * structured audit-log link to the finding entity. Free text is stored
 * verbatim (max 100 chars) for backward-compat with legacy NCR-XXXX
 * style references.
 */
final class TargetedFindingReferenceStep extends AbstractStep
{
    /**
     * Prefix used when the user picked an existing AuditFinding via the
     * TomSelect picker. Stored in `WizardRun.findingReference` so the
     * orchestrator can lift the entity link without an extra column.
     */
    public const AUDIT_FINDING_PREFIX = 'AUDIT_FINDING:';

    /**
     * The repository is optional so unit tests that instantiate the
     * step directly (without a kernel) keep working.
     */
    public function __construct(
        private readonly ?AuditFindingRepository $auditFindingRepository = null,
    ) {
    }

    public function key(): string
    {
        return WizardStepKeys::STEP_TARGETED_FINDING;
    }

    public function isApplicable(WizardRun $run): bool
    {
        return $run->getMode() === WizardStepKeys::MODE_TARGETED;
    }

    public function validate(WizardRun $run, array $input): array
    {
        $errors = [];

        $finding = $input['finding_reference'] ?? null;
        $findingId = null;
        if ($finding !== null) {
            if (!is_string($finding)) {
                $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_invalid';
                $finding = null;
            } else {
                $finding = trim($finding);
                if ($finding === '') {
                    $finding = null;
                } elseif (str_starts_with($finding, self::AUDIT_FINDING_PREFIX)) {
                    // Picked existing AuditFinding — verify it belongs to
                    // the tenant before persisting. Reject foreign / vanished
                    // finding IDs so the audit log stays clean.
                    $idPart = substr($finding, strlen(self::AUDIT_FINDING_PREFIX));
                    if (!ctype_digit($idPart)) {
                        $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_invalid';
                        $finding = null;
                    } else {
                        $candidateId = (int) $idPart;
                        $tenant = $run->getTenant();
                        $entity = ($this->auditFindingRepository !== null && $tenant !== null)
                            ? $this->auditFindingRepository->find($candidateId)
                            : null;
                        if (!$entity instanceof AuditFinding
                            || $entity->getTenant()?->getId() !== $tenant?->getId()
                        ) {
                            $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_unknown';
                            $finding = null;
                        } else {
                            $findingId = $candidateId;
                            // Re-canonicalise the stored string so it always
                            // matches the prefix contract.
                            $finding = self::AUDIT_FINDING_PREFIX . $candidateId;
                            if (strlen($finding) > 100) {
                                $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_too_long';
                                $finding = null;
                                $findingId = null;
                            }
                        }
                    }
                } elseif (strlen($finding) > 100) {
                    $errors['finding_reference'][] = 'policy_wizard.error.finding_reference_too_long';
                    $finding = substr($finding, 0, 100);
                }
            }
        }

        $normalised = [
            'finding_reference' => $finding,
            'finding_audit_finding_id' => $findingId,
        ];
        return [
            'errors' => $errors,
            'normalised_input' => $normalised,
        ];
    }

    public function persist(WizardRun $run, array $input): void
    {
        parent::persist($run, $input);
        if (array_key_exists('finding_reference', $input)) {
            $ref = $input['finding_reference'];
            $run->setFindingReference(is_string($ref) && $ref !== '' ? $ref : null);
        }
    }

    /**
     * Pull pre-fills + the open-finding picker bag.
     *
     * Returned shape:
     *   {
     *       finding_reference: string|null  (current persisted value),
     *       audit_findings:    list<array{
     *           id: int, finding_number: string, title: string,
     *           severity: string, due_date: ?string,
     *       }>
     *   }
     *
     * @return array<string, mixed>
     */
    public function defaults(WizardRun $run): array
    {
        $base = parent::defaults($run);
        $base['finding_reference'] = $base['finding_reference']
            ?? $run->getFindingReference();
        $base['audit_findings'] = $this->openFindingsForTenant($run);
        return $base;
    }

    /**
     * @return list<array{
     *     id: int, finding_number: string, title: string,
     *     severity: string, due_date: ?string,
     * }>
     */
    private function openFindingsForTenant(WizardRun $run): array
    {
        $tenant = $run->getTenant();
        if ($tenant === null || $this->auditFindingRepository === null) {
            return [];
        }

        $rows = [];
        foreach ($this->auditFindingRepository->findOpenByTenant($tenant) as $finding) {
            $id = $finding->getId();
            if ($id === null) {
                continue;
            }
            $rows[] = [
                'id' => $id,
                'finding_number' => (string) ($finding->getFindingNumber() ?? ''),
                'title' => (string) ($finding->getTitle() ?? ''),
                'severity' => $finding->getSeverity(),
                'due_date' => $finding->getDueDate()?->format('Y-m-d'),
            ];
        }
        return $rows;
    }
}
