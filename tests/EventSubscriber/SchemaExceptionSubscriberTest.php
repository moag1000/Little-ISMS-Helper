<?php

declare(strict_types=1);

namespace App\Tests\EventSubscriber;

use App\EventSubscriber\SchemaExceptionSubscriber;
use App\Service\QuickFixGuard;
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
    private SchemaExceptionSubscriber $subscriber;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(QuickFixGuard::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->kernel = $this->createMock(HttpKernelInterface::class);

        $this->urlGenerator->method('generate')->willReturn('/quick-fix');

        $this->subscriber = new SchemaExceptionSubscriber($this->guard, $this->urlGenerator);
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
