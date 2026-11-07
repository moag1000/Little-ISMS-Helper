<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Service\DataRepair\LegacyOwnerReport;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;

/**
 * Free-text owner of an old record matches exactly one account or person of
 * the organisation (Admin > Daten-Reparatur > Freitext-Verantwortliche):
 * propose setting that owner. The admin page stays for the cases without a
 * match; the queue brings the easy ones to the admin without a visit.
 */
final class LegacyOwnerMatchKind extends AbstractSuggestionKind
{
    public const string KEY = 'legacy_owner.match';

    public function __construct(
        private readonly LegacyOwnerReport $report,
        private readonly SuggestionText $text,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Import;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_ADMIN'];
    }

    public function generate(Tenant $tenant): iterable
    {
        foreach ($this->report->openRows([$tenant]) as $row) {
            if ($row->suggestion === null) {
                continue;
            }
            yield new SuggestionDraft(
                subject: $row->record,
                title: $this->text->t($tenant, 'suggestions.text.legacy_owner.title', ['%record%' => $row->title]),
                rationale: $this->text->t($tenant, 'suggestions.text.legacy_owner.rationale', [
                    '%legacy%' => $row->legacy,
                    '%match%' => (string) $row->suggestionName(),
                ]),
                payload: ['type' => $row->type, 'id' => $row->id, '_link' => [$row->editRoute, ['id' => $row->id]]],
                dedupKey: sprintf('%s:%d:%s:%d', $row->type, $row->id, $row->suggestionIsUser() ? 'user' : 'person', (int) $row->suggestion->getId()),
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $payload = $suggestion->getPayload();
        $row = $this->report->findOpenRow((string) ($payload['type'] ?? ''), (int) ($payload['id'] ?? 0), [$suggestion->getTenant()]);
        if ($row === null || !$this->report->applySuggestion($row)) {
            throw new StaleSuggestionException('already_applied');
        }

        return $row->record;
    }
}
