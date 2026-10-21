<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Entity\User;
use App\Message\Job\ExecuteJobMessage;
use App\Service\Job\JobStatusService;
use App\Service\Job\JobTenantScope;
use App\Service\Job\MessengerJobRunner;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The Messenger worker must run a job in the same tenant scope as the
 * in-request runner. A SUPER_ADMIN without an organisation dispatches
 * instance-wide exports (`allTenants: true`), but TenantContext hands such an
 * account a default organisation — forwarding that id would arm the worker's
 * tenant filter for one organisation and silently truncate the export.
 */
final class MessengerJobRunnerTenantScopeTest extends KernelTestCase
{
    #[Test]
    public function instanceWideJobIsQueuedUnscoped(): void
    {
        $message = $this->dispatch([JobTenantScope::ARG_ALL_TENANTS => true], defaultTenantId: 7);

        self::assertNull($message->tenantId);
        self::assertTrue($message->unscoped);
    }

    /**
     * #1059: an account without an organisation that is no instance operator
     * gets the NO_ORGANISATION request filter in the in-request runner. The
     * queued message must carry the same fail-closed scope — never unscoped.
     */
    #[Test]
    public function accountWithoutOrganisationIsQueuedForTheNoOrganisationScope(): void
    {
        $message = $this->dispatch([], defaultTenantId: null, superAdmin: false);

        self::assertNull($message->tenantId);
        self::assertFalse($message->unscoped);
    }

    #[Test]
    public function instanceOperatorWithoutTenantContextIsQueuedUnscoped(): void
    {
        $message = $this->dispatch([], defaultTenantId: null, superAdmin: true);

        self::assertNull($message->tenantId);
        self::assertTrue($message->unscoped);
    }

    #[Test]
    public function tenantScopedJobKeepsTheDispatchingTenant(): void
    {
        $message = $this->dispatch([JobTenantScope::ARG_TENANT_ID => 7], defaultTenantId: 7);

        self::assertSame(7, $message->tenantId);
        self::assertFalse($message->unscoped);
    }

    #[Test]
    public function allTenantsFlagDoesNotOverrideAnExplicitTenant(): void
    {
        $message = $this->dispatch(
            [JobTenantScope::ARG_TENANT_ID => 7, JobTenantScope::ARG_ALL_TENANTS => true],
            defaultTenantId: 7,
        );

        self::assertSame(7, $message->tenantId);
    }

    #[Test]
    public function jobWithoutScopeArgsKeepsTheRequestTenant(): void
    {
        $message = $this->dispatch([], defaultTenantId: 7);

        self::assertSame(7, $message->tenantId);
    }

    /**
     * @param array<string, mixed> $args
     */
    private function dispatch(array $args, ?int $defaultTenantId, bool $superAdmin = false): ExecuteJobMessage
    {
        self::bootKernel();
        $jobStatusService = static::getContainer()->get(JobStatusService::class);
        $jobId = $jobStatusService->create('test.messenger_scope', []);

        $tenantContext = $this->createStub(TenantContext::class);
        $tenantContext->method('getCurrentTenantId')->willReturn($defaultTenantId);

        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(new User());
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => $superAdmin && $attribute === 'ROLE_SUPER_ADMIN',
        );

        $captured = null;
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$captured): Envelope {
            $captured = $message;

            return new Envelope($message);
        });

        try {
            (new MessengerJobRunner($bus, $jobStatusService, $tenantContext, $security))
                ->dispatch('App\\Job\\ExportAnalyticsJob', $args, $jobId, new Response());
        } finally {
            $jobStatusService->delete($jobId);
        }

        self::assertInstanceOf(ExecuteJobMessage::class, $captured);

        return $captured;
    }
}
