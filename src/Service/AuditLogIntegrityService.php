<?php

declare(strict_types=1);

namespace App\Service;

use App\Doctrine\TenantFilterState;
use App\Entity\AuditLog;
use App\Entity\Tenant;
use App\Repository\SystemSettingsRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\ORM\EntityManagerInterface;

/**
 * AUD-02: Signs audit-log rows with HMAC-SHA256 and chains them via previousHmac.
 *
 * Chain:   row_N.hmac = HMAC(payload(row_N) including row_{N-1}.hmac).
 * Deletion or tampering of any row breaks verification of the next row's HMAC.
 *
 * One chain per organisation (B3, 2026-11): row_{N-1} is the newest signed row
 * of the SAME organisation (tenant_id), rows without organisation form the
 * instance chain. The predecessor is read without the Doctrine tenant filter,
 * so a request, a console command and a job sign the same way. Before B3 the
 * predecessor depended on the filter of the writing context (own organisation
 * in a request, the whole table in console/jobs), so older rows may point to
 * another organisation's row; {@see verifyChain()} accepts such a link when the
 * row it points to exists and is older — a deleted or rewritten row still breaks it.
 *
 * Secret is read from env APP_AUDIT_HMAC_KEY (must be set in production;
 * empty secret falls back to a derived key and emits no signature to avoid
 * a false sense of integrity).
 */
final class AuditLogIntegrityService
{
    private const string ALGO = 'sha256';

    /** Rows kept managed before the verification walk clears the EntityManager. */
    private const int BATCH = 500;

