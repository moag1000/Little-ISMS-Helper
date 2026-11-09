<?php

declare(strict_types=1);

namespace App\Service\Suggestion\Kind;

use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Form\BusinessContinuityPlanType;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Suggestion\SectionedText;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionText;
use Doctrine\ORM\EntityManagerInterface;
use App\Controller\BusinessContinuityPlanController;
use App\Service\Suggestion\TargetAccess;

/**
 * BCM officer (ISO 22301 8.4): a business process with a business impact
 * analysis but no continuity plan → the model drafts activation criteria,
 * recovery procedures and communication from the BIA. Accepting creates the
 * plan in status "draft", linked to the process; RTO and RPO come from the
 * BIA like on the plan form. Owner, team and approval stay with the person.
 */
final class AiBcPlanFromBiaKind extends AbstractAiKind
{
    public const string KEY = 'ai.bc_plan_from_bia';

    /** Plan field => translation key of its block heading. */
    private const array FIELDS = [
        'activationCriteria' => 'suggestions.text.ai_bc_plan.activation',
        'recoveryProcedures' => 'suggestions.text.ai_bc_plan.recovery',
        'communicationPlan' => 'suggestions.text.ai_bc_plan.communication',
    ];

    public function __construct(
        AiAssistant $ai,
        SuggestionRepository $suggestions,
        private readonly BusinessProcessRepository $processes,
        private readonly BusinessContinuityPlanRepository $plans,
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
        return TargetAccess::action(BusinessContinuityPlanController::class, 'new');
    }

    public function decisionRoles(): array
    {
        return ['ROLE_BCM_OFFICER'];
    }

    public function requiredModule(): string
    {
        return 'bcm';
    }

    public function subjectClasses(): array
    {
        return [BusinessProcess::class];
    }

    protected function candidates(Tenant $tenant): iterable
    {
        foreach ($this->processes->findByTenant($tenant) as $process) {
            if ($this->isCandidate($process)) {
                yield $process;
            }
        }
    }

    protected function isCandidate(object $record): bool
    {
        return $record instanceof BusinessProcess
            && trim((string) $record->getName()) !== ''
            && ($record->getRto() !== null || $record->getMtpd() !== null || $record->getCriticality() !== null)
            && $this->plans->count(['businessProcess' => $record]) === 0;
    }

    protected function instructions(): string
    {
        return 'Draft a business continuity plan (ISO/IEC 22301 8.4) for this business process from its business '
            . 'impact analysis: "activation" = when the plan is activated (criteria tied to the MTPD/RTO, 2-4 sentences), '
            . '"recovery" = numbered recovery steps in the order of the dependencies, each with the role that acts '
            . '(5-10 lines), "communication" = who is informed internally and externally, when and how (3-5 sentences). '
            . 'Use only the given facts, no invented names or phone numbers. Return keys "activation", "recovery", "communication".';
    }

    protected function describe(object $record): string
    {
        \assert($record instanceof BusinessProcess);
        $assets = array_map(
            static fn ($a): string => (string) $a->getName(),
            array_slice($record->getSupportingAssets()->toArray(), 0, 10),
        );

        return implode("\n", array_filter([
            'Process: ' . $record->getName(),
            $record->getDescription() ? 'Description: ' . $record->getDescription() : null,
            $record->getCriticality() ? 'Criticality: ' . $record->getCriticality() : null,
            $record->getRto() !== null ? 'RTO: ' . $record->getRto() . ' hours' : null,
            $record->getRpo() !== null ? 'RPO: ' . $record->getRpo() . ' hours' : null,
            $record->getMtpd() !== null ? 'MTPD: ' . $record->getMtpd() . ' hours' : null,
            $record->getMbco() ? 'Minimum business continuity objective: ' . $record->getMbco() : null,
            $record->getRecoveryStrategy() ? 'Recovery strategy: ' . $record->getRecoveryStrategy() : null,
            $record->getDependenciesUpstream() ? 'Depends on: ' . $record->getDependenciesUpstream() : null,
            $record->getDependenciesDownstream() ? 'Needed by: ' . $record->getDependenciesDownstream() : null,
            $assets !== [] ? 'Supporting assets: ' . implode(', ', $assets) : null,
        ]));
    }

    protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
    {
        \assert($record instanceof BusinessProcess);
        $answers = ['activationCriteria' => 'activation', 'recoveryProcedures' => 'recovery', 'communicationPlan' => 'communication'];
        $fields = [];
        $sections = [];
        foreach ($answers as $field => $key) {
            $value = self::text($data, $key);
            if ($value !== '') {
                $fields[$field] = $value;
                $sections[$this->text->t($tenant, self::FIELDS[$field])] = $value;
            }
        }
        // The plan form requires activation criteria and recovery procedures.
        if (!isset($fields['activationCriteria'], $fields['recoveryProcedures'])) {
            return null;
        }

        return new SuggestionDraft(
            subject: $record,
            title: $this->text->t($tenant, 'suggestions.text.ai_bc_plan.title', ['%process%' => (string) $record->getName()]),
            rationale: $this->text->t($tenant, 'suggestions.text.ai_bc_plan.rationale', [
                '%rto%' => $record->getRto() !== null ? $record->getRto() . ' h' : '—',
                '%rpo%' => $record->getRpo() !== null ? $record->getRpo() . ' h' : '—',
                '%reasoning%' => self::text($data, 'reasoning', 600),
            ]),
            payload: [
                'fields' => $fields,
                'labels' => array_map(fn (string $k): string => $this->text->t($tenant, $k), self::FIELDS),
            ],
            editableText: SectionedText::compose($sections),
            confidence: self::confidence($data),
            dedupKey: 'process:' . (int) $record->getId(),
            model: $model,
        );
    }

    public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
    {
        $process = $this->entityManager->find(BusinessProcess::class, (int) $suggestion->getSubjectId());
        if (!$process instanceof BusinessProcess || $process->getTenant() !== $suggestion->getTenant()) {
            throw new StaleSuggestionException('process_gone');
        }
        if ($this->plans->count(['businessProcess' => $process]) > 0) {
            throw new StaleSuggestionException('changed_meanwhile');
        }
        $p = $suggestion->getPayload();
        $values = (array) ($p['fields'] ?? []);
        if ($editedText !== null) {
            foreach (SectionedText::parse($editedText, (array) ($p['labels'] ?? [])) as $field => $value) {
                if ($value !== '') {
                    $values[$field] = $value;
                }
            }
        }

        $plan = new BusinessContinuityPlan();
        $plan->setTenant($suggestion->getTenant());
        $plan->setBusinessProcess($process);
        $plan->setName(mb_substr('BC-Plan: ' . $process->getName(), 0, 255));
        $plan->setActivationCriteria((string) ($values['activationCriteria'] ?? ''));
        $plan->setRecoveryProcedures((string) ($values['recoveryProcedures'] ?? ''));
        $plan->setCommunicationPlan(($values['communicationPlan'] ?? '') !== '' ? (string) $values['communicationPlan'] : null);
        BusinessContinuityPlanType::takeTargetsFromBia($plan);
        $this->entityManager->persist($plan);
        $this->entityManager->flush();

        return $plan;
    }
}
