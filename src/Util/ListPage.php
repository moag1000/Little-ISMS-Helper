<?php

declare(strict_types=1);

namespace App\Util;

use Symfony\Component\HttpFoundation\Request;

/**
 * One page of a list view (UI wave C, list skeleton).
 *
 * Wraps the already-loaded, already-filtered result of an index action and cuts
 * it into pages, optionally narrowing it by the shared search parameter `q`.
 * Rendering fewer rows is where list pages lose their time (Twig per row), so
 * this pays off even though the query still loads the tenant's full set; lists
 * that outgrow that move to a repository-level limit/offset like the audit log.
 *
 * Twig: `{% for risk in page %}`, `page.total`, `page.pages`; the pager macro
 * `_fa_pager.render(page)` and the `_fa_list_page` skeleton read the rest.
 *
 * @template T
 * @implements \IteratorAggregate<int, T>
 */
final readonly class ListPage implements \IteratorAggregate, \Countable
{
    public const array PER_PAGE_OPTIONS = [25, 50, 100];
    public const int DEFAULT_PER_PAGE = 50;
    public const string SEARCH_PARAM = 'q';

    /**
     * @param list<T> $items items of the current page
     */
    private function __construct(
        public array $items,
        public int $page,
        public int $perPage,
        public int $total,
        public string $search,
    ) {
    }

    /**
     * @param iterable<T>                              $items      full, already filtered result
     * @param (callable(T): (string|array<?string>))|null $searchText strings `q` is matched against
     *
     * @return self<T>
     */
    public static function fromRequest(iterable $items, Request $request, ?callable $searchText = null): self
    {
        $all = is_array($items) ? array_values($items) : iterator_to_array($items, false);

        $search = trim((string) $request->query->get(self::SEARCH_PARAM, ''));
        if ($search !== '' && $searchText !== null) {
            $all = array_values(array_filter(
                $all,
                static fn (mixed $item): bool => mb_stripos(
                    implode(' ', array_filter((array) $searchText($item), static fn ($s): bool => $s !== null)),
                    $search,
                ) !== false,
            ));
        }

        $perPage = self::perPage($request);
        $total = count($all);
        $page = min(self::pageNumber($request), max(1, (int) ceil($total / $perPage)));

        return new self(array_slice($all, ($page - 1) * $perPage, $perPage), $page, $perPage, $total, $search);
    }

    /**
     * For lists that already page in the database (limit/offset): wraps the
     * loaded slice with the real total. Use {@see perPage()} / {@see pageNumber()}
     * to build the query.
     *
     * @param list<T> $slice
     *
     * @return self<T>
     */
    public static function fromSlice(array $slice, int $total, Request $request): self
    {
        $perPage = self::perPage($request);

        return new self(
            $slice,
            min(self::pageNumber($request), max(1, (int) ceil($total / $perPage))),
            $perPage,
            $total,
            trim((string) $request->query->get(self::SEARCH_PARAM, '')),
        );
    }

    /** Page size requested via `per_page`, limited to {@see PER_PAGE_OPTIONS}. */
    public static function perPage(Request $request): int
    {
        $perPage = $request->query->getInt('per_page', self::DEFAULT_PER_PAGE);

        return in_array($perPage, self::PER_PAGE_OPTIONS, true) ? $perPage : self::DEFAULT_PER_PAGE;
    }

    public static function pageNumber(Request $request): int
    {
        return max(1, $request->query->getInt('page', 1));
    }

    public function pages(): int
    {
        return max(1, (int) ceil($this->total / $this->perPage));
    }

    /** 1-based position of the first item on this page (0 when empty). */
    public function firstItem(): int
    {
        return $this->total === 0 ? 0 : ($this->page - 1) * $this->perPage + 1;
    }

    public function lastItem(): int
    {
        return min($this->page * $this->perPage, $this->total);
    }

    /** Page numbers to show, with null for a gap: 1 … 4 5 6 … 20. @return list<int|null> */
    public function window(int $around = 1): array
    {
        $pages = $this->pages();
        $out = [];
        $last = 0;
        for ($p = 1; $p <= $pages; $p++) {
            if ($p === 1 || $p === $pages || abs($p - $this->page) <= $around) {
                if ($last !== 0 && $p - $last > 1) {
                    $out[] = null;
                }
                $out[] = $p;
                $last = $p;
            }
        }

        return $out;
    }

    public function isEmpty(): bool
    {
        return $this->total === 0;
    }

    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->items);
    }

    public function count(): int
    {
        return count($this->items);
    }
}