    private readonly string $hmacSecret;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        ?string $hmacSecret,
        private readonly ?SystemSettingsRepository $settings = null,
    ) {
        $this->hmacSecret = (string) $hmacSecret;
    }

    public function isEnabled(): bool
    {
        return $this->hmacSecret !== '';
    }

    /**
     * Called before AuditLog is persisted. Sets previousHmac from the latest
     * signed row of the log's organisation and computes the row's own hmac.
     */
    public function sign(AuditLog $log): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $log->setPreviousHmac($this->latestHmac($log->getTenant()?->getId()));
        $log->setHmac(hash_hmac(self::ALGO, $log->getSigningPayload(), $this->hmacSecret));
    }

    /**
     * Re-sign a row with an explicit previousHmac — used by the
     * app:audit-log:resign command when walking the chain from a
     * specific starting point. Bypasses the latest-row lookup so
     * mid-chain re-signing does not inherit the terminal HMAC as
     * every row's predecessor (which would corrupt the chain).
     */
    public function signWithPrevious(AuditLog $log, ?string $previousHmac): void
    {
        if (!$this->isEnabled()) {
            return;
        }
        $log->setPreviousHmac($previousHmac);
        $log->setHmac(hash_hmac(self::ALGO, $log->getSigningPayload(), $this->hmacSecret));
    }

    /**
     * Newest signed HMAC of one chain (organisation id, null = instance chain),
     * optionally only among rows with id <= $upToId. Reads past the tenant filter.
     */
    public function latestHmac(?int $tenantId, ?int $upToId = null): ?string
    {
        $sql = 'SELECT hmac FROM audit_log WHERE hmac IS NOT NULL AND '
            . ($tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant');
        $params = $tenantId === null ? [] : ['tenant' => $tenantId];
        if ($upToId !== null) {
            $sql .= ' AND id <= :upTo';
            $params['upTo'] = $upToId;
        }
        $hmac = $this->entityManager->getConnection()
            ->fetchOne($sql . ' ORDER BY id DESC LIMIT 1', $params);

        return is_string($hmac) ? $hmac : null;
    }

    /**
     * Verifies a single row's HMAC against its current payload.
     */
    public function verify(AuditLog $log): bool
    {
        if (!$this->isEnabled() || $log->getHmac() === null) {
            return false;
        }
        $expected = hash_hmac(self::ALGO, $log->getSigningPayload(), $this->hmacSecret);
        return hash_equals($expected, $log->getHmac());
    }

    /**
     * Ids of every chain: one per organisation that has signed rows, null for the instance chain.
     *
     * @return list<int|null>
     */
    public function chainScopes(): array
    {
        $ids = $this->entityManager->getConnection()->fetchFirstColumn(
            'SELECT DISTINCT tenant_id FROM audit_log WHERE hmac IS NOT NULL',
        );
        $scopes = array_map(static fn (mixed $id): ?int => $id === null ? null : (int) $id, $ids);
        sort($scopes);

        return $scopes;
    }

    /**
     * Walks the chain of one organisation from its oldest signed row forward and
     * returns every broken link. $allChains = true walks every chain
     * (instance-wide check for a SUPER_ADMIN without organisation and the CLI).
     *
     * The oldest remaining row of a chain is its anchor: the retention cleanup
     * deletes old rows, so its predecessor may be gone. Rows up to
     * system_settings(audit_log, hmac_legacy_until_id) were signed over microseconds
     * the column never kept: their content counts as "unverifiable", their links are
     * still checked. Memory stays flat — the EntityManager is cleared every
     * {@see self::BATCH} rows.
     *
     * @param callable(int $checked): void|null $onProgress
     */
    public function verifyChain(?Tenant $tenant = null, bool $allChains = false, ?callable $onProgress = null): AuditChainReport
    {
        if (!$this->isEnabled()) {
            return new AuditChainReport([['id' => 0, 'reason' => 'hmac_secret_not_configured']], 0, 0);
        }

        $legacyUntil = (int) ($this->settings?->getSetting('audit_log', 'hmac_legacy_until_id', 0) ?? 0);
        $scopes = $allChains ? $this->chainScopes() : [$tenant?->getId()];
        $issues = [];
        $checked = 0;
        $unverifiable = 0;
        foreach ($scopes as $scope) {
            array_push($issues, ...$this->verifyScope($scope, $legacyUntil, $checked, $unverifiable, $onProgress));
        }
        usort($issues, static fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return new AuditChainReport($issues, $checked, $unverifiable);
    }

    /**
     * Signed rows in the chain.
     */
    public function countSigned(?Tenant $tenant = null, bool $allChains = false): int
    {
        $sql = 'SELECT COUNT(*) FROM audit_log WHERE hmac IS NOT NULL';
        if ($allChains) {
            return (int) $this->entityManager->getConnection()->fetchOne($sql);
        }
        $id = $tenant?->getId();

        return (int) $this->entityManager->getConnection()->fetchOne(
            $sql . ($id === null ? ' AND tenant_id IS NULL' : ' AND tenant_id = :tenant'),
            $id === null ? [] : ['tenant' => $id],
        );
    }

    /**
     * @param callable(int $checked): void|null $onProgress
     *
     * @return list<array{id:int,reason:string}>
     */
    private function verifyScope(
        ?int $tenantId,
        int $legacyUntil,
        int &$checked,
        int &$unverifiable,
        ?callable $onProgress,
    ): array {
        $walk = function () use ($tenantId, $legacyUntil, &$checked, &$unverifiable, $onProgress): array {
            $qb = $this->entityManager->createQueryBuilder()
                ->select('a')
                ->from(AuditLog::class, 'a')
                ->where('a.hmac IS NOT NULL')
                ->orderBy('a.id', \SortDirection::Ascending);
            if ($tenantId === null) {
                $qb->andWhere('a.tenant IS NULL');
            } else {
                $qb->andWhere('IDENTITY(a.tenant) = :tenant')->setParameter('tenant', $tenantId);
            }

            $issues = [];
            /** @var array<string, list<int>> $unresolved previousHmac => ids of the rows that point to it */
            $unresolved = [];
            $previousHash = null;
            $first = true;
            $inBatch = 0;
            foreach ($qb->getQuery()->toIterable() as $log) {
                /** @var AuditLog $log */
                $id = (int) $log->getId();
                if (!$this->verify($log)) {
                    if ($id <= $legacyUntil) {
                        ++$unverifiable;
                    } else {
                        $issues[] = ['id' => $id, 'reason' => 'hmac_invalid'];
                    }
                }
                $pointsTo = $log->getPreviousHmac();
                if (!$first && $pointsTo !== $previousHash) {
                    if ($pointsTo === null) {
                        $issues[] = ['id' => $id, 'reason' => 'previous_hmac_mismatch'];
                    } else {
                        // Signed before B3 (or two rows signed at the same instant):
                        // valid only if the row it names still exists and is older.
                        $unresolved[$pointsTo][] = $id;
                    }
                }
                $previousHash = $log->getHmac();
                $first = false;
                ++$checked;

                if (++$inBatch >= self::BATCH) {
                    $this->entityManager->clear();
                    $inBatch = 0;
                    if ($onProgress !== null) {
                        $onProgress($checked);
                    }
                }
                if (count($unresolved) >= self::BATCH) {
                    array_push($issues, ...$this->resolveLinks($unresolved));
                    $unresolved = [];
                }
            }
            $this->entityManager->clear();
            array_push($issues, ...$this->resolveLinks($unresolved));
            if ($onProgress !== null) {
                $onProgress($checked);
            }

            return $issues;
        };

        return TenantFilterState::suspended($this->entityManager, $walk);
    }

    /**
     * @param array<string, list<int>> $unresolved
     *
     * @return list<array{id:int,reason:string}>
     */
    private function resolveLinks(array $unresolved): array
    {
        if ($unresolved === []) {
            return [];
        }
        $rows = $this->entityManager->getConnection()->fetchAllKeyValue(
            'SELECT hmac, MIN(id) FROM audit_log WHERE hmac IN (:hashes) GROUP BY hmac',
            ['hashes' => array_map('strval', array_keys($unresolved))],
            ['hashes' => ArrayParameterType::STRING],
        );
        $issues = [];
        foreach ($unresolved as $hash => $ids) {
            $foundId = isset($rows[$hash]) ? (int) $rows[$hash] : null;
            foreach ($ids as $id) {
                if ($foundId === null || $foundId >= $id) {
                    $issues[] = ['id' => $id, 'reason' => 'previous_hmac_mismatch'];
                }
            }
        }

        return $issues;
    }
}
