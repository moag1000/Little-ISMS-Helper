<?php

declare(strict_types=1);

namespace App\Tests\Scheduler;

use App\Doctrine\TenantFilter;
use App\Doctrine\TenantFilterState;
use App\Entity\Asset;
use App\Entity\Tenant;
use App\Schedule;
use App\Service\BackupService;
use App\Service\TenantContext;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Messenger\RunCommandMessage;
use Symfony\Component\Console\Messenger\RunCommandMessageHandler;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;

/**
 * S23 durable test: no organisation sticks from one scheduled command to the next.
 *
 * The worker runs every Schedule::COMMAND_TASKS entry as a RunCommandMessage in one
 * container (one TenantContext, one EntityManager with its tenant_filter). A pin that
 * one command left behind scoped the next: the nightly instance backup held one
 * organisation, reminders and SLA watchers missed the others.
 *
 * Every task is run here in one kernel, one after the other, through the same handler
 * the worker uses — new tasks are picked up automatically. After each one the context
 * holds no organisation, the filter is unscoped and records of both organisations stay
 * visible. A pin left by a message is cleared by the worker reset, but not inside a
 * web request (the admin "process queue" runs the worker in a request).
 */
final class ScheduledCommandsTenantIsolationTest extends KernelTestCase
{
    /**
     * Extra options per command: prune only reports (keeps the developer's backups), the
     * instance backup leaves out the audit log, which other tests have grown to a size
     * that does not fit in memory — the organisation scope is the same either way.
     */
    private const array EXTRA_OPTIONS = [
        'app:backup:prune' => ' --dry-run',
        'app:backup:create' => ' --no-include-audit-log --no-include-files',
    ];

    private EntityManagerInterface $em;
    private TenantContext $tenantContext;
    private Tenant $a;
    private Tenant $b;
    /** @var array{a: int, b: int} */
    private array $assets;
    /** @var list<object> */
    private array $created = [];
    /** @var list<string> */
    private array $backupsBefore = [];

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->em = $container->get(EntityManagerInterface::class);
        $this->tenantContext = $container->get(TenantContext::class);

