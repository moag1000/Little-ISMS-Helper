<?php

declare(strict_types=1);

namespace App\Tests\Util;

use App\Util\ListPage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

final class ListPageTest extends TestCase
{
    #[Test]
    public function cutsTheRequestedPage(): void
    {
        $page = ListPage::fromRequest(range(1, 120), new Request(['page' => '2', 'per_page' => '50']));

        self::assertSame(range(51, 100), $page->items);
        self::assertSame(2, $page->page);
        self::assertSame(3, $page->pages());
        self::assertSame(51, $page->firstItem());
        self::assertSame(100, $page->lastItem());
        self::assertCount(50, $page);
    }

    #[Test]
    public function clampsPageAndRejectsUnknownPageSizes(): void
    {
        $page = ListPage::fromRequest(range(1, 30), new Request(['page' => '99', 'per_page' => '7']));

        self::assertSame(ListPage::DEFAULT_PER_PAGE, $page->perPage);
        self::assertSame(1, $page->page);
        self::assertSame(30, $page->lastItem());
    }

    #[Test]
    public function searchNarrowsBeforePaging(): void
    {
        $items = [['n' => 'Firewall'], ['n' => 'Backup'], ['n' => 'firewall rules']];
        $page = ListPage::fromRequest($items, new Request(['q' => 'FIRE']), static fn (array $i): string => $i['n']);

        self::assertSame(2, $page->total);
        self::assertSame('FIRE', $page->search);
    }

    #[Test]
    public function searchIsIgnoredWithoutSearchableFields(): void
    {
        $page = ListPage::fromRequest(['a', 'b'], new Request(['q' => 'zzz']));

        self::assertSame(2, $page->total);
    }

    #[Test]
    public function emptyListIsOnePageWithoutItems(): void
    {
        $page = ListPage::fromRequest([], new Request());

        self::assertTrue($page->isEmpty());
        self::assertSame(1, $page->pages());
        self::assertSame(0, $page->firstItem());
    }

    #[Test]
    public function windowCollapsesDistantPages(): void
    {
        $page = ListPage::fromRequest(range(1, 500), new Request(['page' => '5', 'per_page' => '25']));

        self::assertSame([1, null, 4, 5, 6, null, 20], $page->window());
    }

    #[Test]
    public function slicePaginatedInTheDatabaseKeepsTheRealTotal(): void
    {
        $page = ListPage::fromSlice([51, 52], 120, new Request(['page' => '2', 'per_page' => '50']));

        self::assertSame(120, $page->total);
        self::assertSame(2, $page->page);
        self::assertSame([51, 52], $page->items);
    }
}
