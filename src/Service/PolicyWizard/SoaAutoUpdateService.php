<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use App\Entity\Control;
use App\Entity\Document;
use App\Entity\Tenant;
use App\Entity\WizardRun;
use App\Repository\ControlRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Policy-Wizard — SoA-Auto-Update Service (User-Mandate 2026-05-08).
 *
 * Closes the user-reported gap: "wenn nur ein Nutzer dann auch die
 * Control-Umsetzungsbewertung ändern jeweils". When a Document is
 * generated, the SoA rows for the linked Annex-A controls (plus any
 * BSI Bausteine / DORA Articles surfaced via the same template) are
 * bumped from `not_implemented`/`null` → `in_progress` so the auditor
 * sees the policy roll-out reflected in the Statement of Applicability
 * the moment the wizard emits the artefact.
 *
 * Status-transition matrix (NEVER downgrades):
 *   not_implemented / not_started / null  →  in_progress
 *   planned                               →  in_progress
 *   partial_documented                    →  in_progress
 *   in_progress                           →  unchanged (manual progress preserved)
 *   implemented / fully_implemented       →  unchanged
 *   verified                              →  unchanged
 *
 * Audit-trail:
 *   - per control bump: action `policy_wizard.soa_auto_updated`
 *   - additionally on single-active-user tenants: action
 *     `policy_wizard.soa_self_assessment` so the trail shows that the
 *     single user effectively self-assessed the implementation status
 *     (no separation of duties possible). Multi-user tenants get only
 *     the standard event.
 *
 * Sandbox runs are skipped by the caller (DocumentGenerator) — this
 * service is invoked only inside the persistent-transaction path.
 */
final class SoaAutoUpdateService
{
    /**
     * Status-rank mirror of {@see DocumentGenerator::STATUS_RANK}.
     * Higher = more advanced; we only ever bump UP. Kept inline so the
     * service is independently testable without coupling to the
     * generator's private constants.
     */
    private const array STATUS_RANK = [
        'not_started'        => 0,
        'not_implemented'    => 0,
        'planned'            => 1,
        'partial_documented' => 2,
        'in_progress'        => 3,
        'implemented'        => 4,
        'fully_implemented'  => 4,
        'verified'           => 5,
    ];

    /**
     * Status-label the wizard writes when bumping. Mapped to the
     * Control entity's existing enum so the column constraint holds.
     */
    private const string TARGET_STATUS = 'in_progress';

    private const string AUDIT_TAG = 'policy-wizard';

