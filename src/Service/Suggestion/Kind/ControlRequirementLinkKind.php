<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\ComplianceRequirement;
use App\Entity\Control;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Text-similarity mapping proposals "control covers requirement" (A2,
 * MappingSuggestionService). They are computed live on the SoA control page
 * and not queued in bulk — that would flood the queue. What the queue adds
 * is memory: a rejected pair is stored here and no longer shown.
 */
final class ControlRequirementLinkKind extends AbstractSuggestionKind
{
    public const string KEY = 'control.requirement_link';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Rule;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_COMPLIANCE_MANAGER', 'ROLE_ISB', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'compliance';
    }

    public function draft(Control $control, ComplianceRequirement $requirement, float $confidence, Tenant $tenant): SuggestionDraft
    {
        return new SuggestionDraft(
            subject: $control,
            title: $this->text->t($tenant, 'suggestions.text.requirement_link.title', [
                '%control%' => ControlState::label($control),
                '%requirement%' => trim(($requirement->getFramework()?->getCode() ?? '') . ' ' . ($requirement->getRequirementId() ?? '')),
            ]),
            rationale: $this->text->t($tenant, 'suggestions.text.requirement_link.rationale', [
                '%title%' => (string) $requirement->getTitle(),
                '%pct%' => (int) round($confidence * 100),
            ]),
            payload: ['requirement' => $requirement->getId()],
            confidence: $confidence,
            dedupKey: self::pairKey((int) $control->getId(), (int) $requirement->getId()),
        );
    }

    public static function pairKey(int $controlId, int $requirementId): string
    {
        return sprintf('control:%d:requirement:%d', $controlId, $requirementId);
    }

    /** The key as stored (SuggestionDraft::resolveDedupKey), to filter rejected pairs. */
    public static function storedKey(int $controlId, int $requirementId): string
    {
        return sha1(self::KEY . '|' . self::pairKey($controlId, $requirementId));
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $control = $this->entityManager->find(Control::class, (int) $suggestion->getSubjectId());
        $requirement = $this->entityManager->find(ComplianceRequirement::class, (int) ($suggestion->getPayload()['requirement'] ?? 0));
        if (!$control instanceof Control || !$requirement instanceof ComplianceRequirement || $control->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('record_gone');
        }
        if (!$requirement->getMappedControls()->contains($control)) {
            $requirement->addMappedControl($control);
        }

        return $control;
    }
}
