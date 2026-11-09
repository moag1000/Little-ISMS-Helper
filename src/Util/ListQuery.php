<?php

declare(strict_types=1);

namespace App\Util;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Symfony\Component\HttpFoundation\Request;

/**
 * A list view filtered, counted and paged in SQL (S31). The list skeleton's {@see ListPage} used to cut a fully
 * loaded tenant set in PHP; with 20 000 assets every list call hydrated all of them. A controller builds the
 * query from the repository's base query builder, adds the request's filters and gets one page:
 *
 * ```php
 * $list = ListQuery::of($this->assetRepository->createQueryBuilder('a')->orderBy('a.name'))
 *     ->tenants($tenants)
 *     ->equals('a.assetType', $request->query->get('type'))
 *     ->search($request, ['a.name', 'a.description']);
 * $criticalCount = $list->countWhere('a.confidentialityValue >= 4');
 * $page = $list->page($request);   // one COUNT + one page of rows
 * ```
 *
 * Every filter skips an empty value; values are always bound as parameters. Field names are code, never input.
 */
final class ListQuery
{
    private int $parameter = 0;

    private function __construct(private readonly QueryBuilder $queryBuilder)
    {
    }

    public static function of(QueryBuilder $queryBuilder): self
    {
        return new self($queryBuilder);
    }

    /**
     * Organisations a list view shows: `own`, `subsidiaries` (own + the whole subtree), `orphaned` / `all`
     * (null: no organisation condition — super-admin views, the caller checks), else the inherited view
     * (`$inherited()` — e.g. the governance rule of the area —, default own + every ancestor).
     *
     * @param (callable(): list<\App\Entity\Tenant>)|null $inherited
     * @return list<\App\Entity\Tenant>|null
     */
    public static function viewTenants(\App\Entity\Tenant $tenant, mixed $view, ?callable $inherited = null): ?array
    {
        return match ($view) {
            'own' => [$tenant],
            'subsidiaries' => array_values([$tenant, ...$tenant->getAllSubsidiaries()]),
            'orphaned', 'all' => null,
            default => $inherited !== null ? $inherited() : array_values([$tenant, ...$tenant->getAllAncestors()]),
        };
    }

    public function alias(): string
    {
        return $this->queryBuilder->getRootAliases()[0];
    }

    /** Loads a relation the rows show together with the page (no query per row). */
    public function with(string $join, string $alias): self
    {
        $this->queryBuilder->leftJoin($join, $alias)->addSelect($alias);

        return $this;
    }

    /** A join used only for filtering (not selected). */
    public function leftJoin(string $join, string $alias): self
    {
        $this->queryBuilder->leftJoin($join, $alias);

        return $this;
    }

    /** A fixed condition; `$params` are bound by name. @param array<string, mixed> $params */
    public function where(string $dql, array $params = []): self
    {
        $this->queryBuilder->andWhere($dql);
        foreach ($params as $name => $value) {
            $this->queryBuilder->setParameter($name, $value);
        }

        return $this;
    }

    /** `field = value`, skipped for null/''. */
    public function equals(string $field, mixed $value): self
    {
        if ($value === null || $value === '' || is_array($value)) {
            return $this;
        }
        $name = $this->next();

        return $this->where("$field = :$name", [$name => $value]);
    }

    /** `field IN (values)`; null skips, an empty list matches nothing. @param list<mixed>|null $values */
    public function in(string $field, ?array $values): self
    {
        if ($values === null) {
            return $this;
        }
        if ($values === []) {
            return $this->where('1 = 0');
        }
        $name = $this->next();

        return $this->where("$field IN (:$name)", [$name => $values]);
    }

    /** `field NOT IN (values)`, skipped for an empty list. @param list<mixed> $values */
    public function notIn(string $field, array $values): self
    {
        if ($values === []) {
            return $this;
        }
        $name = $this->next();

        return $this->where("($field IS NULL OR $field NOT IN (:$name))", [$name => $values]);
    }

    /** Records of these organisations (`<alias>.tenant IN`); null = no organisation condition. @param list<object>|null $tenants */
    public function tenants(?array $tenants): self
    {
        return $this->in($this->alias() . '.tenant', $tenants);
    }

