<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\FulfillmentInheritanceLogRepository;
use App\Service\ComplianceInheritanceService;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;

/**
 * Mirrors the open inheritance proposals (FulfillmentInheritanceLog, created
 * when a framework is activated) into the queue, so they reach the
 * compliance manager without knowing the per-framework review page. The log
 * stays the record: accepting / rejecting calls ComplianceInheritanceService
 * (minimum comment length, four-eyes, audit log unchanged). A log decided on
 * the framework page drops out of the queue on the next run.
 */
final class ComplianceInheritanceKind extends AbstractSuggestionKind
{
    public const string KEY = 'compliance.inheritance';

    public function __construct(
        private readonly FulfillmentInheritanceLogRepository $logs,
        private readonly ComplianceInheritanceService $inheritance,
        private readonly SuggestionText $text,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function origin(): SuggestionOrigin
    {
        return SuggestionOrigin::Inheritance;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_COMPLIANCE_MANAGER', 'ROLE_MANAGER'];
    }

    public function requiredModule(): string
    {
        return 'compliance';
    }

    /** The inheritance service asks for a written comment per item. */
    public function allowsBulk(): bool
    {
        return false;
    }

    public function generate(Tenant $tenant): iterable
    {
        foreach ($this->logs->findBy(['tenant' => $tenant, 'reviewStatus' => FulfillmentInheritanceLog::STATUS_PENDING_REVIEW]) as $log) {
            $target = $log->getFulfillment()?->getRequirement();
            $mapping = $log->getDerivedFromMapping();
            $source = $mapping?->getSourceRequirement();
            if ($target === null || $source === null) {
                continue;
            }
            $params = [
                '%target%' => trim(($target->getFramework()?->getCode() ?? '') . ' ' . ($target->getRequirementId() ?? '')),
                '%source%' => trim(($source->getFramework()?->getCode() ?? '') . ' ' . ($source->getRequirementId() ?? '')),
                '%percent%' => $log->getSuggestedPercentage(),
                '%confidence%' => $mapping->getConfidence(),
            ];
            yield new SuggestionDraft(
                subject: $log,
                title: $this->text->t($tenant, 'suggestions.text.inheritance.title', $params + ['%title%' => (string) $target->getTitle()]),
                rationale: $this->text->t($tenant, 'suggestions.text.inheritance.rationale', $params),
                payload: ['log' => $log->getId(), 'framework' => $target->getFramework()?->getCode()],
                editableText: $this->text->t($tenant, 'suggestions.text.inheritance.comment', $params),
                confidence: match ($mapping->getConfidence()) {
                    'high' => 0.9,
                    'medium' => 0.6,
                    default => 0.3,
                },
                dedupKey: 'log:' . (int) $log->getId(),
            );
        }
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $log = $this->pendingLog($suggestion);
        $this->inheritance->confirmInheritance($log, $by, (string) $editedText);

        return $log;
    }

    public function onReject(Suggestion $suggestion, User $by, ?string $reason): void
    {
        $this->inheritance->rejectInheritance($this->pendingLog($suggestion), $by, (string) $reason);
    }

    private function pendingLog(Suggestion $suggestion): FulfillmentInheritanceLog
    {
        $log = $this->logs->find((int) ($suggestion->getPayload()['log'] ?? 0));
        if (!$log instanceof FulfillmentInheritanceLog || $log->getTenant() !== $suggestion->getTenant() || !$log->isPendingReview()) {
            throw new StaleSuggestionException('already_decided');
        }

        return $log;
    }
}
