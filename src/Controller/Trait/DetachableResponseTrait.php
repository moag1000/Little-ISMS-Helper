<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Response;

/**
 * Provides {@see self::detachAndContinue()} — the canonical shared-hosting
 * pattern for "send the response NOW, then keep working in the same PHP-FPM
 * worker for as long as needed".
 *
 * Background:
 *  - Long-running admin tasks (>30 s) hit PHP-FPM's max_execution_time and
 *    return 504/502 to the browser before any progress reaches the client.
 *  - The "right" fix is Symfony Messenger + a long-running worker daemon, but
 *    that requires systemd / supervisord — unavailable on commodity shared
 *    hosting (1&1, Strato, Hetzner shared, Hosteurope managed PHP, …).
 *  - {@see \fastcgi_finish_request()} (FPM) and
 *    {@see \litespeed_finish_request()} (LiteSpeed/OpenLiteSpeed) close the
 *    HTTP connection while the PHP process keeps executing. The browser sees
 *    "started" instantly, then polls a status endpoint.
 *
 * Output-buffering trap: with PHP's default `output_buffering=4096`, calling
 * `$response->send()` writes the body into a buffer that only flushes on
 * script exit — defeating the early-detach. We MUST drain every active output
 * buffer (potentially nested by Symfony's StreamedResponse handlers and by
 * third-party bundles) before invoking `fastcgi_finish_request()`. Otherwise
 * the FCGI stream stays open and the browser hangs on the POST until the
 * reverse-proxy gateway-timeout fires (typically 30–60 s).
 *
 * @see \App\Service\Job\InRequestJobRunner — uses this to dispatch
 *      AsyncJobInterface implementations on shared hosting.
 * @see \App\Controller\DeploymentWizardController — original consumer
 *      (setup-wizard long migrations).
 */
trait DetachableResponseTrait
{
    /**
     * Sends $response immediately, drains every output buffer, then asks the
     * PHP-FPM/LiteSpeed worker to close the client connection. The worker
     * process continues executing until script end — letting long jobs
     * complete after the browser has already moved on.
     *
     * No-op on CLI or non-FPM SAPIs (e.g. mod_php, php -S) — the caller is
     * responsible for handling those code-paths synchronously.
     */
    private function detachAndContinue(Response $response): void
    {
        $response->send();
        // ob_get_level() can return >1 when Symfony's StreamedResponse or any
        // third-party bundle nested another output buffer on top of PHP's
        // default. Drain each one — silencing failures so a buffer that's
        // already in a closed state does not throw.
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
    }
}
