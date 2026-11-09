<?php

declare(strict_types=1);

namespace App\Job;

use App\Exception\Security\AuditChainBrokenException;
use App\Service\AuditLogIntegrityService;
use App\Service\Job\JobTenantScope;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "HMAC-Kette verifizieren" on the audit-log page (AUD-02). Walks the chain of
 * the dispatching user's organisation; a SUPER_ADMIN without organisation walks
 * every chain. Async because a large audit log takes longer than a request may
 * run (B3, sweep 5 F3). A broken chain fails the job, so the page shows it red
 * with the first broken entries.
 *
 * Args: tenantId / allTenants ({@see JobTenantScope}), locale.
 */
final class VerifyAuditLogChainJob implements AsyncJobInterface
{
    public function __construct(
        private readonly AuditLogIntegrityService $integrity,
        private readonly JobTenantScope $tenantScope,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenant = $this->tenantScope->resolve($ctx);
        $allChains = $tenant === null;
        $locale = is_string($ctx->arg('locale')) ? $ctx->arg('locale') : 'de';

        if (!$this->integrity->isEnabled()) {
            throw new AuditChainBrokenException($this->t('audit_log.integrity.disabled', [], $locale));
        }

        $total = $this->integrity->countSigned($tenant, $allChains);
        $ctx->progress(0, $total, $this->t('audit_log.integrity.job_running', [], $locale));
        $report = $this->integrity->verifyChain(
            $tenant,
            $allChains,
            static fn (int $checked) => $ctx->progress($checked, $total),
        );
        $issues = $report->issues;

        if ($issues !== []) {
            $sample = array_map(
                static fn (array $i): string => sprintf('#%d (%s)', $i['id'], $i['reason']),
                array_slice($issues, 0, 5),
            );
            throw new AuditChainBrokenException($this->t('audit_log.integrity.violations', [
                '%count%' => count($issues),
                '%entries%' => implode(' · ', $sample),
            ], $locale));
        }

        $message = $this->t('audit_log.integrity.intact_count', ['%count%' => $report->checked], $locale);
        if ($report->unverifiable > 0) {
            $message .= ' ' . $this->t('audit_log.integrity.unverifiable', ['%count%' => $report->unverifiable], $locale);
        }
        $ctx->progress($total, $total, $message);
    }

    /**
     * @param array<string, int|string> $params
     */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translator->trans($key, $params, 'audit_log', $locale);
    }
}
