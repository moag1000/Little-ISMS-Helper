<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SchemaExceptionSubscriber;
use App\Service\QuickFixGuard;
use App\Service\SchemaMaintenanceService;
use Doctrine\DBAL\Driver\Exception as DriverExceptionInterface;
use Doctrine\DBAL\Exception\TableNotFoundException;
use Doctrine\ORM\Mapping\MappingException;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
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
