<?php

declare(strict_types=1);

namespace App\Service\Deadline\Configured;

use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\ExpressionLanguage\ExpressionLanguage;
use Symfony\Component\PropertyAccess\PropertyAccessorInterface;

/**
 * The one generic deadline source: reads a date field of a tenant-owned
 * entity as described by a {@see DeadlineTypeDefinition}. A new deadline type
 * of this shape is an entry in `config/deadline_types.yaml`, not a class.
 * Duties with logic of their own (computed dates, several records, rules
 * across records) stay a class implementing {@see DeadlineSource}.
 */
#[Exclude]
final class ConfiguredDeadlineSource implements DeadlineSource
{
    public function __construct(
        public readonly DeadlineTypeDefinition $definition,
        private readonly EntityManagerInterface $entityManager,
        private readonly ExpressionLanguage $expressions,
        private readonly PropertyAccessorInterface $accessor,
    ) {
    }

    public function slug(): string
    {
        return $this->definition->slug;
    }

    public function label(): string
    {
        return $this->definition->label;
    }

    public function requiredModule(): ?string
    {
        return $this->definition->module;
    }

    /**
     * Records of the tenant that carry the duty: those with the date set, or
     * all open ones when the type has no date.
     *
     * @return iterable<object>
     */
    public function findOpen(Tenant $tenant): iterable
    {
        foreach ($this->entityManager->getRepository($this->definition->entity)->findBy(['tenant' => $tenant]) as $item) {
            if (($this->definition->due === null || $this->dueDateOf($item) !== null) && !$this->isCompleted($item)) {
                yield $item;
            }
        }
    }

    public function dueDateOf(object $item): ?DateTimeInterface
    {
        if ($this->definition->due === null) {
            return null;
        }
        $value = $this->accessor->getValue($item, $this->definition->due);

        return $value instanceof DateTimeInterface ? $value : null;
    }

    public function titleOf(object $item): string
    {
        return (string) $this->expressions->evaluate($this->definition->title, ['item' => $item]);
    }

    public function isCompleted(object $item): bool
    {
        return (bool) $this->expressions->evaluate($this->definition->done, ['item' => $item]);
    }

    public function refId(object $item): int
    {
        $ids = $this->entityManager->getClassMetadata($item::class)->getIdentifierValues($item);

        return (int) (reset($ids) ?: 0);
    }

    public function supports(object $item): bool
    {
        return is_a($item, $this->definition->entity);
    }

    public function responsibleOf(object $item): Responsibility
    {
        $responsibility = Responsibility::holders(...$this->people($item, $this->definition->holders));
        if ($this->definition->deputies !== []) {
            $responsibility = $responsibility->withDeputies(...$this->people($item, $this->definition->deputies));
        }
        if ($this->definition->roles !== []) {
            $responsibility = $responsibility->orRoles(...$this->definition->roles);
        }
        if ($this->definition->inform !== []) {
            $roles = array_values(array_filter($this->definition->inform, self::isRole(...)));
            $paths = array_values(array_filter($this->definition->inform, static fn (string $w): bool => !self::isRole($w)));
            $responsibility = $responsibility->alsoInform(...$roles, ...$this->people($item, $paths));
        }

        return $responsibility;
    }

    public function linkOf(object $item): ?RecordLink
    {
        if ($this->definition->route === null) {
            return null;
        }

        return new RecordLink(
            $this->definition->route,
            $this->definition->routeWithId ? ['id' => $this->refId($item)] : [],
        );
    }

    public function profile(): DeadlineProfile
    {
        return new DeadlineProfile(
            bucket: $this->definition->bucket,
            leadDays: $this->definition->leadDays,
            normReference: $this->definition->norm,
        );
    }

    /**
     * @param list<string> $paths
     * @return list<User|Person>
     */
    private function people(object $item, array $paths): array
    {
        $people = [];
        foreach ($paths as $path) {
            $value = $path === 'self' ? $item : $this->accessor->getValue($item, $path);
            foreach (is_iterable($value) ? $value : [$value] as $candidate) {
                if ($candidate instanceof User || $candidate instanceof Person) {
                    $people[] = $candidate;
                }
            }
        }

        return $people;
    }

    private static function isRole(string $value): bool
    {
        return str_starts_with($value, 'ROLE_');
    }
}
