<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Asset;
use App\Entity\Incident;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\IncidentRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Incident\ReportingDuty;
use App\Service\Incident\ReportingPlanner;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;

/**
 * ISB under time pressure: an incident with an open NIS-2 or DORA report
 * (early warning 24 h, notification 72 h, DORA 4 h …) and no report text yet
 * → the model drafts the short text for the authority (NIS-2 Art. 23 (4) a:
 * what happened, suspected malicious act, cross-border impact). Accepting
 * stores it as the incident's report summary, which the MUS export sends.
 * Marking the report as sent stays with the person.
 */
final class AiIncidentReportKind extends AbstractAiKind
{
    public const string KEY = 'ai.incident_report';

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly IncidentRepository $incidents,
        private readonly ReportingPlanner $planner,
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
        return ['ROLE_ISB', 'ROLE_CISO'];
    }

    public function requiredModule(): string
    {
        return 'nis2_dora';
    }

    public function subjectClasses(): array
    {
        return [Incident::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        $since = new \DateTimeImmutable('-35 days');
        foreach ($this->incidents->findBy(['tenant' => $tenant], ['detectedAt' => 'DESC'], 50) as $incident) {
            if ($incident->getDetectedAt() !== null && $incident->getDetectedAt() < $since) {
                break;
            }
            if ($this->isCandidate($incident)) {
                yield $incident;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof Incident
            && trim((string) $record->getReportSummary()) === ''
            && mb_strlen((string) $record->getDescription()) >= 40
            && $this->nextDuty($record) instanceof ReportingDuty;
    }

    protected function instructions(): string
    {
        return 'Write the short text of the next statutory incident report to the authority (NIS-2 Art. 23 (4) / '
            . 'DORA Art. 19): what happened and since when, which services are affected, whether an unlawful or '
            . 'malicious act is suspected, whether there is cross-border impact, what has been done so far. '
            . '4-8 factual sentences, no speculation beyond the facts, say "not yet known" where a fact is missing. '
            . 'Return key "report".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof Incident);
        $duty = $this->nextDuty($record);
        $assets = array_map(static fn (Asset $a): string => (string) $a->getName(), $record->getAffectedAssets()->toArray());
        $malicious = $record->getNis2SuspectedMalicious();

        return implode("\n", array_filter([
            $duty !== null ? 'Report due: ' . $duty->type->value . ' by ' . $duty->deadline->format(DATE_ATOM) : null,
            'Incident: ' . $record->getTitle(),
            'Detected: ' . $record->getDetectedAt()?->format(DATE_ATOM),
            'Severity: ' . $record->getSeverity()?->value,
            'Category: ' . $record->getCategory(),
            $record->getNis2Category() ? 'NIS-2 category: ' . $record->getNis2Category() : null,
            'Suspected malicious: ' . ($malicious === null ? 'not assessed' : ($malicious ? 'yes' : 'no')),
            'Cross-border impact: ' . ($record->isCrossBorderImpact() ? 'yes' : 'no'),
            $record->getAffectedUsersCount() !== null ? 'Affected users: ' . $record->getAffectedUsersCount() : null,
            $assets !== [] ? 'Affected assets: ' . implode(', ', $assets) : null,
            $record->getAffectedSystems() ? 'Affected systems: ' . $record->getAffectedSystems() : null,
            'Description: ' . $record->getDescription(),
            $record->getImmediateActions() ? 'Immediate actions: ' . $record->getImmediateActions() : null,
            $record->getContainmentActions() ? 'Containment: ' . $record->getContainmentActions() : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof Incident);
        $report = self::text($data, 'report');
        $duty = $this->nextDuty($record);
        if ($report === '' || $duty === null) {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_incident_report.title', [
                '%incident%' => trim(($record->getIncidentNumber() ?? '') . ' ' . ($record->getTitle() ?? '')),
            ]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_incident_report.rationale', [
                '%deadline%' => $duty->deadline->format('d.m.Y H:i'),
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: ['duty' => $duty->type->value],
            editableText: $report,
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
        if (trim((string) $incident->getReportSummary()) !== '') {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $incident->setReportSummary((string) ($editedText ?? $suggestion->getEditableText()));

        return $incident;
    }

    private function nextDuty(Incident $incident): ?ReportingDuty
    {
        $next = null;
        foreach ($this->planner->forIncident($incident) as $duty) {
            if ($duty->reportedAt === null && ($next === null || $duty->deadline < $next->deadline)) {
                $next = $duty;
            }
        }

        return $next;
    }
}