    /** Case-insensitive "contains" over any of `$fields` (the list skeleton's `q`). @param list<string> $fields */
    public function contains(array $fields, mixed $text): self
    {
        $text = is_scalar($text) ? trim((string) $text) : '';
        if ($text === '' || $fields === []) {
            return $this;
        }
        $name = $this->next();
        $or = array_map(static fn (string $field): string => "LOWER($field) LIKE :$name", $fields);

        return $this->where('(' . implode(' OR ', $or) . ')', [$name => mb_strtolower(LikePattern::contains($text))]);
    }

    /** @param list<string> $fields */
    public function search(Request $request, array $fields): self
    {
        return $this->contains($fields, $request->query->get(ListPage::SEARCH_PARAM));
    }

    /** `?scope=in|out` of the ISMS scope filter on entities with `inIsmsScope`. */
    public function ismsScope(Request $request): self
    {
        $scope = (string) $request->query->get('scope', '');
        if ($scope !== 'in' && $scope !== 'out') {
            return $this;
        }
        $name = $this->next();

        return $this->where($this->alias() . ".inIsmsScope = :$name", [$name => $scope === 'in']);
    }

    /**
     * Organisations the filtered rows belong to (for per-organisation rules such as risk bands), without the
     * tenant-less rows. @return list<object>
     */
    public function rowTenants(): array
    {
        $ids = (clone $this->queryBuilder)->resetDQLPart('orderBy')
            ->select(sprintf('DISTINCT IDENTITY(%s.tenant) AS t', $this->alias()))
            ->getQuery()->getSingleColumnResult();
        $em = $this->queryBuilder->getEntityManager();

        return array_values(array_filter(array_map(
            static fn (mixed $id): ?object => $id !== null ? $em->find(\App\Entity\Tenant::class, (int) $id) : null,
            $ids,
        )));
    }

    /**
     * Runs `$read` with the Doctrine tenant filter suspended — for the super-admin list views "orphaned" and
     * "all"; the caller checks ROLE_SUPER_ADMIN.
     *
     * @template T
     * @param callable(): T $read
     * @return T
     */
    public function acrossTenants(callable $read): mixed
    {
        $filters = $this->queryBuilder->getEntityManager()->getFilters();
        if (!$filters->isEnabled('tenant_filter')) {
            return $read();
        }
        $filters->suspend('tenant_filter');
        try {
            return $read();
        } finally {
            $filters->restore('tenant_filter');
        }
    }

    /** Every row of the filtered list (exports; lists use {@see page()}). @return list<object> */
    public function all(): array
    {
        return array_values($this->queryBuilder->getQuery()->getResult());
    }

    /** Distinct non-empty values of `$field` in the filtered list, sorted (filter dropdowns). @return list<string> */
    public function distinct(string $field): array
    {
        $values = (clone $this->queryBuilder)->resetDQLPart('orderBy')
            ->select("DISTINCT $field AS v")
            ->setMaxResults(1000)
            ->getQuery()->getSingleColumnResult();
        $values = array_values(array_unique(array_filter(array_map('strval', array_filter($values, 'is_scalar')), static fn (string $v): bool => $v !== '')));
        sort($values);

        return $values;
    }

    /** Rows of the filtered list that also meet `$dql` (summary chips). @param array<string, mixed> $params */
    public function countWhere(?string $dql = null, array $params = []): int
    {
        $count = clone $this->queryBuilder;
        $count->resetDQLPart('orderBy')->select(sprintf('COUNT(DISTINCT %s.id)', $this->alias()));
        if ($dql !== null) {
            $count->andWhere($dql);
            foreach ($params as $name => $value) {
                $count->setParameter($name, $value);
            }
        }

        return (int) $count->getQuery()->getSingleScalarResult();
    }

    /** One page of the list: a COUNT and one LIMIT/OFFSET query; a page past the end shows the last page. */
    public function page(Request $request): ListPage
    {
        $total = $this->countWhere();
        $perPage = ListPage::perPage($request);
        $page = min(ListPage::pageNumber($request), max(1, (int) ceil($total / $perPage)));
        $query = (clone $this->queryBuilder)
            ->setFirstResult(($page - 1) * $perPage)
            ->setMaxResults($perPage)
            ->getQuery();
        // A joined collection would repeat root rows inside the page: the paginator pages by distinct root ids then.
        $items = $this->queryBuilder->getDQLPart('join') !== []
            ? iterator_to_array(new Paginator($query, true), false)
            : $query->getResult();

        return ListPage::fromSlice(array_values($items), $total, $request);
    }

    private function next(): string
    {
        return 'lq' . ++$this->parameter;
    }
}
