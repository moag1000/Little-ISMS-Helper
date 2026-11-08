<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * The schema and file-system scan behind the Quick-Fix page.
 *
 * The page is open without login (a broken login must stay recoverable), so an
 * anonymous visitor gets a snapshot that is at most {@see TTL} seconds old instead of
 * a fresh scan on every request — reloading the page must not be a way to load the
 * database. A super admin or installer-token holder always gets a fresh scan. A scan
 * that fails is never cached, so the page shows the error again on the next load.
 */
class QuickFixStatusSnapshot
{
    public const TTL = 60;
    private const KEY = 'quick_fix.status_snapshot';

    public function __construct(
        private readonly SchemaMaintenanceService $maintenance,
        private readonly CacheInterface $cache,
    ) {
    }

    /**
     * @return array{status: array<string, mixed>, pending_fs: list<string>, clean: array<string, mixed>}
     */
    public function get(bool $fresh): array
    {
        if ($fresh) {
            $this->cache->delete(self::KEY);
            return $this->scan();
        }
        return $this->cache->get(self::KEY, function (ItemInterface $item): array {
            $item->expiresAfter(self::TTL);
            return $this->scan();
        });
    }

    /**
     * Pending migrations on disk, always fresh (one directory listing + one table read).
     * When the database cannot say, applying is the recovery path, so the answer is yes.
     */
    public function hasPendingMigrations(): bool
    {
        try {
            return $this->maintenance->listPendingMigrationVersionsFromFileSystem() !== [];
        } catch (\Throwable) {
            return true;
        }
    }

    /** @return array{status: array<string, mixed>, pending_fs: list<string>, clean: array<string, mixed>} */
    private function scan(): array
    {
        return [
            'status' => $this->maintenance->getMaintenanceStatus(),
            'pending_fs' => array_values($this->maintenance->listPendingMigrationVersionsFromFileSystem()),
            'clean' => $this->maintenance->verifyClean(),
        ];
    }
}
