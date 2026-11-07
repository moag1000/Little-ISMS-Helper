<?php

declare(strict_types=1);

namespace App\Service\Planning\Source\Adapter;

use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Repository\ComplianceRequirementFulfillmentRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Planning\Source\SourceAdapter;
use App\Service\Responsibility\Responsibility;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * SourceAdapter for hand overrides of a fulfilment rule (Paket 3): a value a
 * person set next to the rule carries a review date; when it comes, the
 * override is due for confirmation (keep with a new reason, or hand back to
 * the rule). Legacy values from before the rule ("Altbestand") get a review
 * date 90 days after their first evaluation.
 *
 * Completed when the override is gone or its review date was moved past today.
 */
final class FulfillmentOverrideReviewAdapter implements SourceAdapter
{
    /** Lead time: an override shows up in the planner two weeks before its review date. */
    private const LEAD_DAYS = 14;

    public function __construct(
        private readonly ComplianceRequirementFulfillmentRepository $repository,
    ) {}

    public function slug(): string
    {
        return 'fulfillment_override_review';
    }

    public function label(): string
    {
        return 'Übersteuerung prüfen';
    }

    public function requiredModule(): string
    {
        return 'compliance';
    }

    /** @return iterable<ComplianceRequirementFulfillment> */
    public function findConvertible(Tenant $tenant): iterable
    {
        return $this->repository->findOverridesDueForReview(
            $tenant,
            new DateTimeImmutable('+' . self::LEAD_DAYS . ' days'),
        );
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ComplianceRequirementFulfillment);
        return $item->getOverrideReviewDue();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ComplianceRequirementFulfillment);
        $requirement = $item->getRequirement();

        return sprintf(
            'Übersteuerung prüfen: %s %s',
            (string) $requirement?->getFramework()?->getCode(),
            (string) $requirement?->getRequirementId(),
        );
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ComplianceRequirementFulfillment);
        $due = $item->getOverrideReviewDue();

        return !$item->isOverridden()
            || ($due instanceof DateTimeImmutable && $due > new DateTimeImmutable('+' . self::LEAD_DAYS . ' days'));
    }

    public function ownsRecurrence(): bool
    {
        return true; // a confirmed override carries its own next review date
    }

    public function refId(object $item): int
    {
        assert($item instanceof ComplianceRequirementFulfillment);
        return (int) $item->getId();
    }

    /** @return iterable<ComplianceRequirementFulfillment> */
    public function findOpen(Tenant $tenant): iterable
    {
        return $this->findConvertible($tenant);
    }

    public function supports(object $item): bool
    {
        return $item instanceof ComplianceRequirementFulfillment && $item->isOverridden();
    }

    /** The requirement's responsible person, else whoever set the override, else the compliance roles. */
    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ComplianceRequirementFulfillment);

        return Responsibility::holders($item->getResponsiblePersonUser(), $item->getResponsiblePerson(), $item->getOverriddenBy())
            ->withDeputies(...$item->getResponsibleDeputyPersons()->toArray())
            ->orRoles('ROLE_COMPLIANCE_MANAGER', 'ROLE_ISB');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ComplianceRequirementFulfillment);

        return new RecordLink('app_compliance_requirement_show', ['id' => (int) $item->getRequirement()?->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: 'fulfillment_override_review',
            leadDays: self::LEAD_DAYS,
            normReference: 'ISO 27001 9.1 / 10.2',
            decision: true,
        );
    }
}
