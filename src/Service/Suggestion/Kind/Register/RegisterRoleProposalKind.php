<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind\Register;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Service\Register\RegisterEntryService;
use App\Service\Register\RegisterProposalAdopter;
use App\Service\Register\RegisterProposalProvider;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Roles from the holders of the persona roles (5.3) — and the other way
 * round: when a holder loses the persona role (role withdrawn, account
 * deactivated), "check holder" proposes to archive the role entry. A new
 * holder, if any, gets the usual adoption proposal.
 */
final class RegisterRoleProposalKind extends AbstractRegisterProposalKind
{
    public function __construct(
        private readonly RegisterProposalProvider $roleProposals,
        RegisterProposalAdopter $adopter,
        private readonly SuggestionText $roleText,
        TranslatorInterface $translator,
        private readonly ?RegisterEntryService $writer = null,
    ) {
        parent::__construct($roleProposals, $adopter, $roleText, $translator);
    }

    protected function type(): RegisterType
    {
        return RegisterType::ROLE;
    }

    public function generate(Tenant $tenant): iterable
    {
        yield from parent::generate($tenant);
        foreach ($this->roleProposals->vacatedRoles($tenant) as $entry) {
            yield new SuggestionDraft(
                subject: $entry,
                title: $this->roleText->t($tenant, 'suggestions.text.register.vacated_title', ['%title%' => (string) $entry->getTitle()]),
                rationale: $this->roleText->t($tenant, 'suggestions.text.register.vacated_rationale', [
                    '%norm%' => RegisterType::ROLE->normReference(),
                ]),
                payload: [
                    'vacated_entry' => (int) $entry->getId(),
                    '_link' => ['app_register_entry_show', ['id' => (int) $entry->getId()]],
                ],
                dedupKey: 'vacated:' . (int) $entry->getId(),
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $entryId = $suggestion->getPayload()['vacated_entry'] ?? null;
        if ($entryId === null) {
            return parent::apply($suggestion, $by, $editedText);
        }
        foreach ($this->roleProposals->vacatedRoles($suggestion->getTenant()) as $entry) {
            if ($entry->getId() === (int) $entryId && $this->writer !== null) {
                $this->writer->toggleArchive($entry);

                return $entry;
            }
        }

        // holder got the role back, or the entry was archived or handed over meanwhile
        throw new StaleSuggestionException('already_applied');
    }
}
