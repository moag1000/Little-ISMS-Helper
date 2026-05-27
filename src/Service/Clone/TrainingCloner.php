<?php

declare(strict_types=1);

namespace App\Service\Clone;

use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Tenant;
use App\Entity\Training;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Training Cloner (C4-C1 — Klon-Funktionen).
 *
 * Use case: annual ISO 27001 A.6.3 awareness training cycle — duplicate
 * last year's training schedule and reschedule it. Roll out the same
 * trainer + content + materials to multiple sites or departments.
 *
 * The clone keeps the content template (title, description, trainingType,
 * trainer, duration, materials, deliveryMethod, mandatory flag, recurrence
 * cadence) plus the M2M control / compliance-requirement coverage so the
 * trained controls keep their evidence trail.
 *
 * Reset on clone:
 *   - status → 'planned' (initial lifecycle)
 *   - scheduledDate cleared (must be re-planned)
 *   - completionDate cleared
 *   - attendeeCount → 0
 *   - feedback cleared (per-session)
 *   - lastReminderSentAt cleared
 *
 * Cascade omissions:
 *   - participations (TrainingParticipation OneToMany) — attendance records
 *     are session-specific; would corrupt the per-session attendance log
 *   - participantUsers M2M — per-session attendees
 *   - participants legacy text field — also per-session list
 *
 * Caller is expected to flush.
 */
final class TrainingCloner implements EntityClonerInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supportsEntity(): string
    {
        return Training::class;
    }

    /**
     * @param Training $source
     */
    public function clone(object $source, ?Tenant $targetTenant = null, ?string $titleOverride = null): Training
    {
        if (!$source instanceof Training) {
            throw new \InvalidArgumentException(sprintf(
                'TrainingCloner expects %s, got %s',
                Training::class,
                $source::class,
            ));
        }

        $clone = new Training();

        $tenant = $targetTenant ?? $source->getTenant();
        if ($tenant instanceof Tenant) {
            $clone->setTenant($tenant);
        }

        $baseTitle = (string) $source->getTitle();
        $clone->setTitle($titleOverride !== null && $titleOverride !== ''
            ? $titleOverride
            : ($baseTitle !== '' ? $baseTitle . ' (Kopie)' : 'Kopie')
        );

        $clone->setDescription($source->getDescription());
        $clone->setTrainingType($source->getTrainingType());
        $clone->setDurationMinutes($source->getDurationMinutes());
        $clone->setTrainer($source->getTrainer());
        $clone->setTargetAudience($source->getTargetAudience());
        $clone->setDeliveryMethod($source->getDeliveryMethod());
        $clone->setMandatory($source->isMandatory());
        $clone->setMaterials($source->getMaterials());
        $clone->setMaterialFiles($source->getMaterialFiles());
        $clone->setProgramType($source->getProgramType());
        $clone->setRecurrenceMonths($source->getRecurrenceMonths());

        // M2M control + requirement coverage — these are TEMPLATE data
        // (which controls does the training cover); keep them on the clone
        // so the new session inherits evidence linkage.
        foreach ($source->getCoveredControls() as $control) {
            if ($control instanceof Control) {
                $clone->addCoveredControl($control);
            }
        }
        foreach ($source->getComplianceRequirements() as $requirement) {
            if ($requirement instanceof ComplianceRequirement) {
                $clone->addComplianceRequirement($requirement);
            }
        }

        // Reset lifecycle to 'planned'; clear per-session execution data.
        $clone->setStatus('planned'); // @phpstan-ignore lifecycle.directSetStatus (initial state on clone pre-persist — matches entity-specific lifecycle.initial_marking)
        $clone->setScheduledDate(null);
        $clone->setCompletionDate(null);
        $clone->setAttendeeCount(0);
        $clone->setFeedback(null);
        $clone->setLastReminderSentAt(null);
        $clone->setParticipants(null);

        $clone->setCreatedAt(new DateTimeImmutable());
        $clone->setUpdatedAt(null);

        $this->entityManager->persist($clone);

        return $clone;
    }
}
