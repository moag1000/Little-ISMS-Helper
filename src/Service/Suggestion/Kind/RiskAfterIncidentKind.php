<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Incident;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\RiskStatus;
use App\Enum\SuggestionOrigin;
use App\Repository\RiskRepository;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A risk occurred (an incident linked to it as realised risk was closed in
 * the last 90 days; ISO 27001 6.1.2 c, ISO 27005 lessons learned): propose
 * one step more likelihood, once per risk and incident. The flash "review
 * suggested" at closing was gone after one page view; this stays until the
 * risk manager decides. Rejecting is the answer to "already re-assessed".
 */
final class RiskAfterIncidentKind extends AbstractSuggestionKind
{
    public const string KEY = 'risk.after_incident';

    private const string LOOKBACK = '-90 days';

    public function __construct(
        private readonly RiskRepository $risks,
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
        return ['ROLE_RISK_MANAGER'];
    }

    public function requiredModule(): ?string
    {
        return 'incidents';
    }

    public function generate(Tenant $tenant): iterable
    {
        $since = new \DateTimeImmutable(self::LOOKBACK);
        foreach ($this->risks->findByTenant($tenant) as $risk) {
            $probability = (int) $risk->getProbability();
            if ($probability < 1 || $probability >= 5 || $risk->getStatus() === RiskStatus::Closed) {
                continue;
            }
            $latest = null;
            foreach ($risk->getIncidents() as $incident) {
                $closed = $incident->getClosedAt();
                if ($closed === null || $closed < $since) {
                    continue;
                }
                if ($latest === null || $closed > $latest->getClosedAt()) {
                    $latest = $incident;
                }
            }
            if (!$latest instanceof Incident) {
                continue;
            }
            yield new SuggestionDraft(
                subject: $risk,
                title: $this->text->t($tenant, 'suggestions.text.risk_after_incident.title', ['%risk%' => (string) $risk->getTitle()]),
                rationale: $this->text->t($tenant, 'suggestions.text.risk_after_incident.rationale', [
                    '%incident%' => trim(($latest->getIncidentNumber() ?? '') . ' ' . ($latest->getTitle() ?? '')),
                    '%closed%' => $latest->getClosedAt()?->format('d.m.Y') ?? '—',
                    '%from%' => $probability,
                    '%to%' => $probability + 1,
                ]),
                payload: ['probability' => $probability + 1, 'from' => $probability, 'incident' => $latest->getId()],
                dedupKey: sprintf('risk:%d:incident:%d', (int) $risk->getId(), (int) $latest->getId()),
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $risk = $this->entityManager->find(Risk::class, (int) $suggestion->getSubjectId());
        if (!$risk instanceof Risk || $risk->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('risk_gone');
        }
        $payload = $suggestion->getPayload();
        if ((int) $risk->getProbability() !== (int) ($payload['from'] ?? 0)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $risk->setProbability((int) $payload['probability']);

        return $risk;
    }
}
