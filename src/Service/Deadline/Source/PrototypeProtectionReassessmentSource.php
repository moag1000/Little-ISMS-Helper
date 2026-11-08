<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\PrototypeProtectionAssessment;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * TISAX Prototypenschutz: getNextAssessmentDue() of an approved (or already
 * expired) assessment — the label runs out with it (VDA ISA 8, TISAX 3 years).
 */
final class PrototypeProtectionReassessmentSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return PrototypeProtectionAssessment::class;
    }

    public function slug(): string
    {
        return 'prototype_protection_reassessment';
    }

    public function label(): string
    {
        return 'TISAX-Prototypenschutz neu bewerten';
    }

    public function requiredModule(): string
    {
        return 'tisax';
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof PrototypeProtectionAssessment);

        return $item->getNextAssessmentDue();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof PrototypeProtectionAssessment);

        return (string) $item->getTitle();
    }

    /** Drafts and reviews are still being written; rejected ones are not in force. */
    public function isCompleted(object $item): bool
    {
        assert($item instanceof PrototypeProtectionAssessment);

        return !in_array($item->getStatus(), [
            PrototypeProtectionAssessment::STATUS_APPROVED,
            PrototypeProtectionAssessment::STATUS_EXPIRED,
        ], true);
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof PrototypeProtectionAssessment);

        return Responsibility::holders($item->getAssessor(), $item->getAssessorPerson())
            ->withDeputies(...$item->getAssessorDeputyPersons()->toArray())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof PrototypeProtectionAssessment);

        return new RecordLink('app_prototype_protection_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'tisax_assessment_due', leadDays: 60, normReference: 'VDA ISA 8 (Prototypenschutz)');
    }
}
