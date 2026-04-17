<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\FrameworkActivatedEvent;
use App\Service\ComplianceInheritanceService;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FrameworkActivatedEvent::class)]
final class CreateInheritanceSuggestionsListener
{
    public function __construct(
        private readonly ComplianceInheritanceService $inheritanceService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(FrameworkActivatedEvent $event): void
    {
        try {
            $result = $this->inheritanceService->createInheritanceSuggestions(
                $event->tenant,
                $event->framework,
                $event->activatedBy,
            );
            $this->logger->info('Inheritance suggestions created on framework activation', [
                'tenant' => $event->tenant->getId(),
                'framework' => $event->framework->getCode(),
                'created' => $result['created'],
                'skipped' => $result['skipped'],
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create inheritance suggestions', [
                'tenant' => $event->tenant->getId(),
                'framework' => $event->framework->getCode(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
