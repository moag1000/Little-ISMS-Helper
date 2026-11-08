<?php

declare(strict_types=1);

namespace App\Service\ManagementDecision;

use App\Entity\ManagementDecision;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ManagementDecisionStatus;
use App\Enum\ManagementDecisionType;
use App\Lifecycle\LifecycleTransitionInterface;
use App\Repository\ManagementDecisionRepository;
use App\Service\AuditLogger;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The one place that writes top-management decisions.
 *
 * - propose(): someone prepares a decision, prefilled from the records;
 *   top management approves or rejects it via management_decision_lifecycle.
 * - record(): a decision top management took elsewhere (risk acceptance at
 *   board level, completed management review, automatic acceptance under
 *   approved criteria) is written as already approved, with its evidence.
 * - criteriaApproval(): whether the current risk acceptance thresholds are
 *   covered by an approval in force (ISO 27001 6.1.2 a) — the precondition
 *   for accepting low risks automatically.
 */
class ManagementDecisionService
{
    private const string WORKFLOW = 'management_decision_lifecycle';

    /** Default time top management has to decide a proposal. */
    private const int DEFAULT_DUE_DAYS = 14;

    /** Types of which only one approval is in force at a time (the newest). */
    private const array SINGLE_CURRENT = [
        ManagementDecisionType::Policy,
        ManagementDecisionType::RiskCriteria,
        ManagementDecisionType::Soa,
        ManagementDecisionType::Nis2Measures,
        ManagementDecisionType::Objectives,
        ManagementDecisionType::DoraIctFramework,
        ManagementDecisionType::DoraStrategy,
        ManagementDecisionType::DoraThirdParty,
    ];

