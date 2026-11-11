<?php

declare(strict_types=1);

namespace App\Security;

use App\Security\Voter\PersonalDataRecordVoter;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * S33: lists, dashboards and search follow the detail page's read rule for records about a person
 * ({@see PersonalDataRecordVoter}): managers (DPO included) see every record, anybody else only the
 * ones they are responsible for. One place, so a list never shows what its detail page refuses.
 */
final readonly class PersonalDataVisibility
{
    public function __construct(private AuthorizationCheckerInterface $authorization)
    {
    }

    /**
     * @template T of object
     * @param iterable<T> $records
     * @return list<T>
     */
    public function readable(iterable $records): array
    {
        $records = is_array($records) ? array_values($records) : iterator_to_array($records, false);
        if ($this->authorization->isGranted('ROLE_MANAGER')) {
            return $records;
        }

        return array_values(array_filter(
            $records,
            fn (object $record): bool => $this->authorization->isGranted(PersonalDataRecordVoter::VIEW, $record),
        ));
    }

    /**
     * Rows that point at a record by id (action items, …): kept only when that record is readable.
     * `$records` is called only for a reader who is no manager.
     *
     * @template T of object
     * @param list<array<string, mixed>> $items
     * @param callable(): iterable<T>    $records
     * @param callable(T): mixed         $idOf
     * @return list<array<string, mixed>>
     */
    public function readableItems(array $items, string $idKey, callable $records, callable $idOf): array
    {
        if ($this->authorization->isGranted('ROLE_MANAGER')) {
            return $items;
        }
        $ids = array_map($idOf, $this->readable($records()));

        return array_values(array_filter($items, static fn (array $item): bool => in_array($item[$idKey] ?? null, $ids, true)));
    }
}
