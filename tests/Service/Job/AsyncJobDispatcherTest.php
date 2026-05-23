<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Job\AsyncJobInterface;
use App\Job\JobContext;
use App\Service\Job\AsyncJobDispatcher;
use App\Service\Job\InRequestJobRunner;
use App\Service\Job\JobDispatcher;
use App\Service\Job\JobStatusService;
use App\Service\Job\MessengerJobRunner;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Environment;

/**
 * Junior-ISB-Audit-2026-05-22 P-16 — covers the boilerplate-reduction facade.
 *
 * Verifies the three composition guarantees:
 *   1. JobStatusService::create() is called with the right name + payload
 *      and its return value (UUID) is threaded through the rest of the call.
 *   2. The Response handed to JobDispatcher::dispatch() is the right kind
 *      (303 RedirectResponse for dispatchWithProgress, plain Response with
 *      rendered Twig body for dispatchWithProgressTemplate).
 *   3. JobDispatcher::dispatch() receives the correct $jobClass, $jobArgs,
 *      $jobId, $response and $session.
 *
 * Why the test uses real {@see JobStatusService} + {@see JobDispatcher}:
 * both are `final` and cannot be doubled by PHPUnit's mock generator. We
 * instead inject a tmp-dir kernel and a mocked {@see InRequestJobRunner}
 * (kept non-final precisely so {@see JobDispatcherTest} can mock it) — the
 * runner is what observes the dispatch call.
 *
 * @internal
 */
#[AllowMockObjectsWithoutExpectations]
final class AsyncJobDispatcherTest extends TestCase
{
    private string $tmpDir;
    private JobStatusService $statusService;
    private MockObject&InRequestJobRunner $runner;
    private JobDispatcher $dispatcher;
    private MockObject&UrlGeneratorInterface $urlGenerator;
    private MockObject&Environment $twig;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/async-job-dispatcher-test-' . bin2hex(random_bytes(4));
        mkdir($this->tmpDir, 0o775, true);
        mkdir($this->tmpDir . '/var/jobs', 0o775, true);

        $kernel = $this->createMock(KernelInterface::class);
        $kernel->method('getProjectDir')->willReturn($this->tmpDir);