    public function __construct(
        private readonly ManagementDecisionRepository $repository,
        private readonly DecisionProposalBuilder $proposalBuilder,
        private readonly EntityManagerInterface $entityManager,
        private readonly LifecycleTransitionInterface $lifecycle,
        private readonly AuditLogger $auditLogger,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** A new, prefilled proposal (not persisted — the form shows it first). */
    public function prepare(Tenant $tenant, ManagementDecisionType $type, ?User $requester, ?string $locale = null): ManagementDecision
    {
        $proposal = $this->proposalBuilder->build($type, $tenant, $locale);

        $decision = new ManagementDecision();
        $decision->setTenant($tenant)
            ->setType($type)
            ->setTitle($proposal->title)
            ->setProposal($proposal->text !== '' ? $proposal->text : null)
            ->setSubject($proposal->subjectType, $proposal->subjectId)
            ->setSubjectSnapshot($proposal->snapshot)
            ->setRequestedBy($requester)
            ->setDueDate(new DateTimeImmutable(sprintf('+%d days', self::DEFAULT_DUE_DAYS)));

        return $decision;
    }

    /** Puts a prepared proposal before top management. */
    public function submit(ManagementDecision $decision): void
    {
        // Thresholds may have changed while the form was open: the approval
        // covers what is configured when it is put forward.
        if ($decision->getType() === ManagementDecisionType::RiskCriteria && $decision->getTenant() instanceof Tenant) {
            $decision->setSubjectSnapshot($this->proposalBuilder->criteriaSnapshot($decision->getTenant()));
        }
        $decision->setRequestedAt(new DateTimeImmutable());
        $this->entityManager->persist($decision);
        $this->entityManager->flush();

        $this->auditLogger->logCreate('ManagementDecision', $decision->getId(), [
            'type' => $decision->getType()->value,
            'title' => $decision->getTitle(),
            'dueDate' => $decision->getDueDate()?->format('Y-m-d'),
        ], 'Proposal put before top management');
    }

    /**
     * Writes a decision taken elsewhere as approved and supersedes the older
     * approval it replaces.
     *
     * @param array<string, mixed>|null $snapshot
     */
    public function record(
        Tenant $tenant,
        ManagementDecisionType $type,
        string $title,
        ?string $text,
        ?User $decidedBy,
        ?string $comment = null,
        ?string $subjectType = null,
        ?int $subjectId = null,
        ?array $snapshot = null,
        ?ManagementReview $review = null,
        ?ManagementDecision $basis = null,
    ): ManagementDecision {
        $now = new DateTimeImmutable();
        $decision = new ManagementDecision();
        $decision->setTenant($tenant)
            ->setType($type)
            ->setTitle(mb_substr($title, 0, 255))
            ->setProposal($text)
            ->setSubject($subjectType, $subjectId)
            ->setSubjectSnapshot($snapshot)
            ->setManagementReview($review)
            ->setBasis($basis)
            ->setRequestedBy($decidedBy)
            ->setRequestedAt($now)
            ->setDecidedBy($decidedBy)
            ->setDecidedAt($now)
            ->setDecisionComment($comment)
            ->setValidUntil($this->defaultValidUntil($type, $now));
        // Recorded decisions were taken outside this lifecycle; they start approved.
        $decision->setStatus(ManagementDecisionStatus::Approved->value); // @phpstan-ignore lifecycle.directSetStatus (initial state on a pre-persist entity: the decision was taken in the risk workflow / review completion)

        $this->entityManager->persist($decision);
        $this->entityManager->flush();
        $this->supersedeOlder($decision, $decidedBy);

        $this->auditLogger->logCreate('ManagementDecision', $decision->getId(), [
            'type' => $type->value,
            'title' => $decision->getTitle(),
            'subject' => $subjectType !== null ? $subjectType . '#' . $subjectId : null,
            'basis' => $basis?->getId(),
        ], 'Top-management decision recorded');

        return $decision;
    }

    /**
     * Called when top management approved a proposal (lifecycle listener):
     * sets the default validity and supersedes the older approval.
     */
    public function afterApproval(ManagementDecision $decision, ?User $user): void
    {
        if ($decision->getValidUntil() === null) {
            $decision->setValidUntil($this->defaultValidUntil(
                $decision->getType(),
                $decision->getDecidedAt() ?? new DateTimeImmutable(),
            ));
        }
        $this->supersedeOlder($decision, $user);
    }

    /**
     * The approval in force that covers the current acceptance thresholds, or
     * null. Changing a threshold after the approval ends the coverage.
     */
    public function criteriaApproval(Tenant $tenant): ?ManagementDecision
    {
        $current = $this->proposalBuilder->criteriaSnapshot($tenant);
        foreach ($this->repository->findApproved($tenant, ManagementDecisionType::RiskCriteria) as $decision) {
            if (!$decision->isEffective()) {
                continue;
            }
            $snapshot = $decision->getSubjectSnapshot() ?? [];

            return ((int) ($snapshot['automatic'] ?? -1) === $current['automatic']
                && (int) ($snapshot['manager'] ?? -1) === $current['manager']
                && (int) ($snapshot['executive'] ?? -1) === $current['executive'])
                ? $decision
                : null;
        }

        return null;
    }

    /** The approval in force of a type (newest), or null. */
    public function currentApproval(Tenant $tenant, ManagementDecisionType $type): ?ManagementDecision
    {
        foreach ($this->repository->findApproved($tenant, $type) as $decision) {
            if ($decision->isEffective()) {
                return $decision;
            }
        }

        return null;
    }

    private function defaultValidUntil(ManagementDecisionType $type, DateTimeImmutable $from): ?DateTimeImmutable
    {
        $months = $type->validityMonths();

        return $months === null ? null : $from->setTime(0, 0)->modify(sprintf('+%d months', $months));
    }

    private function supersedeOlder(ManagementDecision $decision, ?User $user): void
    {
        $tenant = $decision->getTenant();
        if (!$tenant instanceof Tenant) {
            return;
        }
        $type = $decision->getType();
        if (in_array($type, self::SINGLE_CURRENT, true)) {
            $older = $this->repository->findApproved($tenant, $type);
        } elseif ($type === ManagementDecisionType::RiskAcceptance && $decision->getSubjectId() !== null) {
            $older = $this->repository->findApproved($tenant, $type, $decision->getSubjectType(), $decision->getSubjectId());
        } else {
            return;
        }

        foreach ($older as $old) {
            if ($old === $decision || $old->getId() === $decision->getId()) {
                continue;
            }
            try {
                $this->lifecycle->transition($old, self::WORKFLOW, 'supersede', $user, null, automatic: true);
            } catch (\Throwable $e) {
                // The newer approval stands either way; the older one stays
                // listed as approved until the next approval replaces it.
                $this->logger->warning('Superseding an older management decision failed', [
                    'decision_id' => $old->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}
