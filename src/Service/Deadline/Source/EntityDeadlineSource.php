<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\HorizonFilteredSource;
use App\Service\Deadline\HorizonValue;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;

/**
 * Base for deadline sources that read one date field of one tenant-owned
 * entity. A subclass names the entity, the date, when the duty is done, who
 * is responsible and its profile — nothing else.
 */
abstract class EntityDeadlineSource implements DeadlineSource, HorizonFilteredSource
{
    public function __construct(
        protected readonly EntityManagerInterface $entityManager,
    ) {
    }

    /** @return class-string */
    abstract protected function entityClass(): string;

    public function requiredModule(): ?string
    {
        return null;
    }

    /**
     * Records of the tenant that carry the duty: by default those with a
     * deadline set (a record without a date is not an open duty here).
     *
     * @return iterable<object>
     */
    public function findOpen(Tenant $tenant): iterable
    {
        foreach ($this->entityManager->getRepository($this->entityClass())->findBy(['tenant' => $tenant]) as $item) {
            if ($this->dueDateOf($item) !== null && !$this->isCompleted($item)) {
                yield $item;
            }
        }
    }

    /**
     * The mapped date column behind {@see dueDateOf()}, or null when the date is
     * computed. With a column the inbox loads only records due within the lead
     * time (S31); without one it falls back to {@see findOpen()}.
     */
    protected function dueField(): ?string
    {
        return null;
    }

    /** Further conditions / fetch joins for {@see findOpenDueBy()} (alias `e`). */
    protected function narrow(QueryBuilder $queryBuilder): void
    {
    }

    /**
     * @return iterable<object>
     */
    public function findOpenDueBy(Tenant $tenant, DateTimeImmutable $horizon): iterable
    {
        $field = $this->dueField();
        if ($field === null) {
            yield from $this->findOpen($tenant);

            return;
        }
        $metadata = $this->entityManager->getClassMetadata($this->entityClass());
        $queryBuilder = $this->entityManager->createQueryBuilder()
            ->select('e')
            ->from($this->entityClass(), 'e')
            ->andWhere('e.tenant = :tenant')
            ->andWhere(sprintf('e.%s IS NOT NULL', $field))
            ->andWhere(sprintf('e.%s <= :horizon', $field))
            ->setParameter('tenant', $tenant)
            ->setParameter('horizon', HorizonValue::forColumn($horizon, $metadata->getTypeOfField($field)), $metadata->getTypeOfField($field));
        $this->narrow($queryBuilder);
        foreach ($queryBuilder->getQuery()->getResult() as $item) {
            if ($this->supports($item) && !$this->isCompleted($item)) {
                yield $item;
            }
        }
    }

    public function supports(object $item): bool
    {
        return is_a($item, $this->entityClass());
    }

    public function refId(object $item): int
    {
        $ids = $this->entityManager->getClassMetadata($item::class)->getIdentifierValues($item);

        return (int) (reset($ids) ?: 0);
    }
}
