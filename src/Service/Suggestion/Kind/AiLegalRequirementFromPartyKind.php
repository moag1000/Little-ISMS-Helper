<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\InterestedParty;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RegisterType;
use App\Repository\InterestedPartyRepository;
use App\Repository\IsmsRegisterEntryRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Register\RegisterProposal;
use App\Service\Register\RegisterProposalAdopter;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\RegisterController;
use App\Service\Suggestion\TargetAccess;

/**
 * Compliance manager (ISO 27001 4.2 b, A.5.31): an interested party whose
 * requirements are only free text and have no entry in the register of legal
 * and contractual requirements → the model drafts one structured entry
 * (title, description, kind, reference). Accepting writes it through the
 * register's one adoption path, linked to the party; the owner is the person
 * who accepted.
 */
final class AiLegalRequirementFromPartyKind extends AbstractAiKind
{
    public const string KEY = 'ai.legal_requirement_from_party';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly InterestedPartyRepository $parties,
        private readonly IsmsRegisterEntryRepository $entries,
        private readonly RegisterProposalAdopter $adopter,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
        parent::__construct($ai, $suggestions);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function targetAccess(): TargetAccess
    {
        return TargetAccess::action(RegisterController::class, 'adopt');
    }

    public function decisionRoles(): array
    {
        return ['ROLE_COMPLIANCE_MANAGER', 'ROLE_ISB'];
    }

    public function requiredModule(): string
    {
        return 'interested_parties';
    }

    public function subjectClasses(): array
    {
        return [InterestedParty::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->parties->findBy(['tenant' => $tenant], ['name' => 'ASC'], 100) as $party) {
            if ($this->isCandidate($party)) {
                yield $party;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        $tenant = $record instanceof InterestedParty ? $record->getTenant() : null;

        return $record instanceof InterestedParty
            && $tenant instanceof Tenant
            && $record->getStatus() !== InterestedParty::STATUS_ARCHIVED
            && mb_strlen(trim((string) $record->getRequirements())) >= 20
            && ($record->getLegalRequirements() ?? []) === []
            && !$this->hasEntry($record, $tenant);
    }

    protected function instructions(): string
    {
        return 'Turn the requirements of this interested party into one entry of the register of legal, regulatory and '
            . 'contractual requirements (ISO/IEC 27001 4.2 b, A.5.31). Pick the most important requirement that binds '
            . 'the organisation; do not invent laws or paragraphs. Return keys "title" (max 120 chars), "description" '
            . '(what is required and what it means for information security, 2-4 sentences), '
            . '"category" (one of: law, regulation, contract, standard, internal), '
            . '"reference" (paragraph, contract or standard, or empty if unknown).';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof InterestedParty);

        return implode("\n", array_filter([
            'Interested party: ' . $record->getName() . ($record->getPartyType() ? ' (' . $record->getPartyType() . ')' : ''),
            $record->getDescription() ? 'Description: ' . $record->getDescription() : null,
            'Importance: ' . $record->getImportance(),
            'Requirements and expectations: ' . $record->getRequirements(),
            $record->getHowAddressed() ? 'How addressed: ' . $record->getHowAddressed() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof InterestedParty);
        $title = self::text($data, 'title', 255);
        $description = self::text($data, 'description');
        if ($title === '' || $description === '') {
            return null;
        }
        $category = self::text($data, 'category', 40);
        if (!in_array($category, RegisterType::LEGAL_REQUIREMENT->categories(), true)) {
            $category = 'contract';
        }
        $reference = self::text($data, 'reference', 255);

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_legal_req.title', ['%requirement%' => $title]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_legal_req.rationale', [
                '%party%' => (string) $record->getName(),
                '%category%' => $category,
                '%reference%' => $reference !== '' ? $reference : '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['title' => $title, 'category' => $category, 'reference' => $reference],
            editableText: $description,
            confidence: self::confidence($data),
            dedupKey: 'party:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $tenant = $suggestion->getTenant();
        $party = $this->entityManager->find(InterestedParty::class, (int) $suggestion->getSubjectId());
        if (!$party instanceof InterestedParty || $party->getTenant() !== $tenant) {
            throw new StaleSuggestionException('party_gone');
        }
        if ($this->hasEntry($party, $tenant)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $p = $suggestion->getPayload();
        $proposal = new RegisterProposal(
            RegisterType::LEGAL_REQUIREMENT,
            (string) $p['title'],
            (string) ($editedText ?? $suggestion->getEditableText()),
            self::originKey($party),
            'register.source.interested_party',
            ownerUserId: $by->getId(),
            category: (string) ($p['category'] ?? 'contract'),
            reference: ($p['reference'] ?? '') !== '' ? (string) $p['reference'] : null,
            links: ['interested_party' => [(int) $party->getId()]],
        );

        return $this->adopter->adopt($tenant, [$proposal], $by)[0];
    }

    /** The party has a legal requirement entry: linked to it, or already adopted from this kind. */
    private function hasEntry(InterestedParty $party, Tenant $tenant): bool
    {
        foreach ($this->entries->findLinkingTo($tenant, 'interested_party', (int) $party->getId()) as $entry) {
            if ($entry->getType() === RegisterType::LEGAL_REQUIREMENT) {
                return true;
            }
        }

        return in_array(self::originKey($party), $this->entries->origins($tenant, RegisterType::LEGAL_REQUIREMENT), true);
    }

    private static function originKey(InterestedParty $party): string
    {
        return 'ai.party.' . (int) $party->getId();
    }
}
