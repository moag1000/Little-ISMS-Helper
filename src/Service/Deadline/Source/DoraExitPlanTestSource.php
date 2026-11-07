<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\DoraExitPlan;
use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Exit-Plan testen (DORA Art. 28 (8)): every plan is tested yearly; a plan
 * never tested is due three months after it was written.
 */
final class DoraExitPlanTestSource extends EntityDeadlineSource
{
    protected function entityClass(): string
    {
        return DoraExitPlan::class;
    }

    public function slug(): string
    {
        return 'dora_exit_plan_test';
    }

    public function label(): string
    {
        return 'Exit-Plan testen';
    }

    public function requiredModule(): string
    {
        return 'nis2_dora';
    }

    public function findOpen(Tenant $tenant): iterable
    {
        return $this->entityManager->getRepository(DoraExitPlan::class)->findBy(['tenant' => $tenant]);
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof DoraExitPlan);
        if ($item->getTestedAt() !== null) {
            return $item->getTestedAt()->modify('+12 months');
        }

        return ($item->getCreatedAt() ?? new DateTimeImmutable('today'))->modify('+3 months');
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof DoraExitPlan);

        return (string) ($item->getSupplier()?->getName() ?? '#' . $item->getId());
    }

    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_ISB', 'ROLE_COMPLIANCE_MANAGER');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof DoraExitPlan);

        return new RecordLink('app_dora_exit_plan_show', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'dora_exit_plan_tests_due', leadDays: 30, normReference: 'DORA Art. 28 (8)');
    }
}
