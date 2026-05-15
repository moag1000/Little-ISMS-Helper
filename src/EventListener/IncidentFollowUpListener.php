<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Incident;
use App\Service\FollowUpTrigger\FollowUpTriggerDefinition;
use App\Service\FollowUpTrigger\FollowUpTriggerService;
use DateTimeInterface;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Sprint-2 Foundation P-7 — Wave-1 trigger registration.
 *
 * Wires the only customer of FollowUpTriggerService for Wave-1: when
 * `Incident.dataBreachOccurred` flips to true, the service emits a
 * FollowUpTriggerResult whose pre-fill payload mirrors the relevant
 * incident fields into the DataBreach form. The corresponding AlvaHint
 * rule `incident.requires_data_breach` surfaces the regulatory CTA on
 * the Incident show-page; this listener exists primarily to log the
 * event so the audit trail captures the GDPR Art. 33 trigger and to
 * keep the trigger registry self-contained (no service-yaml manual
 * compose() calls needed).
 *
 * Side-effects: write-only logging. The listener does not persist the
 * DataBreach skeleton — that is the operator's deliberate action via the
 * AlvaHint CTA (so an audit-evidence "User clicked" event is recorded).
 */
#[AsEntityListener(event: Events::postPersist, entity: Incident::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Incident::class)]
class IncidentFollowUpListener
{
    private bool $registered = false;

    public function __construct(
        private readonly FollowUpTriggerService $triggerService,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function postPersist(Incident $incident, PostPersistEventArgs $args): void
    {
        $this->ensureRegistered();
        $this->evaluate($incident);
    }

    public function postUpdate(Incident $incident, PostUpdateEventArgs $args): void
    {
        $this->ensureRegistered();
        $this->evaluate($incident);
    }

    private function evaluate(Incident $incident): void
    {
        try {
            $results = $this->triggerService->evaluate($incident);
            foreach ($results as $result) {
                $this->logger->info('FollowUp-Trigger fired', [
                    'parent_entity' => Incident::class,
                    'parent_id' => $incident->getId(),
                    'alva_hint_key' => $result->definition->alvaHintKey,
                    'follow_up_route' => $result->definition->followUpRoute,
                ]);
            }
        } catch (\Throwable $e) {
            // A buggy pre-filler must never break the Incident persist.
            $this->logger->warning('FollowUp-Trigger evaluation failed', [
                'parent_id' => $incident->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Lazy single-shot registration so the listener and the registry stay
     * co-located. The service is the same singleton across calls, so
     * registering twice would just append duplicate definitions.
     */
    private function ensureRegistered(): void
    {
        if ($this->registered) {
            return;
        }
        $this->triggerService->register(
            Incident::class,
            new FollowUpTriggerDefinition(
                fieldName: 'dataBreachOccurred',
                equals: true,
                alvaHintKey: 'incident.requires_data_breach',
                followUpRoute: 'app_data_breach_new',
                preFiller: static function (Incident $incident): array {
                    $detectedAt = $incident->getDetectedAt();
                    return [
                        'title' => $incident->getTitle() !== null
                            ? sprintf('Data Breach: %s', $incident->getTitle())
                            : null,
                        'description' => $incident->getDescription(),
                        'detectedAt' => $detectedAt instanceof DateTimeInterface
                            ? $detectedAt->format(DateTimeInterface::ATOM)
                            : null,
                        'incidentId' => $incident->getId(),
                    ];
                },
            ),
        );
        $this->registered = true;
    }
}