        $this->statusService = new JobStatusService($kernel);
        $this->runner = $this->createMock(InRequestJobRunner::class);
        $messengerRunner = $this->createMock(MessengerJobRunner::class);
        $this->dispatcher = new JobDispatcher($this->runner, $messengerRunner, 'in_request');
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->twig = $this->createMock(Environment::class);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->tmpDir);
    }

    #[Test]
    public function dispatchWithProgressCreatesStatusAndRedirects(): void
    {
        $payload = ['_label' => 'Run Integrity Check', '_subtitle' => 'Scanning…'];
        $jobArgs = ['tenantId' => 42];
        $session = $this->createMock(SessionInterface::class);
        $request = $this->makeRequest($session);

        // URL generator is asked for the canonical progress route with the
        // freshly-minted job-id AND the `return` query param the caller
        // supplied. Capture jobId from the generated URL.
        $capturedJobId = null;
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                AsyncJobDispatcher::PROGRESS_ROUTE,
                self::callback(function (array $params) use (&$capturedJobId): bool {
                    $capturedJobId = $params['id'] ?? null;
                    return ($params['return'] ?? null) === '/admin/example/'
                        && is_string($capturedJobId)
                        && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $capturedJobId) === 1;
                }),
            )
            ->willReturnCallback(static fn (string $_, array $params): string =>
                '/admin/jobs/' . $params['id'] . '/progress?return=' . urlencode($params['return']));

        // (2) + (3) — dispatcher's runner gets the right job-class, args,
        // UUID, a 303 RedirectResponse to the progress URL, and the session.
        $capturedResponse = null;
        $this->runner->expects(self::once())
            ->method('dispatch')
            ->with(
                StubAsyncJob::class,
                $jobArgs,
                self::callback(function (string $jobId) use (&$capturedJobId): bool {
                    return $jobId === $capturedJobId;
                }),
                self::callback(function (Response $r) use (&$capturedResponse): bool {
                    $capturedResponse = $r;
                    return $r instanceof RedirectResponse
                        && $r->getStatusCode() === Response::HTTP_SEE_OTHER;
                }),
                $session,
            )
            ->willReturnArgument(3);

        $facade = $this->makeFacade();
        $response = $facade->dispatchWithProgress(
            request: $request,
            jobClass: StubAsyncJob::class,
            jobArgs: $jobArgs,
            jobName: 'admin.example.job',
            payload: $payload,
            returnUrl: '/admin/example/',
        );

        self::assertInstanceOf(RedirectResponse::class, $response);
        self::assertSame(Response::HTTP_SEE_OTHER, $response->getStatusCode());
        self::assertNotNull($capturedJobId);
        self::assertStringContainsString($capturedJobId, $response->getTargetUrl());
        self::assertSame($capturedResponse, $response);

        // (1) status row was persisted under the right name + payload.
        $record = $this->statusService->read($capturedJobId);
        self::assertSame('admin.example.job', $record['name']);
        self::assertSame($payload, $record['payload']);
        self::assertSame('pending', $record['status']);
    }

    #[Test]
    public function dispatchWithProgressOmitsReturnParamWhenEmpty(): void
    {
        $request = $this->makeRequest(null);

        // No `return` key when caller passes empty string — keeps the
        // generated URL clean and lets the progress page fall back to its
        // built-in default.
        $this->urlGenerator->expects(self::once())
            ->method('generate')
            ->with(
                AsyncJobDispatcher::PROGRESS_ROUTE,
                self::callback(static fn (array $params): bool =>
                    array_keys($params) === ['id']
                    && is_string($params['id'])),
            )
            ->willReturn('/admin/jobs/x/progress');

        $this->runner->expects(self::once())
            ->method('dispatch')
            ->willReturnArgument(3);

        $facade = $this->makeFacade();
        $facade->dispatchWithProgress(
            request: $request,
            jobClass: StubAsyncJob::class,
            jobArgs: [],
            jobName: 'admin.example.no_return',
            payload: [],
        );
    }

    #[Test]
    public function dispatchWithProgressTemplateRendersHtmlBody(): void
    {
        $session = $this->createMock(SessionInterface::class);
        $request = $this->makeRequest($session);
        $rendered = '<div class="progress-card">…</div>';

        // Twig is asked for the default progress partial unless overridden,
        // with jobId + cancelUrl in context. Extra template context is
        // merged on top.
        $capturedJobId = null;
        $this->twig->expects(self::once())
            ->method('render')
            ->with(
                AsyncJobDispatcher::PROGRESS_TEMPLATE,
                self::callback(function (array $ctx) use (&$capturedJobId): bool {
                    $capturedJobId = $ctx['jobId'] ?? null;
                    return is_string($capturedJobId)
                        && ($ctx['cancelUrl'] ?? null) === '/admin/example/'
                        && ($ctx['extra'] ?? null) === 'value';
                }),
            )
            ->willReturn($rendered);

        $this->runner->expects(self::once())
            ->method('dispatch')
            ->with(
                StubAsyncJob::class,
                ['x' => 1],
                self::callback(static function (string $jobId) use (&$capturedJobId): bool {
                    return $jobId === $capturedJobId;
                }),
                self::callback(function (Response $r) use ($rendered): bool {
                    return !$r instanceof RedirectResponse
                        && $r->getContent() === $rendered;
                }),
                $session,
            )
            ->willReturnArgument(3);

        $facade = $this->makeFacade();
        $response = $facade->dispatchWithProgressTemplate(
            request: $request,
            jobClass: StubAsyncJob::class,
            jobArgs: ['x' => 1],
            jobName: 'admin.example.tmpl',
            cancelUrl: '/admin/example/',
            payload: ['_label' => 'Doing stuff'],
            templateContext: ['extra' => 'value'],
        );

        self::assertSame($rendered, $response->getContent());
        self::assertNotInstanceOf(RedirectResponse::class, $response);

        // Verify status row was persisted under the right name + payload.
        self::assertNotNull($capturedJobId);
        $record = $this->statusService->read($capturedJobId);
        self::assertSame('admin.example.tmpl', $record['name']);
        self::assertSame(['_label' => 'Doing stuff'], $record['payload']);
    }

    #[Test]
    public function constantsExposeTheCanonicalDefaults(): void
    {
        self::assertSame('admin_job_progress_page', AsyncJobDispatcher::PROGRESS_ROUTE);
        self::assertSame('_components/_async_job_progress.html.twig', AsyncJobDispatcher::PROGRESS_TEMPLATE);
    }

    private function makeFacade(): AsyncJobDispatcher
    {
        return new AsyncJobDispatcher(
            $this->statusService,
            $this->dispatcher,
            $this->urlGenerator,
            $this->twig,
        );
    }

    private function makeRequest(?SessionInterface $session): Request
    {
        $request = new Request();
        if ($session !== null) {
            $request->setSession($session);
        }
        return $request;
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->rrmdir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}

/**
 * Stand-in job class — only used to satisfy the
 * `class-string<AsyncJobInterface>` type-hint. Never invoked by the tests
 * (the mocked runner short-circuits before resolution).
 */
final class StubAsyncJob implements AsyncJobInterface
{
    public function run(JobContext $ctx): void
    {
        // no-op
    }
}
