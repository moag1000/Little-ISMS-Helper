<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineSource;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Base for deadline sources that read one date field of one tenant-owned
 * entity. A subclass names the entity, the date, when the duty is done, who
 * is responsible and its profile — nothing else.
 */
abstract class EntityDeadlineSource implements DeadlineSource
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
