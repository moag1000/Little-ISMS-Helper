<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\Tenant;
use DateTimeImmutable;

/**
 * A {@see DeadlineSource} that can leave records due after a date out in the
 * database query. {@see ObligationInbox} asks for "due by the end of the lead
 * time" instead of loading every record of the tenant (S31).
 */
interface HorizonFilteredSource
{
    /**
     * Open records due on or before $horizon. Records without a date are not
     * part of it (a type that is open without a date uses {@see DeadlineSource::findOpen()}).
     *
     * @return iterable<object>
     */
    public function findOpenDueBy(Tenant $tenant, DateTimeImmutable $horizon): iterable;
}
