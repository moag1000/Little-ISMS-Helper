<?php

declare(strict_types=1);

namespace App\Service\MyDay;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\AlvaHintService;
use App\Entity\Risk;
use App\Entity\User;
use App\Service\Location\LocationMyDayTasks;
use App\Service\Risk\RiskBusinessSummary;
use App\Service\WorkflowService;
use App\Security\Voter\RiskAcceptanceVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Makes the My Day payload readable for people who only come to decide.
 *
 * - Approvals name the record ("Risiko: Kundendaten Online-Shop"), not the
 *   workflow row ("Risk #12 — Risk Treatment"); the workflow name moves to
 *   the subtitle. One query per record type.
 * - Four-eyes requests show their translated action.
 * - Status badges are translated instead of showing raw codes.
 * - Alva's priority-1 hints join the inbox as their own category, so the
 *   separate Alva inbox is no longer a second place to look.
 * - Requested risk acceptances (deadline source RiskAcceptanceRequestSource)
 *   carry a business line (band, €/year, asset) and are decided in place by
 *   who may approve them; risk approvals carry the same business line.
 * - Own sites with open checks join as their own category (location map).
 */
readonly class MyDayInboxEnricher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlvaHintService $alvaHints,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
        private AuthorizationCheckerInterface $authorization,
        private RiskBusinessSummary $riskSummary,
        private LocationMyDayTasks $locationTasks,
    ) {
    }

    /**
     * @param array<string, mixed> $inbox MyDayAggregator::aggregate() payload
     * @return array<string, mixed>
     */
    public function enrich(array $inbox, ?User $user = null): array
    {
        $labels = $this->subjectLabels(array_merge($inbox['workflows_pending'] ?? [], $inbox['workflows_overdue'] ?? []));
        foreach (['workflows_pending', 'workflows_overdue'] as $key) {
            $inbox[$key] = array_map(fn (array $item): array => $this->labelWorkflow($item, $labels), $inbox[$key] ?? []);
        }
        $inbox['four_eyes'] = array_map(fn (array $item): array => [
            'title' => $this->translator->trans('four_eyes.action.' . $item['title'], [], 'four_eyes'),
        ] + $item, $inbox['four_eyes'] ?? []);

        $inbox['alva_hints'] = $this->alvaItems();
        $inbox['risk_acceptance_pending'] = array_map(fn (array $item): array => $this->acceptanceItem($item), $inbox['risk_acceptance_pending'] ?? []);
        $inbox['location_gaps'] = $user !== null ? $this->locationTasks->openGapsFor($user) : [];
        $inbox['total'] = ($inbox['total'] ?? 0) + count($inbox['alva_hints']) + count($inbox['location_gaps']);

        foreach ($inbox as $key => $items) {
            if (is_array($items) && array_is_list($items)) {
                $inbox[$key] = array_map(fn ($item) => is_array($item) ? $this->translateBadge($item) : $item, $items);
            }
        }

        return $inbox;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, array{label: string, business?: string}> "<class>#<id>" => label
     */
    private function subjectLabels(array $items): array
    {
        $idsByClass = [];
        foreach ($items as $item) {
            $class = WorkflowService::subjectClass($item['decision']['subject_type'] ?? null);
            $id = $item['decision']['subject_id'] ?? null;
            if ($class !== null && $id !== null && $this->entityManager->getMetadataFactory()->hasMetadataFor($class)) {
                $idsByClass[$class][] = $id;
            }
        }

        $labels = [];
        foreach ($idsByClass as $class => $ids) {
            foreach ($this->entityManager->getRepository($class)->findBy(['id' => array_unique($ids)]) as $entity) {
                $label = $this->labelOf($entity);
                if ($label !== null && method_exists($entity, 'getId')) {
                    $labels[$class . '#' . $entity->getId()] = $entity instanceof Risk
                        ? ['label' => $label, 'business' => $this->riskSummary->line($entity)]
                        : ['label' => $label];
                }
            }
        }

        return $labels;
    }

    private function labelOf(object $entity): ?string
    {
        foreach (['getTitle', 'getName'] as $getter) {
            if (method_exists($entity, $getter) && is_string($value = $entity->$getter()) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $entity instanceof \Stringable ? (string) $entity : null;
    }

    /**
     * @param array<string, mixed>  $item
     * @param array<string, array{label: string, business?: string}> $labels
     * @return array<string, mixed>
     */
    private function labelWorkflow(array $item, array $labels): array
    {
        $class = WorkflowService::subjectClass($item['decision']['subject_type'] ?? null);
        $subject = $class !== null ? ($labels[$class . '#' . ($item['decision']['subject_id'] ?? '')] ?? null) : null;
        if ($subject === null) {
            return $item;
        }
        $label = $subject['label'];
        $type = $this->translator->trans('my_day.subject.' . substr((string) strrchr('\\' . $class, '\\'), 1), [], 'my_day');
        $item['title'] = str_starts_with($type, 'my_day.subject.') ? $label : $type . ': ' . $label;
        $item['decision']['summary'] = $subject['business'] ?? null; // business line for the decision dialog
        $item['subtitle'] = isset($subject['business'])
            ? $subject['business'] . ' · ' . $item['decision']['name']
            : $item['decision']['name'] . ' · ' . $item['subtitle'];

        return $item;
    }

    /** Number of items enrich() adds (Alva hints, risk acceptances to decide), for the sidebar badge. */
    public function addedCount(User $user): int
    {
        return count($this->priorityHints()) + count($this->locationTasks->openGapsFor($user));
    }

    /**
     * A requested risk acceptance: business line as subtitle; the approve /
     * reject dialog only for who may decide it (RiskAcceptanceVoter) — the
     * fallback recipient without that level just opens the risk.
     *
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function acceptanceItem(array $item): array
    {
        $risk = isset($item['acceptance_risk_id']) ? $this->entityManager->find(Risk::class, $item['acceptance_risk_id']) : null;
        if (!$risk instanceof Risk) {
            return $item;
        }
        $item['subtitle'] = $this->riskSummary->line($risk);
        if (!$this->authorization->isGranted(RiskAcceptanceVoter::APPROVE, $risk)) {
            unset($item['acceptance_risk_id']);
        }

        return $item;
    }

    /**
     * @return list<AlvaHint>
     */
    private function priorityHints(): array
    {
        return array_values(array_filter(
            $this->alvaHints->getAllTenantGlobalHints(),
            static fn (AlvaHint $hint): bool => $hint->priorityTier === 1,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function alvaItems(): array
    {
        $items = [];
        foreach ($this->priorityHints() as $hint) {
            $items[] = [
                'priority' => 'high',
                'due_date' => null,
                'link' => $this->alvaLink($hint),
                'entity_type' => 'alva_hint',
                'tone' => 'warning',
                'title' => $this->translator->trans($hint->titleTranslationKey, $hint->bodyTranslationParams, $hint->translationDomain),
                'subtitle' => $this->translator->trans($hint->bodyTranslationKey, $hint->bodyTranslationParams, $hint->translationDomain),
                'badge' => null,
            ];
        }

        return $items;
    }

    private function alvaLink(AlvaHint $hint): string
    {
        // POST actions need their CSRF form, which the Alva inbox renders.
        if ($hint->actionRoute !== null && $hint->actionMethod === 'GET') {
            return $this->urls->generate($hint->actionRoute, $hint->actionRouteParams);
        }

        return $this->urls->generate('app_alva_hint_inbox');
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function translateBadge(array $item): array
    {
        $badge = $item['badge'] ?? null;
        if (!is_string($badge) || $badge === '') {
            return $item;
        }
        $key = 'my_day.badge_value.' . $badge;
        $translated = $this->translator->trans($key, [], 'my_day');
        $item['badge'] = $translated !== $key ? $translated : ucfirst(str_replace('_', ' ', $badge));

        return $item;
    }
}
