<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\EntityTypeRegistry;
use App\Lifecycle\LifecycleService;
use App\Service\ModuleConfigurationService;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\AssistantDraftSource;
use App\Service\Suggestion\AssistantOnlyKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use App\Service\Suggestion\TargetAccess;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Workflow\Registry;

/**
 * "Set this record to another status" — proposed by the person's assistant (MCP `propose_draft`, #1207), decided
 * by a person in the queue. The one generic proposal for every lifecycle-managed record (close an incident,
 * reactivate or retire an asset, archive a document): the assistant never writes a status, and accepting runs
 * the lifecycle transition, so roles, reasons, the audit log and the tenant's overrides apply unchanged
 * (LifecycleService is the only status writer).
 *
 * Offered to the assistant only for transitions the asking person may perform themselves and that need no
 * second person (four-eyes steps stay in the app); not a button in the UI, no nightly run.
 */
final class RecordTransitionKind extends AbstractSuggestionKind implements AssistantDraftSource, AssistantOnlyKind
{
    public const string KEY = 'record.transition';

    private const string APPROVAL_CHAIN = 'workflow_instance_lifecycle';

    public function __construct(
        private readonly LifecycleService $lifecycle,
        private readonly Registry $workflows,
        private readonly LifecycleConfigResolverInterface $resolver,
        private readonly Security $security,
        private readonly ModuleConfigurationService $modules,
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
        return SuggestionOrigin::Ai;
    }

    public function decisionRoles(): array
    {
        return ['ROLE_MANAGER'];
    }

    public function targetAccess(): TargetAccess
    {
        return TargetAccess::role('ROLE_MANAGER', 'status change through LifecycleService: the transition\'s own roles are checked again when a person accepts (S21)');
    }

    public function allowsBulk(): bool
    {
        return false;
    }

    public function subjectClasses(): array
    {
        $classes = [];
        foreach (EntityTypeRegistry::managedEntityClasses() as $class) {
            if (EntityTypeRegistry::workflowOfClass($class) !== self::APPROVAL_CHAIN
                && method_exists($class, 'getTenant') && method_exists($class, 'getStatus')) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    public function supportsRecord(object $record): bool
    {
        return $this->transitionsOf($record) !== [];
    }

    public function needsModel(): bool
    {
        return false;
    }

    /** The status is the assistant's choice: there is nothing to draft from the data alone. */
    public function draftFor(Tenant $tenant, object $record): ?SuggestionDraft
    {
        return null;
    }

    public function assistantBrief(object $record): ?array
    {
        $transitions = $this->transitionsOf($record);
        if ($transitions === []) {
            return null;
        }
        $lines = [];
        foreach ($transitions as $name => $transition) {
            $lines[] = sprintf(
                '- %s → %s%s',
                $name,
                $transition['to'],
                $transition['reason_required'] ? ' (reason required)' : '',
            );
        }

        return [
            'instructions' => 'Propose a status change. Send data as JSON with the keys "transition" (one of the names below) and '
                . '"reason" (one or two sentences: why the status changes now, from what you know). A person decides in the '
                . 'suggestion queue; the status is only changed then, by the lifecycle.',
            'facts' => sprintf("Current status: %s\nTransitions you may propose:\n%s", (string) $this->statusOf($record), implode("\n", $lines)),
        ];
    }

    public function draftFromAssistant(Tenant $tenant, object $record, array $data, string $model): ?SuggestionDraft
    {
        $transitions = $this->transitionsOf($record);
        $name = is_string($data['transition'] ?? null) ? trim($data['transition']) : '';
        $reason = is_string($data['reason'] ?? null) ? trim(mb_substr($data['reason'], 0, 1000)) : '';
        $workflow = EntityTypeRegistry::workflowOfClass($record::class);
        if ($workflow === null || !isset($transitions[$name]) || ($transitions[$name]['reason_required'] && $reason === '')) {
            return null;
        }
        $from = (string) $this->statusOf($record);
        $to = $transitions[$name]['to'];

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.record_transition.title', [
                '%record%' => self::labelOf($record),
                '%from%' => $from,
                '%to%' => $to,
            ]),
            rationale: $reason !== '' ? $reason : $this->text->t($tenant, 'suggestions.text.record_transition.no_reason'),
            payload: ['workflow' => $workflow, 'transition' => $name, 'from' => $from, 'to' => $to],
            dedupKey: sprintf('transition:%s:%d:%s:%s', $record::class, (int) (method_exists($record, 'getId') ? $record->getId() : 0), $name, $from),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $class = (string) $suggestion->getSubjectClass();
        $workflow = EntityTypeRegistry::workflowOfClass($class);
        if (!in_array($class, $this->subjectClasses(), true) || $workflow === null) {
            throw new StaleSuggestionException('record_gone');
        }
        $record = $this->entityManager->find($class, (int) $suggestion->getSubjectId());
        if ($record === null || !method_exists($record, 'getTenant') || $record->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('record_gone');
        }
        $payload = $suggestion->getPayload();
        if ((string) $this->statusOf($record) !== (string) ($payload['from'] ?? '')) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $transition = (string) ($payload['transition'] ?? '');
        if (!$this->workflows->get($record, $workflow)->can($record, $transition)) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        // Roles, reason and four-eyes of the transition are checked for the person who accepts.
        $this->lifecycle->transition($record, $workflow, $transition, $by, $suggestion->getRationale());

        return $record;
    }

    /**
     * Transitions of the record the asking person may perform that need no second person.
     *
     * @return array<string, array{to: string, reason_required: bool}>
     */
    private function transitionsOf(object $record): array
    {
        $workflow = EntityTypeRegistry::workflowOfClass($record::class);
        $tenant = method_exists($record, 'getTenant') ? $record->getTenant() : null;
        if ($workflow === null || $workflow === self::APPROVAL_CHAIN || !$tenant instanceof Tenant || !method_exists($record, 'getStatus')) {
            return [];
        }
        $module = EntityTypeRegistry::moduleOfClass($record::class);
        if ($module !== null && !$this->modules->isModuleActiveForTenant($module, $tenant)) {
            return [];
        }

        $transitions = [];
        foreach ($this->workflows->get($record, $workflow)->getEnabledTransitions($record) as $transition) {
            $name = $transition->getName();
            if (!$this->security->isGranted(sprintf('lifecycle.%s.%s', $workflow, $name), $record)) {
                continue;
            }
            $effective = $this->resolver->resolve($record, $workflow, $name);
            if (($effective['four_eyes'] ?? false) === true) {
                continue;
            }
            $transitions[$name] = [
                'to' => (string) ($transition->getTos()[0] ?? ''),
                'reason_required' => (bool) ($effective['reason_required'] ?? false),
            ];
        }

        return $transitions;
    }

    private function statusOf(object $record): mixed
    {
        $status = method_exists($record, 'getStatus') ? $record->getStatus() : null;

        return $status instanceof \BackedEnum ? $status->value : $status;
    }

    private static function labelOf(object $record): string
    {
        foreach (['getTitle', 'getName', 'getOriginalFilename', 'getIncidentNumber'] as $getter) {
            if (method_exists($record, $getter) && is_string($value = $record->{$getter}()) && $value !== '') {
                return mb_substr($value, 0, 120);
            }
        }

        return (new \ReflectionClass($record))->getShortName() . ' #' . (method_exists($record, 'getId') ? (int) $record->getId() : 0);
    }
}
