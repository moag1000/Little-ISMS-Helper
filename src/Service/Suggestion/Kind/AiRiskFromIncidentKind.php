<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IncidentRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Risk manager: a recent incident that no risk covers yet → the model drafts
 * the risk (threat, vulnerability, likelihood, impact) from the incident text
 * (ISO 27001 6.1.2, 10.2 lessons learned). Accepting creates the risk in
 * status "identified", linked to the incident and its first affected asset.
 */
final class AiRiskFromIncidentKind extends AbstractAiKind
{
    public const string KEY = 'ai.risk_from_incident';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly IncidentRepository $incidents,
        private readonly EntityManagerInterface $entityManager,
        private readonly SuggestionText $text,
    ) {
        parent::__construct($ai, $suggestions);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_RISK_MANAGER'];
    }

    public function requiredModule(): ?string
    {
        return 'risks';
    }

    protected function candidates(Tenant $tenant): iterable
    {
        $since = new \DateTimeImmutable('-90 days');
        foreach ($this->incidents->findBy(['tenant' => $tenant], ['detectedAt' => 'DESC'], 50) as $incident) {
            if ($incident->getDetectedAt() !== null && $incident->getDetectedAt() < $since) {
                break;
            }
            if ($incident->getRealizedRisks()->count() === 0 && mb_strlen((string) $incident->getDescription()) >= 40) {
                yield $incident;
            }
        }
    }

    protected function instructions(): string
    {
        return 'Derive the information security risk that this incident shows, so it can be added to the risk register. '
            . 'Return keys: "title" (max 120 chars), "description" (2-4 sentences: what could happen to which asset, '
            . 'and the consequence), "threat", "vulnerability", "probability" (1-5), "impact" (1-5).';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof Incident);
        $assets = array_map(static fn (Asset $a): string => (string) $a->getName(), $record->getAffectedAssets()->toArray());

        return implode("\n", array_filter([
            'Incident: ' . $record->getTitle(),
            'Category: ' . $record->getCategory(),
            'Severity: ' . $record->getSeverity()?->value,
            'Affected assets: ' . implode(', ', $assets),
            'Description: ' . $record->getDescription(),
            $record->getRootCause() ? 'Root cause: ' . $record->getRootCause() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof Incident);
        $title = self::text($data, 'title', 200);
        $description = self::text($data, 'description');
        if ($title === '' || $description === '') {
            return null;
        }
        $payload = [
            'title' => $title,
            'threat' => self::text($data, 'threat', 1000),
            'vulnerability' => self::text($data, 'vulnerability', 1000),
            'probability' => self::scale($data, 'probability'),
            'impact' => self::scale($data, 'impact'),
        ];

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_risk.title', ['%risk%' => $title]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_risk.rationale', [
                '%incident%' => trim(($record->getIncidentNumber() ?? '') . ' ' . ($record->getTitle() ?? '')),
                '%threat%' => $payload['threat'] !== '' ? $payload['threat'] : '—',
                '%vulnerability%' => $payload['vulnerability'] !== '' ? $payload['vulnerability'] : '—',
                '%probability%' => $payload['probability'],
                '%impact%' => $payload['impact'],
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: $payload,
            editableText: $description,
            confidence: self::confidence($data),
            dedupKey: 'incident:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $incident = $this->entityManager->find(Incident::class, (int) $suggestion->getSubjectId());
        if (!$incident instanceof Incident || $incident->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('incident_gone');
        }
        $p = $suggestion->getPayload();
        $risk = new Risk();
        $risk->setTenant($suggestion->getTenant());
        $risk->setTitle((string) $p['title']);
        $risk->setCategory('security');
        $risk->setDescription((string) ($editedText ?? $suggestion->getEditableText()));
        $risk->setThreat((string) ($p['threat'] ?? '') ?: null);
        $risk->setVulnerability((string) ($p['vulnerability'] ?? '') ?: null);
        $risk->setProbability((int) $p['probability']);
        $risk->setImpact((int) $p['impact']);
        $risk->setResidualProbability((int) $p['probability']);
        $risk->setResidualImpact((int) $p['impact']);
        $asset = $incident->getAffectedAssets()->first();
        if ($asset instanceof Asset) {
            $risk->setAsset($asset);
        }
        $this->entityManager->persist($risk);
        $incident->addRealizedRisk($risk);
        $this->entityManager->flush();

        return $risk;
    }
}
