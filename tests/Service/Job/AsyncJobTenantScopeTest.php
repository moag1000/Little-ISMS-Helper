<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Entity\Tenant;
use App\Job\AsyncJobInterface;
use App\Job\JobContext;
use App\Message\Job\ExecuteJobMessage;
use App\MessageHandler\Job\ExecuteJobHandler;
use App\Service\Job\JobStatusService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\Filter\SQLFilter;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Guards tenant isolation on the Messenger worker path.
 *
 * TenantFilterSubscriber arms the Doctrine tenant_filter only on
 * kernel.request. A `messenger:consume async` worker has no request, so
 * without ExecuteJobHandler restoring the tenant the filter stays unarmed
 * and jobs that call findAll() — ExportUsersJob, ExportAnalyticsJob,
 * ExportDataJob — read across ALL tenants.
 */
final class AsyncJobTenantScopeTest extends KernelTestCase
{
    #[Test]
    public function handlerArmsTheTenantFilterBeforeRunningTheJob(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $tenantContext = $container->get(TenantContext::class);
        $jobStatusService = $container->get(JobStatusService::class);

        $job = new class implements AsyncJobInterface {
            public ?string $seenTenantParameter = null;
            public ?EntityManagerInterface $em = null;

            public function run(JobContext $ctx): void
            {
                // Observe the filter state at the moment the job body runs.
                $this->seenTenantParameter = AsyncJobTenantScopeTest::rawTenantParameter($this->em);
            }
        };
        $job->em = $em;

        $handler = new ExecuteJobHandler(
            $jobStatusService,
            $tenantContext,
            new class ($job) implements ContainerInterface {
                public function __construct(private readonly AsyncJobInterface $job)
                {
                }

                public function get(string $id): AsyncJobInterface
                {
                    return $this->job;
                }

                public function has(string $id): bool
                {
                    return true;
                }
            },
        );

        $tenant = new Tenant();
        $tenant->setName('Async Job Scope Probe');
        $tenant->setCode('async-job-scope-probe');
        $em->persist($tenant);
        $em->flush();
        $tenantId = (int) $tenant->getId();

        $jobId = $jobStatusService->create('test.tenant_scope', []);

        try {
            $handler(new ExecuteJobMessage(
                jobClass: $job::class,
                args: [],
                jobId: $jobId,
                tenantId: $tenantId,
            ));

            self::assertSame(
                (string) $tenantId,
                $job->seenTenantParameter,
                'the job body must run with the dispatching tenant armed on the Doctrine filter',
            );
        } finally {
            $jobStatusService->delete($jobId);
            $em->remove($em->getReference(Tenant::class, $tenantId));
            $em->flush();
        }
    }

    #[Test]
    public function unresolvableTenantFailsTheJobInsteadOfRunningItUnscoped(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $jobStatusService = $container->get(JobStatusService::class);

        $job = new class implements AsyncJobInterface {
            public bool $ran = false;

            public function run(JobContext $ctx): void
            {
                $this->ran = true;
            }
        };

        $handler = new ExecuteJobHandler(
            $jobStatusService,
            $container->get(TenantContext::class),
            new class ($job) implements ContainerInterface {
                public function __construct(private readonly AsyncJobInterface $job)
                {
                }

                public function get(string $id): AsyncJobInterface
                {
                    return $this->job;
                }

                public function has(string $id): bool
                {
                    return true;
                }
            },
        );

        $jobId = $jobStatusService->create('test.tenant_scope_missing', []);

        try {
            $handler(new ExecuteJobMessage(
                jobClass: $job::class,
                args: [],
                jobId: $jobId,
                tenantId: 999999999,
            ));

            self::assertFalse($job->ran, 'the job body must not run when its tenant cannot be resolved');
            self::assertSame('failed', $jobStatusService->read($jobId)['status'] ?? null);
        } finally {
            $jobStatusService->delete($jobId);
        }
    }

    #[Test]
    public function messagesQueuedBeforeTheTenantFieldExistedStillDeserialise(): void
    {
        // Old payloads carry no tenantId property at all. ExecuteJobHandler
        // reads it through `??`, which uses isset() semantics and therefore
        // tolerates the uninitialised typed property instead of throwing.
        $legacy = unserialize(sprintf(
            'O:%d:"%s":3:{s:8:"jobClass";s:3:"Foo";s:4:"args";a:0:{}s:5:"jobId";s:3:"abc";}',
            strlen(ExecuteJobMessage::class),
            ExecuteJobMessage::class,
        ));

        self::assertInstanceOf(ExecuteJobMessage::class, $legacy);
        self::assertNull($legacy->tenantId ?? null, 'legacy payloads must degrade to unscoped, not fatal');
    }

    /**
     * SQLFilter::getParameter() quotes through the DB connection, which would
     * make this test require a live database. Read the stored value instead.
     */
    public static function rawTenantParameter(EntityManagerInterface $em): ?string
    {
        $filters = $em->getFilters();

        if (!$filters->isEnabled('tenant_filter')) {
            return null;
        }

        $property = new \ReflectionProperty(SQLFilter::class, 'parameters');
        $stored = $property->getValue($filters->getFilter('tenant_filter'))['tenant_id'] ?? null;

        // ORM 3 stores Query\Filter\Parameter objects; ORM 2 stored raw arrays.
        $value = is_object($stored) ? $stored->value : ($stored['value'] ?? $stored);

        return $value === null ? null : (string) $value;
    }
}
