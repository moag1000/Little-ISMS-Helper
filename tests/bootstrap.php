<?php

declare(strict_types=1);

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

// Run schema:reconcile --non-destructive-only once per test session to eliminate
// local-vs-CI schema drift caused by PREPARE/EXECUTE legacy migrations (see CLAUDE.md pitfall #6).
// Only runs in the 'test' environment and only when a DB connection is available.
// CI already runs this in its pipeline bootstrap; this guards local dev runs.
if (($_SERVER['APP_ENV'] ?? 'dev') === 'test' && !isset($_SERVER['SKIP_SCHEMA_RECONCILE'])) {
    $reconcileFlag = sys_get_temp_dir() . '/isms_schema_reconcile_' . md5(__DIR__) . '.lock';
    if (!file_exists($reconcileFlag) || (time() - filemtime($reconcileFlag)) > 3600) {
        // Run reconcile; suppress output unless it fails so tests remain readable
        $cmd = sprintf(
            'php %s/bin/console app:schema:reconcile --non-destructive-only --env=test 2>&1',
            escapeshellarg(dirname(__DIR__))
        );
        exec($cmd, $output, $exitCode);
        // Touch the lock file even on failure so we don't hammer a broken DB on every test file
        file_put_contents($reconcileFlag, date('c'));
        if ($exitCode !== 0) {
            // Non-fatal: tests might still pass if the missing columns are not exercised
            fwrite(STDERR, "⚠ schema:reconcile exited $exitCode:\n" . implode("\n", $output) . "\n");
        }
    }
}
