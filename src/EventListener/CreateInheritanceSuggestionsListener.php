<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Event\FrameworkActivatedEvent;
use App\Service\ComplianceInheritanceService;
use App\Service\Suggestion\Kind\ComplianceInheritanceKind;
use App\Service\Suggestion\SuggestionGenerator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener(event: FrameworkActivatedEvent::class)]
final class CreateInheritanceSuggestionsListener
{
    public function __construct(
        private readonly ComplianceInheritanceService $inheritanceService,
        private readonly SuggestionGenerator $suggestionGenerator,
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
            // Mirror them into the suggestion queue right away (Paket 5),
            // not only with the nightly run.
            $this->suggestionGenerator->run($event->tenant, [ComplianceInheritanceKind::KEY]);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to create inheritance suggestions', [
                'tenant' => $event->tenant->getId(),
                'framework' => $event->framework->getCode(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