    public function __construct(
        private readonly ControlRepository $controlRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ?AuditLogger $auditLogger = null,
        private readonly ?UserRepository $userRepository = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Walk the Document's source-template control links and bump every
     * SoA row from a lower status to {@see self::TARGET_STATUS}.
     *
     * Returns a map of control_ref → new_status for every row that was
     * actually changed (already-in-progress / already-implemented rows
     * are NOT included). The map drives the caller's flash-message
     * surface ("3 SoA-Einträge angehoben"). An empty map means
     * "no changes" (template had no links, or every linked row was
     * already at or above target).
     *
     * @return array<string, string> control_ref => new_status
     */
    public function propagateForDocument(Document $document, WizardRun $run): array
    {
        $template = $document->getGeneratedFromTemplate();
        if ($template === null) {
            return [];
        }
        $tenant = $document->getTenant();
        if (!$tenant instanceof Tenant) {
            return [];
        }

        $refs = array_merge(
            $template->getLinkedAnnexAControls() ?? [],
            $template->getLinkedBausteine() ?? [],
            $template->getLinkedDoraArticles() ?? [],
        );
        $refs = array_values(array_unique(array_filter(
            $refs,
            static fn ($r): bool => is_string($r) && $r !== '',
        )));
        if ($refs === []) {
            return [];
        }

        $changes = [];
        $isSingleUserTenant = $this->isSingleActiveUserTenant($tenant);

        foreach ($refs as $ref) {
            $control = $this->resolveControl($tenant, $ref);
            if ($control === null) {
                continue;
            }
            $oldStatus = $control->getImplementationStatus() ?? 'not_started';
            if (!$this->shouldBump($oldStatus)) {
                continue;
            }

            $control->setImplementationStatus(self::TARGET_STATUS);
            $this->entityManager->persist($control);
            $changes[$ref] = self::TARGET_STATUS;

            $this->emitAuditUpdated($control, $oldStatus, self::TARGET_STATUS, $document, $run, $ref);

            if ($isSingleUserTenant) {
                $this->emitAuditSelfAssessment($control, $document, $run, $ref);
            }
        }

        return $changes;
    }

    /**
     * Return true when bumping `$current` to {@see self::TARGET_STATUS}
     * is a NET INCREASE per the status-rank matrix. Anything already
     * at or above target is left untouched (manual-progress preservation).
     */
    private function shouldBump(string $current): bool
    {
        $currentRank = self::STATUS_RANK[$current] ?? 0;
        $targetRank = self::STATUS_RANK[self::TARGET_STATUS];
        return $currentRank < $targetRank;
    }

    /**
     * Resolve a Control via its `controlId`. Strips a leading `A.` so
     * the lookup matches both ISO 27002 catalogue notation (`A.5.15`)
     * and the bare-id storage (`5.15`). DORA references (`Art. 9.4`)
     * pass through unchanged.
     */
    private function resolveControl(Tenant $tenant, string $ref): ?Control
    {
        $candidates = [$ref];
        if (str_starts_with($ref, 'A.')) {
            $candidates[] = substr($ref, 2);
        }
        foreach ($candidates as $candidate) {
            $hit = $this->controlRepository->findOneBy([
                'tenant'    => $tenant,
                'controlId' => $candidate,
            ]);
            if ($hit instanceof Control) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * Detect single-active-user tenants. The audit-trail needs to
     * differentiate between an SoA bump that was reviewed by a separate
     * approver and one that was effectively self-assessed because the
     * tenant has only one active user. Falls back to false on missing
     * UserRepository (legacy unit-test wiring) so the additional
     * self-assessment event is OPT-IN per service-construction.
     */
    private function isSingleActiveUserTenant(Tenant $tenant): bool
    {
        if ($this->userRepository === null) {
            return false;
        }
        try {
            $activeUsers = $this->userRepository->createQueryBuilder('u')
                ->select('COUNT(u.id)')
                ->andWhere('u.tenant = :tenant')
                ->andWhere('u.isActive = :active')
                ->setParameter('tenant', $tenant)
                ->setParameter('active', true)
                ->getQuery()
                ->getSingleScalarResult();
            return ((int) $activeUsers) === 1;
        } catch (Throwable $error) {
            $this->logger->warning(
                'PolicyWizard SoaAutoUpdate: single-user detection failed; defaulting to multi-user',
                [
                    'tenant_id' => $tenant->getId(),
                    'error'     => $error->getMessage(),
                ],
            );
            return false;
        }
    }

    private function emitAuditUpdated(
        Control $control,
        string $oldStatus,
        string $newStatus,
        Document $document,
        WizardRun $run,
        string $controlRef,
    ): void {
        if ($this->auditLogger === null) {
            return;
        }
        $this->auditLogger->logCustom(
            action: 'policy_wizard.soa_auto_updated',
            entityType: 'Control',
            entityId: $control->getId(),
            oldValues: ['implementation_status' => $oldStatus],
            newValues: [
                'implementation_status' => $newStatus,
                'control_ref'           => $controlRef,
                'document_id'           => $document->getId(),
                'wizard_run_id'         => $run->getId(),
                'tag'                   => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] SoA control %s bumped from "%s" to "%s" (Document #%d, WizardRun #%d)',
                self::AUDIT_TAG,
                $controlRef,
                $oldStatus,
                $newStatus,
                $document->getId() ?? 0,
                $run->getId() ?? 0,
            ),
        );
    }

    private function emitAuditSelfAssessment(
        Control $control,
        Document $document,
        WizardRun $run,
        string $controlRef,
    ): void {
        if ($this->auditLogger === null) {
            return;
        }
        $this->auditLogger->logCustom(
            action: 'policy_wizard.soa_self_assessment',
            entityType: 'Control',
            entityId: $control->getId(),
            oldValues: null,
            newValues: [
                'control_ref'   => $controlRef,
                'document_id'   => $document->getId(),
                'wizard_run_id' => $run->getId(),
                'reason'        => 'tenant.single_active_user',
                'tag'           => self::AUDIT_TAG,
            ],
            description: sprintf(
                '[%s] SoA bump for control %s recorded as SELF-ASSESSMENT (single-user tenant; no separation of duties)',
                self::AUDIT_TAG,
                $controlRef,
            ),
        );
    }
}
