<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Incident;
use App\EventListener\IncidentFollowUpListener;
use App\Service\FollowUpTrigger\FollowUpTriggerService;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class IncidentFollowUpListenerTest extends TestCase
{
    private FollowUpTriggerService $service;
    private IncidentFollowUpListener $listener;

    protected function setUp(): void
    {
        $this->service = new FollowUpTriggerService();
        $this->listener = new IncidentFollowUpListener($this->service);
    }

    #[Test]
    public function registersDataBreachTriggerOnFirstEvaluation(): void
    {
        $incident = $this->buildIncident(true);
        $this->listener->postUpdate($incident, $this->mockPostUpdateArgs());

        $registry = $this->service->getRegistry();
        $this->assertArrayHasKey(Incident::class, $registry);
        $this->assertCount(1, $registry[Incident::class]);
        $this->assertSame('incident.requires_data_breach', $registry[Incident::class][0]->alvaHintKey);
    }

    #[Test]
    public function preFillerExtractsRelevantIncidentFields(): void
    {
        $incident = $this->buildIncident(true);
        $this->listener->postPersist($incident, $this->mockPostPersistArgs());

        $results = $this->service->evaluate($incident);
        $this->assertCount(1, $results);
        $payload = $results[0]->preFillPayload;
        $this->assertSame('Data Breach: Phishing 2026-05', $payload['title']);
        $this->assertSame('A targeted phishing campaign exfiltrated PII.', $payload['description']);
        $this->assertNotEmpty($payload['detectedAt']);
    }

    #[Test]
    public function noTriggerFiresWhenFlagIsFalse(): void
    {
        $incident = $this->buildIncident(false);
        $this->listener->postUpdate($incident, $this->mockPostUpdateArgs());

        $results = $this->service->evaluate($incident);
        $this->assertSame([], $results);
    }

    #[Test]
    public function repeatedCallsDoNotDuplicateRegistration(): void
    {
        $incident = $this->buildIncident(true);
        $this->listener->postUpdate($incident, $this->mockPostUpdateArgs());
        $this->listener->postUpdate($incident, $this->mockPostUpdateArgs());
        $this->listener->postPersist($incident, $this->mockPostPersistArgs());

        $registry = $this->service->getRegistry();
        $this->assertCount(1, $registry[Incident::class]);
    }

    private function buildIncident(bool $dataBreachOccurred): Incident
    {
        $incident = new Incident();
        $incident->setTitle('Phishing 2026-05');
        $incident->setDescription('A targeted phishing campaign exfiltrated PII.');
        $incident->setDetectedAt(new DateTimeImmutable('-1 hour'));
        $incident->setDataBreachOccurred($dataBreachOccurred);
        return $incident;
    }

    private function mockPostUpdateArgs(): PostUpdateEventArgs
    {
        /** @var EntityManagerInterface $em */
        $em = $this->createMock(EntityManagerInterface::class);
        return new PostUpdateEventArgs(new \stdClass(), $em);
    }

    private function mockPostPersistArgs(): PostPersistEventArgs
    {
        /** @var EntityManagerInterface $em */
        $em = $this->createMock(EntityManagerInterface::class);
        return new PostPersistEventArgs(new \stdClass(), $em);
    }
}
