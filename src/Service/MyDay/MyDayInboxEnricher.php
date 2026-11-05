<?php

declare(strict_types=1);

namespace App\Service\MyDay;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\AlvaHintService;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
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
 */
readonly class MyDayInboxEnricher
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private AlvaHintService $alvaHints,
        private TranslatorInterface $translator,
        private UrlGeneratorInterface $urls,
    ) {
    }

    /**
     * @param array<string, mixed> $inbox MyDayAggregator::aggregate() payload
     * @return array<string, mixed>
     */
    public function enrich(array $inbox): array
    {
        $labels = $this->subjectLabels(array_merge($inbox['workflows_pending'] ?? [], $inbox['workflows_overdue'] ?? []));
        foreach (['workflows_pending', 'workflows_overdue'] as $key) {
            $inbox[$key] = array_map(fn (array $item): array => $this->labelWorkflow($item, $labels), $inbox[$key] ?? []);
        }
        $inbox['four_eyes'] = array_map(fn (array $item): array => [
            'title' => $this->translator->trans('four_eyes.action.' . $item['title'], [], 'four_eyes'),
        ] + $item, $inbox['four_eyes'] ?? []);

        $inbox['alva_hints'] = $this->alvaItems();
        $inbox['total'] = ($inbox['total'] ?? 0) + count($inbox['alva_hints']);

        foreach ($inbox as $key => $items) {
            if (is_array($items) && array_is_list($items)) {
                $inbox[$key] = array_map(fn ($item) => is_array($item) ? $this->translateBadge($item) : $item, $items);
            }
        }

        return $inbox;
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, string> "<class>#<id>" => label
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
                    $labels[$class . '#' . $entity->getId()] = $label;
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
     * @param array<string, string> $labels
     * @return array<string, mixed>
     */
    private function labelWorkflow(array $item, array $labels): array
    {
        $class = WorkflowService::subjectClass($item['decision']['subject_type'] ?? null);
        $label = $class !== null ? ($labels[$class . '#' . ($item['decision']['subject_id'] ?? '')] ?? null) : null;
        if ($label === null) {
            return $item;
        }
        $type = $this->translator->trans('my_day.subject.' . substr((string) strrchr('\\' . $class, '\\'), 1), [], 'my_day');
        $item['title'] = str_starts_with($type, 'my_day.subject.') ? $label : $type . ': ' . $label;
        $item['subtitle'] = $item['decision']['name'] . ' · ' . $item['subtitle'];

        return $item;
    }

    /** Number of Alva items enrich() adds, for the sidebar badge. */
    public function alvaHintCount(): int
    {
        return count($this->priorityHints());
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
