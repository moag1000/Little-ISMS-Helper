<?php

declare(strict_types=1);

namespace App\Service\Deadline;

use App\Entity\Tenant;
use App\Service\Responsibility\Responsibility;
use DateTimeInterface;

/**
 * One source of obligations with a deadline ("Fristenquelle").
 *
 * Every kind of duty the tool reminds people of — a risk review, an expiring
 * acceptance, a finding, a supplier reassessment, an approval — is one
 * implementation. My Day, the morning digest mail and the urgent mail read
 * ONLY from these sources, and ask {@see \App\Service\Responsibility\ResponsibilityResolver}
 * who has to act. A new norm brings new sources, nothing else.
 *
 * Sources the action planner may turn into Maßnahmen implement the extended
 * {@see \App\Service\Planning\Source\SourceAdapter}; inbox-only sources
 * (suggestions, decisions) implement this interface alone.
 *
 * Registered via the `app.deadline_source` tag (autoconfigured).
 */
interface DeadlineSource
{
    /** Stable slug, unique across all sources. */
    public function slug(): string;

    /** Human label of the source type. */
    public function label(): string;

    /** Module that must be active for this source, or null if always on. */
    public function requiredModule(): ?string;

    /**
     * Open items of the tenant. May include completed ones — callers filter
     * through {@see isCompleted()}.
     *
     * @return iterable<object>
     */
    public function findOpen(Tenant $tenant): iterable;

    /** The deadline, or null for an open item without one (shown while open). */
    public function dueDateOf(object $item): ?DateTimeInterface;

    public function titleOf(object $item): string;

    public function isCompleted(object $item): bool;

    /** The record id. */
    public function refId(object $item): int;

    /** Is this record one of this source's items? (record → source lookup) */
    public function supports(object $item): bool;

    /** Who has to act: relations on the record first, roles as fallback. */
    public function responsibleOf(object $item): Responsibility;

    /** The record's page, or null when it has none. */
    public function linkOf(object $item): ?RecordLink;

    /** Inbox group, lead time and norm reference of this kind of duty. */
    public function profile(): DeadlineProfile;
}
