<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Tenant;
use App\Enum\SuggestionOrigin;
use App\Repository\SuggestionRepository;
use App\Service\TenantContext;
use Psr\Log\LoggerInterface;

/**
 * Runs the kinds for one organisation (nightly via app:suggestions:generate,
 * on demand via the "check now" button). Rule, inheritance and import kinds
 * return everything that holds right now, so their pending entries that are
 * not proposed again are superseded ("condition no longer holds"). AI kinds
 * only add, they are never swept.
 */
class SuggestionGenerator
{
    public function __construct(
        private readonly SuggestionQueue $queue,
        private readonly SuggestionRepository $repository,
        private readonly TenantContext $tenantContext,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param list<string>|null $onlyKinds
     * @return array{created: int, superseded: int, failed: list<string>}
     */
    public function run(Tenant $tenant, ?array $onlyKinds = null): array
    {
        $created = 0;
        $superseded = 0;
        $failed = [];
        // Outside a request the Doctrine tenant filter is unarmed; scope it.
        $generate = function () use ($tenant, $onlyKinds, &$created, &$superseded, &$failed): void {
            foreach ($this->queue->kinds() as $key => $kind) {
                if ($onlyKinds !== null && !in_array($key, $onlyKinds, true)) {
                    continue;
                }
                if (!$this->queue->isKindActive($kind, $tenant)) {
                    continue;
                }
                try {
                    $offeredKeys = [];
                    foreach ($kind->generate($tenant) as $draft) {
                        $offeredKeys[$draft->resolveDedupKey($key)] = true;
                        if ($this->queue->offer($tenant, $kind, $draft) !== null) {
                            $created++;
                        }
                    }
                    if ($kind->origin() !== SuggestionOrigin::Ai) {
                        foreach ($this->repository->findPending($tenant, $key) as $pending) {
                            if (!isset($offeredKeys[$pending->getDedupKey()])) {
                                $this->queue->supersede($pending, 'condition_gone');
                                $superseded++;
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    $failed[] = $key;
                    $this->logger->error('Suggestion kind failed', [
                        'kind' => $key,
                        'tenant' => $tenant->getId(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        };
        $this->tenantContext->runAs($tenant, $generate);

        return ['created' => $created, 'superseded' => $superseded, 'failed' => $failed];
    }
}
