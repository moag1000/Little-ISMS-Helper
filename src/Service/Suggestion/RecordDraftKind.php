<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Tenant;

/**
 * A suggestion kind that can also draft for one record on request — the
 * "Entwurf vorschlagen" button on a detail page (P6). The draft goes into
 * the same queue as the nightly ones; a person decides it there or in the
 * hint above the record's links block. Nothing is written before that.
 */
interface RecordDraftKind extends SuggestionKind
{
    /**
     * Record classes the button may appear on (whitelist for the request).
     *
     * @return list<class-string>
     */
    public function subjectClasses(): array;

    /** Can this kind draft something useful for the record right now? */
    public function supportsRecord(object $record): bool;

    /** Does the draft wait for a language model (async job) or come from rules (immediate)? */
    public function needsModel(): bool;

    /** The draft for this record, or null when nothing could be drafted (model failed, facts missing). */
    public function draftFor(Tenant $tenant, object $record): ?SuggestionDraft;
}
