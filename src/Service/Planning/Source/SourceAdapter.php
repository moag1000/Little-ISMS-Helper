<?php

declare(strict_types=1);

namespace App\Service\Planning\Source;

use App\Entity\Tenant;
use App\Service\Deadline\DeadlineSource;

/**
 * A {@see DeadlineSource} the action planner may also turn into Maßnahmen
 * (Engineering-Spec §4). Everything a source knows about its deadline, title,
 * completion and responsibility lives in DeadlineSource; this adds the two
 * planner-only questions.
 *
 * Register implementations with the `app.source_adapter` tag (autoconfigured via
 * the _instanceof block in services.yaml) — they are deadline sources too.
 */
interface SourceAdapter extends DeadlineSource
{
    /**
     * Open, not-yet-completed source items for the tenant that should become
     * ActionItems. Implementations filter out completed items via {@see isCompleted()}.
     *
     * @return iterable<object>
     */
    public function findConvertible(Tenant $tenant): iterable;

    /**
     * True when the source owns its own recurrence — the created ActionItem then
     * carries NO recurrenceMonths (the source re-emits the next item itself).
     */
    public function ownsRecurrence(): bool;
}
