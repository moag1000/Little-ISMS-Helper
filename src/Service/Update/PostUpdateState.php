<?php

declare(strict_types=1);

namespace App\Service\Update;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What the automatic post-update run did last, kept in `var/post-update-state.json`
 * (a file, not the database: the run exists to repair a database that is behind the code).
 *
 * `stat` is the cheap change detector the request subscriber compares on every request
 * (three file stats); `fingerprint` is the real "which code version is this" answer
 * ({@see PostUpdateRunner::fingerprint()}).
 *
 * @phpstan-type State array{
 *     fingerprint: string, stat: string, status: 'ok'|'failed'|'running', version: string,
 *     previous_version: ?string, started_at: string, finished_at: ?string, trigger: string,
 *     steps: list<array{name: string, status: 'ok'|'failed'|'skipped', message: string}>,
 *     error: ?string
 * }
 */
class PostUpdateState
{
    private readonly string $path;

    public function __construct(
        #[Autowire('%kernel.project_dir%')]
        string $projectDir,
    ) {
        $this->path = $projectDir . '/var/post-update-state.json';
    }

    /** @return State|null */
    public function read(): ?array
    {
        if (!is_readable($this->path)) {
            return null;
        }
        $data = json_decode((string) @file_get_contents($this->path), true);

        return is_array($data) && isset($data['fingerprint'], $data['status']) ? $data : null;
    }

    /** @param State $state */
    public function write(array $state): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($this->path, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), LOCK_EX);
    }
}
