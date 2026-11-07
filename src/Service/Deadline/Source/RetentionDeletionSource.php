<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\RetentionEnforcementService;
use DateTimeImmutable;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vorwarnung zur Aufbewahrung (DSGVO Art. 5 (1) e): records the weekly
 * retention sweep will delete within the next two weeks, one item per record
 * type the organisation opted into auto-deletion for. Due is the sweep that
 * deletes the first of them; whoever wants to keep one changes the policy or
 * the record before that.
 */
final class RetentionDeletionSource implements DeadlineSource
{
    private const int LEAD_DAYS = 14;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function slug(): string
    {
        return 'retention_deletion';
    }

    public function label(): string
    {
        return 'Löschung nach Aufbewahrungsfrist';
    }

    public function requiredModule(): ?string
    {
        return null;
    }

    /** @return list<UpcomingDeletion> */
    public function findOpen(Tenant $tenant): iterable
    {
        $now = new DateTimeImmutable();
        $items = [];
        foreach ($tenant->getDataRetentionPolicies() ?? [] as $type => $policy) {
            $days = (int) $policy['retention_days'];
            if (empty($policy['auto_delete']) || $days <= 0 || !isset(RetentionEnforcementService::REGISTRY[$type])) {
                continue;
            }
            [$class, $field] = RetentionEnforcementService::REGISTRY[$type];
            $row = $this->entityManager->createQueryBuilder()
                ->select(sprintf('COUNT(e.id) AS n, MIN(e.%s) AS oldest', $field))
                ->from($class, 'e')
                ->where('e.tenant = :tenant')
                ->andWhere(sprintf('e.%s < :cutoff', $field))
                ->setParameter('tenant', $tenant)
                ->setParameter('cutoff', $now->modify(sprintf('+%d days', self::LEAD_DAYS))->modify(sprintf('-%d days', $days)))
                ->getQuery()
                ->getSingleResult();
            if ((int) $row['n'] === 0 || $row['oldest'] === null) {
                continue;
            }
            $eligible = (new DateTimeImmutable((string) $row['oldest']))->modify(sprintf('+%d days', $days));
            $items[] = new UpcomingDeletion(
                (string) $type,
                (int) array_search($type, array_keys(RetentionEnforcementService::REGISTRY), true) + 1,
                (int) $row['n'],
                self::nextSweep(max($eligible, $now)),
            );
        }

        return $items;
    }

    /** The first scheduled sweep at or after the moment. */
    public static function nextSweep(DateTimeImmutable $from): DateTimeImmutable
    {
        $sweep = $from->setTime(RetentionEnforcementService::SWEEP_HOUR, 0);
        while ((int) $sweep->format('w') !== RetentionEnforcementService::SWEEP_WEEKDAY || $sweep < $from) {
            $sweep = $sweep->modify('+1 day');
        }

        return $sweep;
    }

    public function dueDateOf(object $item): DateTimeInterface
    {
        assert($item instanceof UpcomingDeletion);

        return $item->deletesAt;
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof UpcomingDeletion);

        return $this->translator->trans('my_day.retention_title', [
            '%count%' => $item->count,
            '%type%' => $this->translator->trans('admin.data_retention.entity.' . $item->type, [], 'admin'),
        ], 'my_day');
    }

    public function isCompleted(object $item): bool
    {
        return false;
    }

    public function refId(object $item): int
    {
        assert($item instanceof UpcomingDeletion);

        return $item->id;
    }

    public function supports(object $item): bool
    {
        return $item instanceof UpcomingDeletion;
    }

    public function responsibleOf(object $item): Responsibility
    {
        return Responsibility::roles('ROLE_DPO', 'ROLE_ADMIN');
    }

    public function linkOf(object $item): RecordLink
    {
        return new RecordLink('admin_settings_data_retention', []);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'retention_deletion_upcoming', leadDays: self::LEAD_DAYS, normReference: 'DSGVO Art. 5 Abs. 1 e');
    }
}
