<?php

declare(strict_types=1);

namespace App\Service\Planning\Source;

use App\Entity\Tenant;
use DateTimeInterface;

/**
 * One adapter per source module — the ONLY type-specific place in the
 * auto-conversion pipeline (Engineering-Spec §4). Each adapter knows how to read
 * its source entity's heterogeneous deadline field, decide convertibility, and
 * expose a stable id for the polymorphic provenance reference.
 *
 * Register implementations with the `app.source_adapter` tag (autoconfigured via
 * the _instanceof block in services.yaml).
 */
interface SourceAdapter
{
    /** Stable slug == ActionItem.origin == ActionItemReference.refType. */
    public function slug(): string;

    /** Human label for the provenance badge / settings UI. */
    public function label(): string;

    /** Module that must be active for this adapter to run, or null if always on. */
    public function requiredModule(): ?string;

    /**
     * Open, not-yet-completed source items for the tenant that should become
     * ActionItems. Implementations filter out completed items via {@see isCompleted()}.
     *
     * @return iterable<object>
     */
    public function findConvertible(Tenant $tenant): iterable;

    /** The source's deadline (heterogeneous field), or null if it has none. */
    public function dueDateOf(object $item): ?DateTimeInterface;

    public function titleOf(object $item): string;

    /** Whether the source item is already done (so it must not spawn a Maßnahme). */
    public function isCompleted(object $item): bool;

    /**
     * True when the source owns its own recurrence — the created ActionItem then
     * carries NO recurrenceMonths (the source re-emits the next item itself).
     */
    public function ownsRecurrence(): bool;

    /** The source row id used as ActionItemReference.refId. */
    public function refId(object $item): int;
}
