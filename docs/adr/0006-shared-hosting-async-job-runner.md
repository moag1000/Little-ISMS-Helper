# ADR-0006: Shared-Hosting Async Job Runner via `fastcgi_finish_request()`

**Status:** Accepted  
**Date:** 2026-04-15  
**Deciders:** moag1000  
**Tags:** async, jobs, shared-hosting, messenger, performance

---

## Context

Several admin operations exceed the typical PHP-FPM 30-second request timeout:

| Operation | Typical duration |
|---|---|
| Full database backup (100 MB) | 45–120 s |
| Restore from backup | 60–180 s |
| Bulk risk import (1000 rows) | 30–90 s |
| Schema reconcile (drift repair) | 15–60 s |
| Cross-tenant analytics report | 20–60 s |

The canonical Symfony solution for long-running background jobs is **Symfony Messenger** with a
dedicated worker process (`bin/console messenger:consume async`). However, the target operator
demographic includes:

- Small DACH law firms running on shared cPanel/Plesk hosting
- KRITIS operators with strict "no worker processes" network policy
- NGOs and public bodies without shell access to their hosting provider
- MSPs managing 10–50 tenants on a single VPS with no process manager

For all of these, starting and keeping a persistent Messenger worker alive is either impossible
(no shell access) or operationally costly (systemd unit, health monitoring, log rotation). The
prior approach of redirecting to a "please wait" page and polling for completion with a hard 30-s
PHP timeout produced timeouts on larger datasets.

---

## Decision

**Implement a dual-strategy async job system with `in_request` as the default**, switchable to
`messenger` via environment variable for dedicated infrastructure.

### Strategy 1: `in_request` (default — shared-hosting friendly)

`InRequestJobRunner` (at `src/Service/Job/InRequestJobRunner.php`) uses:
1. Buffer the response body (progress-page HTML).
2. Flush all output buffers and call `fastcgi_finish_request()` (or `litespeed_finish_request()`
   on LiteSpeed). This signals to the FPM upstream that the HTTP response is complete — the browser
   receives the page and begins polling.
3. Continue executing the job in the same PHP-FPM worker process, past the "response sent" point.
4. Write job status updates to `var/jobs/<uuid>.json` throughout execution.
5. A Stimulus `async-job` controller polls `GET /admin/jobs/{uuid}/status` every 3 seconds and
   updates the progress bar in the browser.

Under CLI (PHPUnit, console commands), `fastcgi_finish_request()` does not exist; the runner falls
back to synchronous execution. No test changes required.

### Strategy 2: `messenger` (opt-in)

Set `APP_ASYNC_JOB_RUNNER=messenger`. `MessengerJobRunner` dispatches an `ExecuteJobMessage` to the
doctrine async transport. A separate `messenger:consume async` worker picks it up. Worker-Health-UI
at `/admin/queue-status` shows queue depth + last heartbeat + emergency-trigger button.

### Controller ergonomics: `AsyncJobDispatcher` facade (P-16)

To avoid a 5-step ritual in every controller, the `AsyncJobDispatcher` facade provides a single
method:

```php
return $this->asyncJobDispatcher->dispatchWithProgress(
    request: $request,
    jobClass: MyJob::class,
    jobArgs: ['tenantId' => $tenantId],
    jobName: 'admin.my_job',
    payload: ['_label' => '…', '_subtitle' => '…'],
    returnUrl: $this->generateUrl('admin_my_index'),
);
```

This encapsulates job creation, runner dispatch, PRG redirect, and session release in one call.
The lower-level `JobDispatcher` primitive remains available for rare cases requiring direct template
rendering (XHR JSON envelope, payload patching with freshly-minted UUID).

### Critical invariant

`$this->render(...)` MUST be called before `$jobDispatcher->dispatch()` — the response body must
exist before the runner flushes it. Violating this ordering results in an empty progress page.

**Jobs must NOT depend on request-bound services** (Session, Request, FlashBag, TenantContext-via-
request). After `fastcgi_finish_request()` the session is read-only and the original request is
gone. Pass all context through `$args` at dispatch time. See `ExportRisksJob` as the reference
pattern.

---

## Consequences

### Positive

- **Works on shared hosting:** No worker process, no systemd unit, no shell access required.
  The operator installs the application like any Symfony app and long-running operations just work.
- **Graceful fallback:** CLI and test environments degrade transparently to synchronous execution.
- **Single implementation:** The same `AsyncJobInterface` is used by both strategies. Job classes
  do not change when switching runner.

### Negative

- **PHP-FPM process held:** After `fastcgi_finish_request()` the FPM worker is occupied for the
  job duration. Under high concurrency, this reduces the effective worker pool. Mitigated by the
  `pm.max_children` setting — operators should ensure at least 2× the maximum concurrent admin
  jobs.
- **No job queue management:** The `in_request` strategy provides no retry on crash, no dead-letter
  queue, no priority lanes. If the FPM process is killed mid-job, the job status file records
  `status: failed` on next restart. Messenger strategy has all standard transport guarantees.
- **`var/jobs/` accumulates:** Status files in `var/jobs/` are never automatically purged. A cron
  job pruning files older than 7 days is recommended for long-running installations.
- **`fastcgi_finish_request()` is FPM-only:** Apache mod_php and built-in CLI server do not support
  it. The runner detects the SAPI and falls back synchronously — admin ops will time out under
  non-FPM Apache. Document `mod_php` as unsupported in hosting guide.

---

## References

- `src/Service/Job/AsyncJobDispatcher.php` — P-16 facade
- `src/Service/Job/InRequestJobRunner.php` — default strategy
- `src/Service/Job/MessengerJobRunner.php` — opt-in Messenger strategy
- `src/Service/Job/JobStatusService.php` — file-based status store (`var/jobs/`)
- `src/Job/AsyncJobInterface.php` — job contract
- `src/Job/JobContext.php` — progress/message helper
- `src/Controller/Trait/DetachableResponseTrait.php` — buffer-drain + FCGI helper
- `assets/controllers/async_job_controller.js` — Stimulus polling controller
- CLAUDE.md §"Async Admin Jobs"
- `docs/user-guide/HOSTING_WORKER.md` — cron pattern for shared hosting Messenger alternative
