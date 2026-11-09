<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\DataSubjectRequest;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\DataSubjectRequestController;
use App\Service\Suggestion\TargetAccess;

/**
 * DPO within the one-month deadline (GDPR Art. 12 (3)): an open data subject
 * request whose identity is verified where Art. 12 (6) requires it and that
 * has no answer yet → the model drafts the answer to the person (access,
 * erasure, objection … per Art. 15–22, incl. the right to complain, Art. 77).
 * Accepting stores it as the request's response text; sending it and closing
 * the request stay with the DPO.
 */
final class AiDataSubjectResponseKind extends AbstractAiKind
{
    public const string KEY = 'ai.dsr_response';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly DataSubjectRequestRepository $requests,
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
        return TargetAccess::action(DataSubjectRequestController::class, 'edit');
    }

    public function decisionRoles(): array
    {
        return ['ROLE_DPO'];
    }

    public function requiredModule(): string
    {
        return 'privacy';
    }

    public function subjectClasses(): array
    {
        return [DataSubjectRequest::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->requests->findBy(['tenant' => $tenant], ['receivedAt' => 'DESC'], 50) as $request) {
            if ($this->isCandidate($request)) {
                yield $request;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        if (!$record instanceof DataSubjectRequest || in_array($record->getStatus(), ['completed', 'rejected'], true)) {
            return false;
        }
        $identityOk = $record->isIdentityVerified()
            || !in_array($record->getRequestType(), DataSubjectRequest::IDENTITY_REQUIRED_TYPES, true);

        return $identityOk && trim((string) $record->getResponseDescription()) === '';
    }

    protected function instructions(): string
    {
        return 'Draft the written answer of the controller to this data subject request under the GDPR article given. '
            . 'Address the person neutrally (no name), say what was done or will be done, which data or copy is enclosed '
            . 'where applicable, and mention the right to lodge a complaint with a supervisory authority (Art. 77). '
            . 'Plain language, 6-12 sentences, no legal advice beyond the article. Return key "response".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof DataSubjectRequest);

        return implode("\n", array_filter([
            'Request type: ' . $record->getRequestType() . ' (' . $record->getGdprArticle() . ')',
            'Received: ' . $record->getReceivedAt()?->format('Y-m-d'),
            'Deadline: ' . $record->getEffectiveDeadline()?->format('Y-m-d'),
            'Request: ' . $record->getDescription(),
            $record->getProcessingActivity() ? 'Processing activity: ' . $record->getProcessingActivity()->getName()
                . ' — purposes: ' . implode(', ', array_map('strval', (array) $record->getProcessingActivity()->getPurposes())) : null,
            $record->getNotes() ? 'Internal notes: ' . $record->getNotes() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof DataSubjectRequest);
        $response = self::text($data, 'response', 8000);
        if ($response === '') {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_dsr.title', ['%request%' => $record->getDisplayName()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_dsr.rationale', [
                '%article%' => (string) $record->getGdprArticle(),
                '%deadline%' => $record->getEffectiveDeadline()?->format('d.m.Y') ?? '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            editableText: $response,
            confidence: self::confidence($data),
            dedupKey: 'dsr:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $request = $this->entityManager->find(DataSubjectRequest::class, (int) $suggestion->getSubjectId());
        if (!$request instanceof DataSubjectRequest || $request->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('request_gone');
        }
        if (trim((string) $request->getResponseDescription()) !== '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $request->setResponseDescription((string) ($editedText ?? $suggestion->getEditableText()));

        return $request;
    }
}
