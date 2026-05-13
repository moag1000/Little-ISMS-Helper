<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SchemaExceptionSubscriber;
use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\InvalidFieldNameException;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\Mapping\MappingException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

#[AllowMockObjectsWithoutExpectations]
class SchemaExceptionSubscriberTest extends TestCase
{
    private QuickFixGuard&MockObject $guard;
    private UrlGeneratorInterface&MockObject $urlGenerator;
    private HttpKernelInterface&MockObject $kernel;
    private SchemaMaintenanceService&MockObject $maintenance;
    private SchemaExceptionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(QuickFixGuard::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);
        $this->maintenance = $this->createMock(SchemaMaintenanceService::class);

        $this->urlGenerator->method('generate')->willReturn('/quick-fix');
        // Default: pending migrations exist (positive case for redirect).
        $this->maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 1, 'names' => ['VersionXXX']],
            'schema_drift' => ['count' => 0, 'statements' => [], 'destructive' => []],
        ]);

        $this->subscriber = new SchemaExceptionSubscriber(
            $this->guard,
            $this->urlGenerator,
            $this->maintenance,
        );
    }

    #[Test]
    public function redirectsToQuickFixOnTableNotFound(): void
    {
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $exception = new TableNotFoundException($this->driverException('Table users does not exist'), null);
        $event = $this->makeEvent('/dashboard', $exception);

        $this->subscriber->onKernelException($event);

        $this->assertInstanceOf(RedirectResponse::class, $event->getResponse());
        $this->assertSame('/quick-fix', $event->getResponse()->getTargetUrl());
    }

    #[Test]
    public function redirectsOnOrmMappingException(): void
    {
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/dashboard', MappingException::invalidMapping('SomeEntity'));

        $this->subscriber->onKernelException($event);

        $this->assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    #[Test]
    public function ignoresUnrelatedExceptions(): void
    {
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/dashboard', new \LogicException('something else'));

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function noOpWhenFallbackUiDisabled(): void
    {
        $this->guard->method('fallbackUiEnabled')->willReturn(false);

        $event = $this->makeEvent('/dashboard', new TableNotFoundException($this->driverException('x'), null));

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function avoidsRecursionOnQuickFixRoute(): void
    {
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/quick-fix', new TableNotFoundException($this->driverException('x'), null));

        $this->subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function unwrapsPreviousExceptionChain(): void
    {
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $inner = new TableNotFoundException($this->driverException('table missing'), null);
        $outer = new \RuntimeException('Wrapped', 0, $inner);
        $event = $this->makeEvent('/dashboard', $outer);

        $this->subscriber->onKernelException($event);

        $this->assertInstanceOf(RedirectResponse::class, $event->getResponse());
    }

    #[Test]
    public function noRedirectWhenNoPendingMigrations(): void
    {
        // Override the default maintenance-status mock — simulate "schema is
        // up-to-date, no pending migrations". A TableNotFoundException now
        // means a typo or bug in custom SQL, not an out-of-date schema, so
        // the original exception should bubble up as a normal 500.
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => ['count' => 0, 'statements' => [], 'destructive' => []],
        ]);
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/some-page', new TableNotFoundException(
            $this->driverException('Table b_c_exercise does not exist'),
            null,
        ));

        $subscriber->onKernelException($event);

        $this->assertNull($event->getResponse());
    }

    #[Test]
    public function redirectsOnSchemaDriftWithoutPendingMigrations(): void
    {
        // Drift-only case: all migrations applied but entity metadata still
        // diverges from the live DB (e.g. column added via faulty migration).
        // Subscriber must redirect so the operator sees Quick-Fix instead of 500.
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => [
                'count' => 1,
                'statements' => ['ALTER TABLE users ADD sso_external_id VARCHAR(255) DEFAULT NULL'],
                'destructive' => [],
            ],
        ]);
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/dashboard', new TableNotFoundException(
            $this->driverException("Unknown column 't0.sso_external_id' in 'SELECT'"),
            null,
        ));

        $subscriber->onKernelException($event);

        $this->assertInstanceOf(RedirectResponse::class, $event->getResponse());
        $this->assertSame('/quick-fix', $event->getResponse()->getTargetUrl());
    }

    #[Test]
    public function autoFixRunsPendingMigrationsBeforeReconcile(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 1, 'names' => ['Version20260507235217']],
            'schema_drift' => [
                'count' => 0,
                'statements' => [],
                'destructive' => [],
            ],
        ]);
        $maintenance->expects($this->once())
            ->method('executePendingMigrations')
            ->with('auto-fix-on-error')
            ->willReturn(['success' => true, 'executed' => 1, 'error' => null]);
        // reconcileSchema may run additionally for any residual additive drift.
        $maintenance->method('reconcileSchema')
            ->willReturn(['success' => true, 'executed' => 0, 'error' => null, 'blocked' => null]);
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/dashboard', new TableNotFoundException(
            $this->driverException("Unknown column 't0.competencies' in 'SELECT'"),
            null,
        ));

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('_schema_autofixed=1', $response->getTargetUrl());
    }

    #[Test]
    public function autoFixesAdditiveDriftEvenOnQuickFixPath(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => [
                'count' => 1,
                'statements' => ['ALTER TABLE users ADD competencies JSON DEFAULT NULL'],
                'destructive' => [],
            ],
        ]);
        $maintenance->expects($this->once())
            ->method('reconcileSchema')
            ->with('auto-fix-on-error')
            ->willReturn(['success' => true, 'executed' => 1, 'error' => null, 'blocked' => null]);
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/quick-fix', new TableNotFoundException(
            $this->driverException("Unknown column 't0.competencies' in 'SELECT'"),
            null,
        ));

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('_schema_autofixed=1', $response->getTargetUrl());
        $this->assertStringContainsString('/quick-fix', $response->getTargetUrl());
    }

    #[Test]
    public function autoFixesAdditiveDriftAndRetriesRequest(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => [
                'count' => 1,
                'statements' => ['ALTER TABLE users ADD competencies JSON DEFAULT NULL'],
                'destructive' => [],
            ],
        ]);
        $maintenance->expects($this->once())
            ->method('reconcileSchema')
            ->with('auto-fix-on-error')
            ->willReturn(['success' => true, 'executed' => 1, 'error' => null, 'blocked' => null]);
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/people', new TableNotFoundException(
            $this->driverException("Unknown column 't0.competencies' in 'SELECT'"),
            null,
        ));

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertStringEndsWith('_schema_autofixed=1', $response->getTargetUrl());
        $this->assertStringContainsString('/people', $response->getTargetUrl());
    }

    #[Test]
    public function skipsAutoFixOnSecondAttemptViaRecursionFlag(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => [
                'count' => 1,
                'statements' => ['ALTER TABLE x'],
                'destructive' => [],
            ],
        ]);
        $maintenance->expects($this->never())->method('reconcileSchema');
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/people?_schema_autofixed=1', new TableNotFoundException(
            $this->driverException('Unknown column'),
            null,
        ));

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/quick-fix', $response->getTargetUrl());
    }

    #[Test]
    public function skipsAutoFixWhenDestructiveDriftPresent(): void
    {
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => [
                'count' => 2,
                'statements' => ['ALTER TABLE users ADD x', 'DROP TABLE legacy'],
                'destructive' => ['DROP TABLE legacy'],
            ],
        ]);
        $maintenance->expects($this->never())->method('reconcileSchema');
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);

        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/dashboard', new TableNotFoundException(
            $this->driverException('Unknown column'),
            null,
        ));

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertSame('/quick-fix', $response->getTargetUrl());
    }

    // -----------------------------------------------------------------------
    // Phantom-diff-state tests (Pitfall #6 — migration marked executed but
    // DDL never ran). Both pending and drift counters report 0, yet the live
    // DB is missing a column.
    // -----------------------------------------------------------------------

    #[Test]
    public function redirectsOnInvalidFieldNameExceptionWhenPendingAndDriftAreZero(): void
    {
        // Simulate the exact production scenario: migrations table says "all
        // done" (pending=0), SchemaTool sees no drift (drift=0), but the DB
        // is missing a column because the PREPARE/EXECUTE migration never
        // materialised the DDL.
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => ['count' => 0, 'statements' => [], 'destructive' => []],
        ]);
        // getEntityVsDbDrift() returns 1 additive statement found via entity
        // metadata — this unblocks the auto-fix path.
        $maintenance->method('getEntityVsDbDrift')
            ->willReturn(['ALTER TABLE user ADD in_app_notifications_enabled TINYINT(1) DEFAULT 0']);
        $maintenance->method('executePendingMigrations')
            ->willReturn(['success' => true, 'executed' => 0, 'error' => null]);
        $maintenance->method('reconcileSchema')
            ->willReturn(['success' => true, 'executed' => 1, 'error' => null, 'blocked' => null]);

        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $exception = new InvalidFieldNameException(
            $this->driverException("Unknown column 't0.in_app_notifications_enabled' in 'field list'"),
            null,
        );
        $event = $this->makeEvent('/de/admin/users', $exception);

        $subscriber->onKernelException($event);

        $response = $event->getResponse();
        $this->assertInstanceOf(RedirectResponse::class, $response, 'Must redirect even when pending=0 and drift=0');
    }

    #[Test]
    public function redirectsOnUnknownColumnDriverExceptionWhenPendingAndDriftAreZero(): void
    {
        // DriverException (not InvalidFieldNameException specifically) wrapping
        // "Unknown column" text — same phantom-diff scenario via a plain
        // DriverException rather than the typed subclass.
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => ['count' => 0, 'statements' => [], 'destructive' => []],
        ]);
        $maintenance->method('getEntityVsDbDrift')
            ->willReturn(['ALTER TABLE tenant ADD some_new_column VARCHAR(255) DEFAULT NULL']);
        $maintenance->method('executePendingMigrations')
            ->willReturn(['success' => true, 'executed' => 0, 'error' => null]);
        $maintenance->method('reconcileSchema')
            ->willReturn(['success' => true, 'executed' => 1, 'error' => null, 'blocked' => null]);

        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        // Wrap in a plain RuntimeException to exercise the exception-chain walk.
        $driver = $this->driverException("Unknown column 't0.some_new_column' in 'SELECT'");
        $inner = new \Doctrine\DBAL\Exception\DriverException($driver, null);
        $outer = new \RuntimeException('DB failure', 0, $inner);
        $event = $this->makeEvent('/de/admin/settings', $outer);

        $subscriber->onKernelException($event);

        $this->assertInstanceOf(
            RedirectResponse::class,
            $event->getResponse(),
            'Must redirect even when pending=0 and drift=0 for Unknown column DriverException',
        );
    }

    #[Test]
    public function tableNotFoundWithZeroPendingAndZeroDriftDoesNotRedirect(): void
    {
        // TableNotFoundException that is NOT an "Unknown column" error (e.g. a
        // typo in raw DQL) must NOT redirect when pending=0 and drift=0.
        // This test ensures the phantom-diff bypass only fires for the exact
        // unknown-column signal, not for every schema exception.
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => ['count' => 0, 'statements' => [], 'destructive' => []],
        ]);
        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance);
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $event = $this->makeEvent('/some-page', new TableNotFoundException(
            $this->driverException("Table 'mydb.typo_table' doesn't exist"),
            null,
        ));

        $subscriber->onKernelException($event);

        $this->assertNull($event->getResponse(), 'Non-unknown-column TableNotFoundException must NOT redirect when clean status');
    }

    #[Test]
    public function phantomDiffStateLogsWarningWithColumnName(): void
    {
        // Verify that the phantom_diff_state warning is logged and includes the
        // missing column name extracted from the exception message.
        $maintenance = $this->createMock(SchemaMaintenanceService::class);
        $maintenance->method('getMaintenanceStatus')->willReturn([
            'migration_status' => ['pending' => 0, 'names' => []],
            'schema_drift' => ['count' => 0, 'statements' => [], 'destructive' => []],
        ]);
        $maintenance->method('getEntityVsDbDrift')->willReturn([]);
        $maintenance->method('executePendingMigrations')
            ->willReturn(['success' => true, 'executed' => 0, 'error' => null]);

        $logCapture = new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };

        $subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator, $maintenance, $logCapture);
        $this->guard->method('fallbackUiEnabled')->willReturn(true);

        $exception = new InvalidFieldNameException(
            $this->driverException("Unknown column 't0.in_app_notifications_enabled' in 'field list'"),
            null,
        );
        $event = $this->makeEvent('/de/admin/users', $exception);

        $subscriber->onKernelException($event);

        // Find the phantom_diff_state log entry.
        $phantomRecords = array_filter(
            $logCapture->records,
            static fn (array $r): bool => str_contains($r['message'], 'phantom_diff_state'),
        );
        $this->assertNotEmpty($phantomRecords, 'Expected a phantom_diff_state warning log entry');

        $record = array_values($phantomRecords)[0];
        $this->assertSame('warning', $record['level']);
        $this->assertArrayHasKey('missing_column', $record['context']);
        $this->assertStringContainsString('in_app_notifications_enabled', (string) $record['context']['missing_column']);
    }

    private function makeEvent(string $path, \Throwable $throwable): ExceptionEvent
    {
        $request = Request::create($path);
        return new ExceptionEvent($this->kernel, $request, HttpKernelInterface::MAIN_REQUEST, $throwable);
    }

    private function driverException(string $message): DriverExceptionInterface
    {
        return new class($message) extends \RuntimeException implements DriverExceptionInterface {
            public function getSQLState(): ?string { return null; }
        };
    }
}
