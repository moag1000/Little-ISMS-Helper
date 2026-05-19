<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Job\AsyncJobInterface;
use App\Service\Job\InRequestJobRunner;
use App\Service\Job\JobDispatcher;
use App\Service\Job\MessengerJobRunner;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * @internal
 *
 * Covers the routing logic in {@see JobDispatcher} — which runner gets called
 * for which strategy, plus argument validation. Does NOT exercise the
 * underlying runners (they have their own integration coverage via the
 * controller WebTestCase suites).
 */
#[AllowMockObjectsWithoutExpectations]
final class JobDispatcherTest extends TestCase
{
    private MockObject $inRequest;
    private MockObject $messenger;

    protected function setUp(): void
    {
        $this->inRequest = $this->createMock(InRequestJobRunner::class);
        $this->messenger = $this->createMock(MessengerJobRunner::class);
    }

    #[Test]
    public function defaultStrategyIsInRequest(): void
    {
        $dispatcher = new JobDispatcher($this->inRequest, $this->messenger);

        self::assertSame('in_request', $dispatcher->getStrategy());
    }

    #[Test]
    public function inRequestStrategyDelegatesToInRequestRunner(): void
    {
        $response = new Response('ok');
        $session = $this->createMock(SessionInterface::class);

        $this->inRequest->expects(self::once())
            ->method('dispatch')
            ->with(StubJob::class, ['x' => 1], 'job-uuid', $response, $session)
            ->willReturn($response);

        $this->messenger->expects(self::never())
            ->method('dispatch');

        $dispatcher = new JobDispatcher($this->inRequest, $this->messenger, 'in_request');
        $result = $dispatcher->dispatch(StubJob::class, ['x' => 1], 'job-uuid', $response, $session);

        self::assertSame($response, $result);
    }

    #[Test]
    public function messengerStrategyDelegatesToMessengerRunner(): void
    {
        $response = new Response('ok');

        $this->messenger->expects(self::once())
            ->method('dispatch')
            ->with(StubJob::class, [], 'job-uuid', $response, null)
            ->willReturn($response);

        $this->inRequest->expects(self::never())
            ->method('dispatch');

        $dispatcher = new JobDispatcher($this->inRequest, $this->messenger, 'messenger');
        $result = $dispatcher->dispatch(StubJob::class, [], 'job-uuid', $response);

        self::assertSame($response, $result);
        self::assertSame('messenger', $dispatcher->getStrategy());
    }

    #[Test]
    public function invalidStrategyThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Invalid app\.async_job\.runner "bogus"/');

        new JobDispatcher($this->inRequest, $this->messenger, 'bogus');
    }
}

/**
 * Stand-in job class used only to satisfy the `class-string<AsyncJobInterface>`
 * type-hint in JobDispatcher::dispatch(). Never executed by the tests above
 * (the mocked runners short-circuit before any resolution would happen).
 */
final class StubJob implements AsyncJobInterface
{
    public function run(\App\Job\JobContext $ctx): void
    {
        // no-op
    }
}
