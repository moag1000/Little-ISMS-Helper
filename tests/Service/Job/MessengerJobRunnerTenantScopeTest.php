<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Entity\User;
use App\Message\Job\ExecuteJobMessage;
use App\Repository\UserRepository;
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
     * #1059 review: `allTenants` in the args is honoured only when the job's
     * recorded owner is an instance operator — a flag in the args of a
     * tenant admin's job is ignored (it keeps its tenant scope).
     */
    #[Test]
    public function allTenantsFlagOfANonOperatorOwnerIsIgnored(): void
    {
        $message = $this->dispatch([JobTenantScope::ARG_ALL_TENANTS => true], defaultTenantId: 7, ownerRoles: ['ROLE_ADMIN']);

        self::assertSame(7, $message->tenantId);
        self::assertFalse($message->unscoped);
    }

    #[Test]
    public function allTenantsFlagWithoutRecordedOwnerFailsClosed(): void
    {
        $message = $this->dispatch([JobTenantScope::ARG_ALL_TENANTS => true], defaultTenantId: null, ownerRoles: null);

        self::assertNull($message->tenantId);
        self::assertFalse($message->unscoped, 'no owner, no instance operator: no-organisation scope');
    }

    /**
     * @param array<string, mixed> $args
     * @param list<string>|null    $ownerRoles roles of the job's recorded owner (null = no owner)
     */
    private function dispatch(
        array $args,
        ?int $defaultTenantId,
        bool $superAdmin = false,
        ?array $ownerRoles = ['ROLE_SUPER_ADMIN'],
    ): ExecuteJobMessage {
        self::bootKernel();
        $owner = null;
        $users = $this->createStub(UserRepository::class);
        if ($ownerRoles !== null) {
            $owner = new User();
            (new \ReflectionProperty(User::class, 'id'))->setValue($owner, 42);
            $owner->setRoles($ownerRoles);
            $users->method('find')->willReturn($owner);
        }
        // The job record names its owner (the user who dispatched it).
        $ownerSecurity = $this->createStub(Security::class);
        $ownerSecurity->method('getUser')->willReturn($owner);
        $jobStatusService = new JobStatusService(self::getContainer()->get('kernel'), $ownerSecurity);
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
            (new MessengerJobRunner($bus, $jobStatusService, $tenantContext, $security, $users))
                ->dispatch('App\\Job\\ExportAnalyticsJob', $args, $jobId, new Response());
        } finally {
            $jobStatusService->delete($jobId);
        }

        self::assertInstanceOf(ExecuteJobMessage::class, $captured);

        return $captured;
    }
}
