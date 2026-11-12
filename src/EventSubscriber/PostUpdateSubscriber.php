<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Service\Update\PostUpdateRunner;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Hosts without a worker or cron line never run the scheduler, so the first request after a deploy
 * starts the automatic post-update run ({@see PostUpdateRunner}) — after the response has been sent
 * (kernel.terminate). The check on every other request is three file stats. Not in the test
 * environment (tests start the runner explicitly) and never for CLI.
 */
#[AsEventListener(event: KernelEvents::TERMINATE)]
final class PostUpdateSubscriber
{
    public function __construct(
        private readonly PostUpdateRunner $runner,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
    ) {
    }

    public function __invoke(TerminateEvent $event): void
    {
        if ($this->environment === 'test' || \PHP_SAPI === 'cli' || !$event->isMainRequest()) {
            return;
        }
        try {
            $this->runner->run('first_request');
        } catch (\Throwable $e) {
            $this->logger->error('Automatic post-update run crashed', ['exception' => $e]);
        }
    }
}
