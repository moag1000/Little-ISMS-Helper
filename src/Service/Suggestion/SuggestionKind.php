<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One kind of suggestion (Paket 5): finds what to propose (generate) and
 * carries out an accepted proposal (apply). Every source — rules over the
 * organisation's data, inheritance, imports, a language model — is a kind;
 * the queue, the decision dialog, the audit trail and the inbox are shared.
 *
 * Labels: `suggestions.kind.<key>` in the `suggestions` domain.
 * Interface doc: docs/features/SUGGESTION_QUEUE.md.
 */
#[AutoconfigureTag('app.suggestion_kind')]
interface SuggestionKind
{
    /** Stable key, e.g. 'asset.protection_need'. */
    public function key(): string;

    public function origin(): SuggestionOrigin;

    /**
     * Roles that decide when the record has no responsible person (role
     * hierarchy applies). The first one is shown as "decides".
     *
     * @return list<string>
     */
    public function decisionRoles(): array;

    /**
     * The right the same write needs in the UI (S30). Accepting needs it on
     * top of a decision role or the responsibility for the record — a
     * suggestion never widens who may write.
     */
    public function targetAccess(): TargetAccess;

    public function requiredModule(): ?string;

    /** May several suggestions of this kind be accepted at once? */
    public function allowsBulk(): bool;

    /**
     * Everything this kind would propose for the organisation right now
     * (nightly run, "check now" button). For rule kinds the result is
     * complete: pending suggestions not proposed again are superseded.
     *
     * @return iterable<SuggestionDraft>
     */
    public function generate(Tenant $tenant): iterable;

    /**
     * Carries out an accepted suggestion and returns the record created or
     * changed. Throws StaleSuggestionException when the proposal no longer
     * fits the record (the queue then marks it superseded).
     */
    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object;

    /** Hook for kinds that mirror a decision elsewhere (inheritance log). */
    public function onReject(Suggestion $suggestion, User $by, ?string $reason): void;
}
