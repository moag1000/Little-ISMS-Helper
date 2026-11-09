<?php

declare(strict_types=1);

namespace App\Repository\GateFixture;

use Doctrine\ORM\QueryBuilder;

/** Gate 72 fixture (shared-query): one class per spelling, the entity is unknown → every spelling is reported. */
final class InWithParameterListRepository
{
    public function q(QueryBuilder $qb): QueryBuilder
    {
        return $qb->where('a.tenant IN (:t) OR a.tenant IS NULL'); // expect: gate72.sharedQuery
    }
}

final class PositionalParameterRepository
{
    public function q(QueryBuilder $qb): QueryBuilder
    {
        return $qb->where('a.tenant = ?1 OR a.tenant IS NULL'); // expect: gate72.sharedQuery
    }
}

final class OrWhereIsNullRepository
{
    public function q(QueryBuilder $qb): QueryBuilder
    {
        return $qb->andWhere('a.tenant = :t')->orWhere($qb->expr()->isNull('a.tenant')); // expect: gate72.sharedQuery
    }
}

final class SprintfAliasRepository
{
    public function q(QueryBuilder $qb, string $al): QueryBuilder
    {
        return $qb->andWhere('x.tenant = :t')->orWhere(sprintf('%s.tenant IS NULL', $al)); // expect: gate72.sharedQuery
    }
}

final class CoalesceRepository
{
    public function q(QueryBuilder $qb): QueryBuilder
    {
        return $qb->where('COALESCE(IDENTITY(a.tenant), :t) = :t'); // expect: gate72.sharedQuery
    }
}

final class ConcatenatedRepository
{
    public function q(QueryBuilder $qb, string $a): QueryBuilder
    {
        return $qb->where($a . '.tenant = :t OR ' // expect: gate72.sharedQuery
            . $a . '.tenant IS NULL');
    }
}

final class OrXRepository
{
    public function q(QueryBuilder $qb): QueryBuilder
    {
        return $qb->where($qb->expr()->orX('a.tenant = :t', $qb->expr()->isNull('a.tenant'))); // expect: gate72.sharedQuery
    }
}

final class OrphanFinderRepository
{
    /** Looking for rows without tenant on purpose (data repair) is no "own OR global" query. */
    public function q(QueryBuilder $qb): QueryBuilder
    {
        return $qb->where('a.tenant IS NULL');
    }
}
