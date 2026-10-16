<?php

declare(strict_types=1);

namespace App\Job;

use App\Entity\Role;
use App\Repository\UserRepository;
use App\Service\Job\JobTenantScope;
use Symfony\Component\HttpKernel\KernelInterface;
use App\Util\CsvSanitizer;

/**
 * Async admin job: dump the users of the dispatching organisation (id,
 * contact, status, MFA flag, tenant, roles, auth-provider, timestamps) to
 * var/exports/<jobId>.csv.
 *
 * Mirrors {@see \App\Controller\UserManagementController::export()} —
 * same column layout (`escape: '\\\\'`) and CSV-injection sanitization so the
 * file is byte-equivalent regardless of which entry-point produced it.
 *
 * Args: the tenant scope captured by {@see JobTenantScope::argsForCurrentUser()}.
 * Like the sync export (audit M-7) only the dispatching organisation's users
 * are exported; an instance-wide dump is only possible for a SUPER_ADMIN
 * without an organisation. A job without scope fails instead of exporting
 * every tenant's users — the Doctrine tenant filter is not relied upon
 * because it is unarmed outside the HTTP request.
 *
 * Phase 3 of the async admin-jobs rollout.
 */
final class ExportUsersJob implements AsyncJobInterface
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JobTenantScope $tenantScope,
        private readonly KernelInterface $kernel,
    ) {
    }

    public function run(JobContext $ctx): void
    {
        $tenant = $this->tenantScope->resolve($ctx);

        $ctx->message('Loading user list…');
        // null tenant = explicit instance-wide export (SUPER_ADMIN without tenant only)
        $users = $this->userRepository->findBy($tenant !== null ? ['tenant' => $tenant] : []);
        $total = count($users);
        $ctx->progress(0, max($total, 1), sprintf('Building CSV for %d user(s)…', $total));

        $path = $this->path($ctx->getJobId());
        $this->ensureExportDir(dirname($path));

        $handle = fopen($path, 'w');
        if ($handle === false) {
            // @intentional-assertion: disk write failure
            throw new \RuntimeException(sprintf('Failed to open export file "%s" for writing.', $path));
        }

        try {
            fputcsv($handle, [
                'ID',
                'Email',
                'First Name',
                'Last Name',
                'Active',
                'MFA Enabled',
                'Tenant',
                'Roles',
                'Auth Provider',
                'Created At',
                'Last Login',
            ], escape: '\\');

            foreach ($users as $i => $user) {
                $roles = array_map(static fn(Role $role): ?string => $role->getName(), $user->getCustomRoles()->toArray());

                $row = [
                    $user->getId(),
                    $user->getEmail(),
                    $user->getFirstName(),
                    $user->getLastName(),
                    $user->isActive() ? 'Yes' : 'No',
                    'N/A', // MFA status — would need MfaTokenRepository to check
                    $user->getTenant() ? $user->getTenant()->getName() : '',
                    implode(', ', $roles),
                    $user->getAuthProvider(),
                    $user->getCreatedAt()?->format('Y-m-d H:i:s'),
                    $user->getLastLoginAt()?->format('Y-m-d H:i:s'),
                ];
                fputcsv($handle, array_map([CsvSanitizer::class, 'sanitize'], $row), escape: '\\');

                if (($i + 1) % 100 === 0 || $i + 1 === $total) {
                    $ctx->progress($i + 1, max($total, 1), sprintf('Wrote %d / %d user row(s)…', $i + 1, $total));
                }
            }
        } finally {
            fclose($handle);
        }

        $size = (int) (@filesize($path) ?: 0);
        $ctx->progress($total, max($total, 1), sprintf(
            'Done. Wrote %d user(s) → %s (%d KB).',
            $total,
            basename($path),
            (int) round($size / 1024),
        ));
    }

    private function ensureExportDir(string $dir): void
    {
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function path(string $jobId): string
    {
        return $this->kernel->getProjectDir() . '/var/exports/' . $jobId . '.csv';
    }
}
