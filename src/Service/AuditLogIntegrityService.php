<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\AuditLog;
use App\Repository\AuditLogRepository;

/**
 * AUD-02: Signs audit-log rows with HMAC-SHA256 and chains them via previousHmac.
 *
 * Chain:   row_N.hmac = HMAC(payload(row_N) including row_{N-1}.hmac).
 * Deletion or tampering of any row breaks verification of the next row's HMAC.
 *
 * Secret is read from env APP_AUDIT_HMAC_KEY (must be set in production;
 * empty secret falls back to a derived key and emits no signature to avoid
 * a false sense of integrity).
 */
class AuditLogIntegrityService
{
    private const ALGO = 'sha256';

    private readonly string $hmacSecret;

    public function __construct(
        private readonly AuditLogRepository $auditLogRepository,
        ?string $hmacSecret,
    ) {
        $this->hmacSecret = (string) $hmacSecret;
    }

    public function isEnabled(): bool
    {
        return $this->hmacSecret !== '';
    }

    /**
     * Called before AuditLog is persisted. Sets previousHmac from the latest
     * row and computes the row's own hmac.
     */
    public function sign(AuditLog $log): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $latest = $this->auditLogRepository->findLatestSignedHmac();
        $log->setPreviousHmac($latest);
        $log->setHmac(hash_hmac(self::ALGO, $log->getSigningPayload(), $this->hmacSecret));
    }

    /**
     * Re-sign a row with an explicit previousHmac — used by the
     * app:audit-log:resign command when walking the chain from a
     * specific starting point. Bypasses findLatestSignedHmac() so
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
     * Walks the chain from the oldest signed row forward and returns a list of
     * (row_id, reason) tuples for every broken link.
     *
     * @return list<array{id:int,reason:string}>
     */
    public function verifyChain(): array
    {
        $issues = [];
        if (!$this->isEnabled()) {
            return [['id' => 0, 'reason' => 'hmac_secret_not_configured']];
        }

        $previousHash = null;
        foreach ($this->auditLogRepository->findAllSignedOrdered() as $log) {
            if ($log->getPreviousHmac() !== $previousHash) {
                $issues[] = ['id' => (int) $log->getId(), 'reason' => 'previous_hmac_mismatch'];
            }
            if (!$this->verify($log)) {
                $issues[] = ['id' => (int) $log->getId(), 'reason' => 'hmac_invalid'];
            }
            $previousHash = $log->getHmac();
        }

        return $issues;
    }
}
