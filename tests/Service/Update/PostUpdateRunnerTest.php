<?php

declare(strict_types=1);

namespace App\Tests\Service\Update;

use App\Repository\SystemSettingsRepository;
use App\Service\AuditLogger;
use App\Service\SchemaMaintenanceService;
use App\Service\SchemaSnapshotService;
use App\Service\TenantContext;
use App\Service\Update\PostUpdateRunner;
use App\Service\Update\PostUpdateState;
use App\Service\Upload\ProtectedUploads;
use App\Workflow\Mirror\RegulatoryWorkflowMirror;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The automatic post-update run: when it is due, that a failure stops and waits for the operator,
 * that the switch is respected and that every run is audit-logged. The steps themselves are
 * the existing Quick-Fix services (stubbed here).
 */
#[AllowMockObjectsWithoutExpectations]
final class PostUpdateRunnerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/post_update_' . uniqid();
        mkdir($this->dir . '/var', 0775, true);
        mkdir($this->dir . '/migrations');
        mkdir($this->dir . '/config/workflows/regulatory', 0775, true);
        file_put_contents($this->dir . '/composer.json', '{"version":"4.0.0"}');
        file_put_contents($this->dir . '/migrations/Version20261101000000.php', '<?php');
        file_put_contents($this->dir . '/var/setup_complete.lock', '1');
    }

    protected function tearDown(): void
    {
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($it as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->dir);
    }

    /** @param list<string> $failSteps */
    private function runner(bool $enabled = true, array $failSteps = [], ?AuditLogger $audit = null, array &$called = []): PostUpdateRunner
    {
        $settings = $this->createStub(SystemSettingsRepository::class);
        $settings->method('getSetting')->willReturn($enabled);
        $tenant = $this->createStub(TenantContext::class);
        $tenant->method('runWithoutOrganisation')->willReturnCallback(static fn (callable $fn): mixed => $fn());
        $make = static fn (string $class): object => (new \ReflectionClass($class))->newInstanceWithoutConstructor();

        return new class (
            new PostUpdateState($this->dir),
            $make(SchemaMaintenanceService::class),
            $make(SchemaSnapshotService::class),
            $make(RegulatoryWorkflowMirror::class),
            $make(ProtectedUploads::class),
            $audit ?? $this->createStub(AuditLogger::class),
            $tenant,
            $settings,
            new NullLogger(),
            $this->dir,
            $failSteps,
            $called,
        ) extends PostUpdateRunner {
            public function __construct(
                PostUpdateState $state,
                SchemaMaintenanceService $m,
                SchemaSnapshotService $s,
                RegulatoryWorkflowMirror $w,
                ProtectedUploads $u,
                AuditLogger $a,
                TenantContext $t,
                SystemSettingsRepository $r,
                \Psr\Log\LoggerInterface $l,
                string $dir,
                private readonly array $failSteps,
                private array &$called,
            ) {
                parent::__construct($state, $m, $s, $w, $u, $a, $t, $r, $l, $dir);
            }

            protected function migrations(): array
            {
                return $this->fake('migrations');
            }

            protected function workflowDefinitions(): array
            {
                return $this->fake('workflows');
            }

            protected function protectUploads(): array
            {
                return $this->fake('uploads');
            }

            /** @return array{0: string, 1: string} */
            private function fake(string $name): array
            {
                $this->called[] = $name;
                if (in_array($name, $this->failSteps, true)) {
                    throw new \RuntimeException($name . ' broke');
                }

                return ['ok', $name . ' done'];
            }
        };
    }

    #[Test]
    public function firstRunAfterDeployRunsAllStepsAndRemembersTheCode(): void
    {
        $called = [];
        $runner = $this->runner(called: $called);

        $this->assertTrue($runner->isDue());
        $this->assertSame(['ran' => true, 'status' => 'ok'], $runner->run('first_request'));
        $this->assertSame(['migrations', 'workflows', 'uploads'], $called);
        $this->assertFalse($runner->isDue(), 'nothing new: the next request does not run again');
        $this->assertSame(['ran' => false, 'status' => 'not_due'], $runner->run('scheduler'));
    }

    #[Test]
    public function newMigrationOrVersionMakesItDueAgain(): void
    {
        $runner = $this->runner();
        $runner->run('scheduler');

        file_put_contents($this->dir . '/migrations/Version20261102000000.php', '<?php');
        touch($this->dir . '/migrations', time() + 5);

        $this->assertTrue($runner->isDue());
    }

    #[Test]
    public function aTouchedFileWithoutNewCodeIsNotAnUpdate(): void
    {
        $runner = $this->runner();
        $runner->run('scheduler');

        touch($this->dir . '/composer.json', time() + 5);

        $this->assertFalse($runner->isDue());
    }

    #[Test]
    public function aFailedMigrationStopsTheRunAndWaitsForTheOperator(): void
    {
        $called = [];
        $runner = $this->runner(failSteps: ['migrations'], called: $called);

        $this->assertSame(['ran' => true, 'status' => 'failed'], $runner->run('scheduler'));
        $this->assertSame(['migrations'], $called, 'later steps need the schema, they do not run');

        $state = (new PostUpdateState($this->dir))->read();
        $this->assertSame('failed', $state['status']);
        $this->assertSame('migrations broke', $state['error']);

        $this->assertSame(['ran' => false, 'status' => 'not_due'], $runner->run('first_request'));
        $this->assertTrue($runner->run('quick_fix', true)['ran'], 'the operator button runs again');
    }

    #[Test]
    public function switchedOffMeansNeverDueButTheOperatorButtonStillRuns(): void
    {
        $called = [];
        $runner = $this->runner(enabled: false, called: $called);

        $this->assertFalse($runner->isDue());
        $this->assertSame(['ran' => false, 'status' => 'not_due'], $runner->run('scheduler'));
        $this->assertSame([], $called);

        $this->assertTrue($runner->run('quick_fix', true)['ran']);
    }

    #[Test]
    public function aFreshInstanceWithoutFinishedSetupIsLeftToTheWizard(): void
    {
        unlink($this->dir . '/var/setup_complete.lock');

        $this->assertFalse($this->runner()->isDue());
    }

    #[Test]
    public function everyRunIsAuditLogged(): void
    {
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects($this->once())->method('logCustom')
            ->with('system.auto_update.completed', 'System');

        $this->runner(audit: $audit)->run('scheduler');
    }
}
