<?php

declare(strict_types=1);

namespace App\Tests\Service\FollowUpTrigger;

use App\Entity\Incident;
use App\Service\FollowUpTrigger\FollowUpTriggerDefinition;
use App\Service\FollowUpTrigger\FollowUpTriggerService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FollowUpTriggerServiceTest extends TestCase
{
    private FollowUpTriggerService $service;

    protected function setUp(): void
    {
        $this->service = new FollowUpTriggerService();
    }

    #[Test]
    public function returnsEmptyResultWhenNoTriggersRegistered(): void
    {
        $incident = new Incident();
        $this->assertSame([], $this->service->evaluate($incident));
    }

    #[Test]
    public function firesWhenFieldMatchesEqualityCheck(): void
    {
        $this->service->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'dataBreachOccurred',
                equals: true,
                alvaHintKey: 'incident.requires_data_breach',
                followUpRoute: 'app_data_breach_new',
            ),
        );

        $incident = new Incident();
        $incident->setDataBreachOccurred(true);

        $results = $this->service->evaluate($incident);
        $this->assertCount(1, $results);
        $this->assertSame('incident.requires_data_breach', $results[0]->definition->alvaHintKey);
        $this->assertSame('app_data_breach_new', $results[0]->definition->followUpRoute);
    }

    #[Test]
    public function doesNotFireWhenFieldDoesNotMatch(): void
    {
        $this->service->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'dataBreachOccurred',
                equals: true,
                alvaHintKey: 'incident.requires_data_breach',
            ),
        );

        $incident = new Incident();
        $incident->setDataBreachOccurred(false);

        $this->assertSame([], $this->service->evaluate($incident));
    }

    #[Test]
    public function ignoresTriggersForUnrelatedEntityClasses(): void
    {
        // Register trigger for a fake class to ensure entity-class check works.
        $this->service->register(
            \stdClass::class,
            new FollowUpTriggerDefinition(
                fieldName: 'foo',
                equals: 'bar',
                alvaHintKey: 'irrelevant',
            ),
        );

        $incident = new Incident();
        $incident->setDataBreachOccurred(true);
        $this->assertSame([], $this->service->evaluate($incident));
    }

    #[Test]
    public function preFillerProducesPayload(): void
    {
        $this->service->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'dataBreachOccurred',
                equals: true,
                alvaHintKey: 'incident.requires_data_breach',
                preFiller: static fn (Incident $i): array => [
                    'title' => $i->getTitle(),
                    'incidentId' => $i->getId(),
                ],
            ),
        );

        $incident = new Incident();
        $incident->setTitle('Phishing attack 2026-05');
        $incident->setDataBreachOccurred(true);

        $results = $this->service->evaluate($incident);
        $this->assertCount(1, $results);
        $this->assertSame('Phishing attack 2026-05', $results[0]->preFillPayload['title']);
        $this->assertArrayHasKey('incidentId', $results[0]->preFillPayload);
    }

    #[Test]
    public function strictEqualityRejectsLooseMatches(): void
    {
        $this->service->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'dataBreachOccurred',
                equals: 1, // intentionally wrong type
                alvaHintKey: 'incident.requires_data_breach',
            ),
        );

        $incident = new Incident();
        $incident->setDataBreachOccurred(true);

        // bool true must not match int 1 — strict equality
        $this->assertSame([], $this->service->evaluate($incident));
    }

    #[Test]
    public function multipleTriggersAreEvaluatedIndependently(): void
    {
        $this->service->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'dataBreachOccurred',
                equals: true,
                alvaHintKey: 'incident.requires_data_breach',
            ),
        );
        $this->service->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'notificationRequired',
                equals: true,
                alvaHintKey: 'incident.requires_notification',
            ),
        );

        $incident = new Incident();
        $incident->setDataBreachOccurred(true);
        $incident->setNotificationRequired(true);

        $results = $this->service->evaluate($incident);
        $this->assertCount(2, $results);
        $keys = array_map(static fn ($r): string => $r->definition->alvaHintKey, $results);
        $this->assertContains('incident.requires_data_breach', $keys);
        $this->assertContains('incident.requires_notification', $keys);
    }
}
