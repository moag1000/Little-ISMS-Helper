<?php

declare(strict_types=1);

namespace App\Service\Objective;

use App\Entity\ISMSObjective;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISO 27001 9.1: confirms that an objective was measured today when the value
 * stayed the same (a changed value sets the date itself, see
 * {@see ISMSObjective::setCurrentValue()}). Closes the measurement deadline
 * until the next cadence.
 */
final readonly class ObjectiveMeasurement
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    public function confirm(ISMSObjective $objective): void
    {
        $objective->setLastMeasuredAt(new DateTimeImmutable('today'));
        $objective->setUpdatedAt(new DateTimeImmutable());
        $this->entityManager->flush();
    }
}
