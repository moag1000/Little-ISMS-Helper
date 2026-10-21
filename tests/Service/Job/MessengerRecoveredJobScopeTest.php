<?php

declare(strict_types=1);

namespace App\Tests\Service\Job;

use App\Doctrine\EntityManagerRecovery;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\Job\AsyncJobInterface;
use App\Job\JobContext;
use App\Message\Job\ExecuteJobMessage;
use App\MessageHandler\Job\ExecuteJobHandler;
use App\Service\Job\JobStatusService;
use App\Service\TenantContext;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * #1059 re-review 2, R-1 at worker level: a Messenger job that suspends the
 * tenant filter (operator job) recovers from a flush the database rejects.
 * The job still finishes (its restore('tenant_filter') works) and the NEXT
 * job in the same worker is tenant-scoped again — before the fix, the
 * recovered filter was left disabled and every later job read across all
 * organisations until the worker restarted.
 */
final class MessengerRecoveredJobScopeTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private Connection $conn;
    /** @var list<int> */
    private array $tenantIds = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->conn = $this->em->getConnection();
    }

    protected function tearDown(): void
    {
        foreach ($this->tenantIds as $tenantId) {
            $this->conn->executeStatement('DELETE FROM processing_activity WHERE tenant_id = ?', [$tenantId]);
            $this->conn->executeStatement('DELETE FROM tenant WHERE id = ?', [$tenantId]);
        }
        $this->tenantIds = [];

        parent::tearDown();
    }

    #[Test]
    public function aJobAfterARecoveredOperatorJobIsTenantScoped(): void
    {
        $container = self::getContainer();
        $own = $this->tenant('own');
        $foreign = $this->tenant('foreign');
        $ownActivity = $this->activity($own, 'contract');
        $foreignActivity = $this->activity($foreign, 'contract');
        $ownId = (int) $own->getId();
        $em = $this->em;
        $recovery = $container->get(EntityManagerRecovery::class);

        // Job 1: operator job pattern (AssignOrphansJob) — suspend, a flush the
        // database rejects, recover, restore in finally.
        $operatorJob = new class ($em, $recovery, $ownId) implements AsyncJobInterface {
            public function __construct(
                private readonly EntityManagerInterface $em,
                private readonly EntityManagerRecovery $recovery,
                private readonly int $tenantId,
            ) {
            }

            public function run(JobContext $ctx): void
            {
                $filters = $this->em->getFilters();
                $filters->suspend('tenant_filter');
                try {
                    $broken = new ProcessingActivity();
                    $broken->setName('broken');
                    $broken->setPurposes(['x']);
                    $broken->setDataSubjectCategories(['customers']);
                    $broken->setPersonalDataCategories(['contact_data']);
                    $broken->setLegalBasis(str_repeat('x', 80)); // VARCHAR(50)
                    $broken->setTenant($this->em->getReference(Tenant::class, $this->tenantId));
                    $this->em->persist($broken);
                    try {
                        $this->em->flush();
                    } catch (\Doctrine\DBAL\Exception) {
                        $this->recovery->recover();
                    }
                } finally {
                    $this->em->getFilters()->restore('tenant_filter');
                }
            }
        };

        // Job 2: an ordinary tenant job that reads.
        $readingJob = new class ($em, [$ownActivity, $foreignActivity]) implements AsyncJobInterface {
            /** @var list<int|null> */
            public array $seen = [];

            /** @param list<int> $ids */
            public function __construct(private readonly EntityManagerInterface $em, private readonly array $ids)
            {
            }

            public function run(JobContext $ctx): void
            {
                $this->seen = array_map(
                    static fn (ProcessingActivity $pa): ?int => $pa->getId(),
                    $this->em->getRepository(ProcessingActivity::class)->findBy(['id' => $this->ids]),
                );
            }
        };

        $jobs = $container->get(JobStatusService::class);
        $first = $jobs->create('test.recovered_operator_job', []);
        $second = $jobs->create('test.reading_job', []);
        try {
            $this->handler($operatorJob)(new ExecuteJobMessage($operatorJob::class, [], $first, $ownId));
            self::assertSame('succeeded', $jobs->read($first)['status'] ?? null, 'the operator job finishes — its restore() works');

            $this->em->clear(); // the worker's kernel.reset between messages

            $this->handler($readingJob)(new ExecuteJobMessage($readingJob::class, [], $second, $ownId));
            self::assertSame('succeeded', $jobs->read($second)['status'] ?? null);
            self::assertSame([$ownActivity], $readingJob->seen, 'the next job is tenant-scoped');
        } finally {
            $jobs->delete($first);
            $jobs->delete($second);
        }
    }

    private function handler(AsyncJobInterface $job): ExecuteJobHandler
    {
        return new ExecuteJobHandler(
            self::getContainer()->get(JobStatusService::class),
            self::getContainer()->get(TenantContext::class),
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
    }

    private function tenant(string $label): Tenant
    {
        $uid = uniqid('', true);
        $tenant = new Tenant();
        $tenant->setName('Worker recovery ' . $label . ' ' . $uid);
        $tenant->setCode('wrec_' . $label . '_' . $uid);
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenantIds[] = (int) $tenant->getId();

        return $tenant;
    }

    private function activity(Tenant $tenant, string $legalBasis): int
    {
        $pa = new ProcessingActivity();
        $pa->setName('Worker recovery probe ' . uniqid());
        $pa->setPurposes(['x']);
        $pa->setDataSubjectCategories(['customers']);
        $pa->setPersonalDataCategories(['contact_data']);
        $pa->setLegalBasis($legalBasis);
        $pa->setTenant($tenant);
        $this->em->persist($pa);
        $this->em->flush();

        return (int) $pa->getId();
    }
}