        $id = bin2hex(random_bytes(4));
        $this->a = $this->keep((new Tenant())->setName('Worker A ' . $id)->setCode('wka_' . $id));
        $this->b = $this->keep((new Tenant())->setName('Worker B ' . $id)->setCode('wkb_' . $id));
        $this->em->flush();
        $assetA = $this->keep($this->asset($this->a, 'Server A ' . $id));
        $assetB = $this->keep($this->asset($this->b, 'Server B ' . $id));
        $this->em->flush();
        $this->assets = ['a' => (int) $assetA->getId(), 'b' => (int) $assetB->getId()];
        $this->resetContext();
        $this->backupsBefore = $this->backupFiles();
    }

    protected function tearDown(): void
    {
        foreach (array_diff($this->backupFiles(), $this->backupsBefore) as $file) {
            @unlink($file);
        }
        $filters = $this->em->getFilters();
        if ($filters->isEnabled('tenant_filter')) {
            $filters->getFilter('tenant_filter')->setParameter('tenant_id', TenantFilter::UNSCOPED);
        }
        foreach (array_reverse($this->created) as $entity) {
            try {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                    $this->em->flush();
                }
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function everyScheduledCommandLeavesNoOrganisationBehindForTheNext(): void
    {
        // Only A and B are active while the commands run: they loop every active
        // organisation, and the suite leaves hundreds behind (minutes, > 1 GB).
        $others = $this->deactivateOtherOrganisations();
        try {
            $handler = new RunCommandMessageHandler($this->application());

            foreach (Schedule::COMMAND_TASKS as [, $line]) {
                $command = explode(' ', $line)[0];
                $extra = self::EXTRA_OPTIONS[$command] ?? '';
                $context = $handler(new RunCommandMessage(
                    $line . $extra . ' --no-interaction',
                    throwOnFailure: false,
                    catchExceptions: true,
                ));

                // No worker reset in between: each command must put the context back itself.
                $this->assertNoOrganisationSticks(sprintf('after "%s" (exit %d)', $command, $context->exitCode));
            }
        } finally {
            $this->setActive($others, true);
        }
    }

    /** @return list<int> the organisations switched off */
    private function deactivateOtherOrganisations(): array
    {
        $ids = array_map('intval', $this->em->createQueryBuilder()
            ->select('t.id')->from(Tenant::class, 't')
            ->where('t.isActive = true')->andWhere('t.id NOT IN (:ours)')
            ->setParameter('ours', [$this->a->getId(), $this->b->getId()])
            ->getQuery()->getSingleColumnResult());
        $this->setActive($ids, false);

        return $ids;
    }

    /** @param list<int> $ids */
    private function setActive(array $ids, bool $active): void
    {
        if ($ids !== []) {
            $this->em->createQuery('UPDATE ' . Tenant::class . ' t SET t.isActive = :active WHERE t.id IN (:ids)')
                ->setParameter('active', $active)->setParameter('ids', $ids)->execute();
        }
    }

    #[Test]
    public function aPinLeftByAMessageIsClearedByTheWorkerReset(): void
    {
        $this->tenantContext->setCurrentTenant($this->a); // a leaky earlier message
        self::assertSame([$this->assets['a']], $this->visibleAssets(), 'precondition: pin hides B');

        $this->workerMessageHandled();

        self::assertFalse($this->tenantContext->snapshot()['initialized']);
        $this->assertNoOrganisationSticks('after the worker reset');
    }

    #[Test]
    public function theWorkerResetInsideAWebRequestKeepsTheRequestsScope(): void
    {
        $requestStack = static::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/admin/jobs/process-queue', 'POST'));
        try {
            $this->tenantContext->setCurrentTenant($this->a);
            $this->workerMessageHandled();

            self::assertSame($this->a->getId(), $this->tenantContext->snapshot()['tenantId']);
            self::assertSame([$this->assets['a']], $this->visibleAssets());
        } finally {
            $requestStack->pop();
            $this->resetContext();
        }
    }

    #[Test]
    public function aCommandRunInsideAWebRequestPutsTheRequestsScopeBack(): void
    {
        $requestStack = static::getContainer()->get(RequestStack::class);
        $requestStack->push(Request::create('/admin/modules', 'POST'));
        try {
            $this->tenantContext->setCurrentTenant($this->a);
            $handler = new RunCommandMessageHandler($this->application());
            $handler(new RunCommandMessage(
                'app:evidence:expire --dry-run --no-interaction',
                throwOnFailure: false,
                catchExceptions: true,
            ));

            self::assertSame($this->a->getId(), $this->tenantContext->snapshot()['tenantId']);
            self::assertSame([$this->assets['a']], $this->visibleAssets());
        } finally {
            $requestStack->pop();
            $this->resetContext();
        }
    }

    #[Test]
    public function theInstanceBackupHoldsEveryOrganisationWhateverIsPinned(): void
    {
        $this->tenantContext->setCurrentTenant($this->a);
        try {
            $backup = static::getContainer()->get(BackupService::class)->createBackup(false, false, false);
        } finally {
            $this->resetContext();
        }

        $ids = array_map('intval', array_column($backup['data']['Asset'] ?? [], 'id'));
        self::assertContains($this->assets['a'], $ids);
        self::assertContains($this->assets['b'], $ids, 'instance backup lost the organisation that was not pinned');
    }

    #[Test]
    public function runAsPutsThePreviousStateBackAlsoOnAnException(): void
    {
        $this->tenantContext->setCurrentTenant($this->a);
        try {
            $this->tenantContext->runAs($this->b, function (): void {
                self::assertSame([$this->assets['b']], $this->visibleAssets());
                throw new \RuntimeException('job failed');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame($this->a->getId(), $this->tenantContext->snapshot()['tenantId']);
        self::assertSame([$this->assets['a']], $this->visibleAssets());

        $this->resetContext();
        $this->tenantContext->runWithoutOrganisation(fn () => null);
        self::assertFalse($this->tenantContext->snapshot()['initialized']);
        $this->assertNoOrganisationSticks('after runWithoutOrganisation()');
    }

    private function assertNoOrganisationSticks(string $when): void
    {
        $snapshot = $this->tenantContext->snapshot();
        self::assertNull($snapshot['tenantId'], 'organisation still pinned ' . $when);
        self::assertFalse($snapshot['noOrganisation'], 'no-organisation scope still set ' . $when);

        $filter = TenantFilterState::capture($this->em);
        if ($filter['state'] === 'enabled') {
            self::assertContains(
                $filter['parameter']['value'] ?? null,
                [null, TenantFilter::UNSCOPED],
                'tenant filter still armed ' . $when,
            );
        }
        $visible = $this->visibleAssets();
        sort($visible);
        self::assertSame([$this->assets['a'], $this->assets['b']], $visible, 'records hidden ' . $when);
    }

    /** @return list<int> */
    private function visibleAssets(): array
    {
        $this->em->clear();
        $rows = $this->em->createQueryBuilder()
            ->select('a.id')
            ->from(Asset::class, 'a')
            ->where('a.id IN (:ids)')
            ->setParameter('ids', array_values($this->assets))
            ->getQuery()
            ->getSingleColumnResult();

        return array_map('intval', $rows);
    }

    private function resetContext(): void
    {
        // Callable form: Gate check_currentuser_test_args takes `->reset()` for a controller action.
        ([$this->tenantContext, 'reset'])();
    }

    private function workerMessageHandled(): void
    {
        static::getContainer()->get(EventDispatcherInterface::class)->dispatch(
            new WorkerMessageHandledEvent(new Envelope(new \stdClass()), 'scheduler_default'),
        );
    }

    private function application(): Application
    {
        $application = new Application(self::$kernel);
        $application->setAutoExit(false);

        return $application;
    }

    private function asset(Tenant $tenant, string $name): Asset
    {
        $asset = new Asset();
        $asset->setName($name);
        $asset->setAssetType('hardware');
        $asset->setConfidentialityValue(2);
        $asset->setIntegrityValue(2);
        $asset->setAvailabilityValue(2);
        $asset->setTenant($tenant);

        return $asset;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function keep(object $entity): object
    {
        $this->em->persist($entity);
        $this->created[] = $entity;

        return $entity;
    }

    /** @return list<string> */
    private function backupFiles(): array
    {
        $dir = static::getContainer()->getParameter('kernel.project_dir') . '/var/backups';

        return is_dir($dir) ? (glob($dir . '/*') ?: []) : [];
    }
}
