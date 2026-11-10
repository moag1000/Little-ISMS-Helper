<?php

declare(strict_types=1);

namespace App\Service\Deadline\Source;

use App\Entity\ActionItem;
use App\Entity\Tenant;
use App\Repository\ActionItemRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\RecordLink;
use App\Service\Planning\Source\SourceAdapter;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Maßnahmen with a due date (ActionItem.dueDate). Tasks from a management
 * review have their own source; Maßnahmen converted from another deadline
 * source (risk review, finding …) are left out, because that source already
 * reminds of the same duty.
 */
final class ActionItemDueSource extends EntityDeadlineSource
{
    /** @var array<string, true>|null slugs of the planner sources, read on first use */
    private ?array $adapterSlugs = null;

    /**
     * @param iterable<SourceAdapter> $adapters
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        #[AutowireIterator('app.source_adapter')]
        private readonly iterable $adapters = [],
    ) {
        parent::__construct($entityManager);
    }

    protected function entityClass(): string
    {
        return ActionItem::class;
    }

    public function slug(): string
    {
        return 'action_item_due';
    }

    public function label(): string
    {
        return 'Maßnahme fällig';
    }

    public function requiredModule(): string
    {
        return 'resource_planning';
    }

    public function findOpen(Tenant $tenant): iterable
    {
        foreach (parent::findOpen($tenant) as $item) {
            if ($this->supports($item)) {
                yield $item;
            }
        }
    }

    protected function dueField(): string
    {
        return 'dueDate';
    }

    /** Open items only, references fetched with them (supports() reads them per item). */
    protected function narrow(QueryBuilder $queryBuilder): void
    {
        $queryBuilder
            ->andWhere('e.status NOT IN (:closed)')
            ->setParameter('closed', [ActionItem::STATUS_DONE, ActionItem::STATUS_DISMISSED])
            ->leftJoin('e.references', 'ref')
            ->addSelect('ref');
    }

    public function supports(object $item): bool
    {
        if (!$item instanceof ActionItem) {
            return false;
        }
        foreach ($item->getReferences() as $reference) {
            $type = (string) $reference->getRefType();
            if ($type === ActionItemRepository::MANAGEMENT_REVIEW_REF || isset($this->adapterSlugs()[$type])) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, true> */
    private function adapterSlugs(): array
    {
        if ($this->adapterSlugs === null) {
            $this->adapterSlugs = [];
            foreach ($this->adapters as $adapter) {
                $this->adapterSlugs[$adapter->slug()] = true;
            }
        }

        return $this->adapterSlugs;
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        assert($item instanceof ActionItem);

        return $item->getDueDate();
    }

    public function titleOf(object $item): string
    {
        assert($item instanceof ActionItem);

        return (string) $item->getTitle();
    }

    public function isCompleted(object $item): bool
    {
        assert($item instanceof ActionItem);

        return $item->isClosed();
    }

    public function responsibleOf(object $item): Responsibility
    {
        assert($item instanceof ActionItem);

        return Responsibility::holders($item->getResponsibleUser(), $item->getResponsiblePerson())
            ->orRoles('ROLE_ISB', 'ROLE_CISO');
    }

    public function linkOf(object $item): RecordLink
    {
        assert($item instanceof ActionItem);

        return new RecordLink('app_planning_action_item_edit', ['id' => (int) $item->getId()]);
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(bucket: 'action_items_due', leadDays: 14, normReference: 'ISO 27001 6.1.3, 8.1');
    }
}
