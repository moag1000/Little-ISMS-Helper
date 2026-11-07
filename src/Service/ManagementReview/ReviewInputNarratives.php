<?php

declare(strict_types=1);

namespace App\Service\ManagementReview;

use App\Entity\ActionItem;
use App\Entity\Incident;
use App\Entity\IsmsRegisterEntry;
use App\Entity\InterestedParty;
use App\Entity\ISMSContext;
use App\Entity\ISMSObjective;
use App\Entity\ManagementReview;
use App\Entity\Tenant;
use App\Enum\RegisterType;
use App\Repository\ActionItemRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\ISMSContextRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementDecisionRepository;
use App\Service\Register\RegisterChanges;
use DateTimeInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Texts for the management review inputs that come from records rather than
 * from the management report: ISO 27001:2022 9.3.2 a (actions from the
 * previous review), b (context), c (needs of interested parties), d 2
 * (incidents), d 4 (objectives) and e (feedback from interested parties).
 * For b the register entries on issues, legal requirements and scope
 * interfaces that changed since the previous review are listed too.
 *
 * Each method states what the records say since the previous review; an
 * empty period is said as such, so nobody has to fill a placeholder.
 */
final class ReviewInputNarratives
{
    public function __construct(
        private readonly TranslatorInterface $translator,
        private readonly ActionItemRepository $actionItemRepository,
        private readonly ManagementDecisionRepository $decisionRepository,
        private readonly ISMSContextRepository $contextRepository,
        private readonly InterestedPartyRepository $interestedPartyRepository,
        private readonly IncidentRepository $incidentRepository,
        private readonly ISMSObjectiveRepository $objectiveRepository,
        private readonly RegisterChanges $registerChanges,
    ) {
    }

    /** 9.3.2 a — status of actions from the previous review. */
    public function previousActions(?ManagementReview $previous, string $locale): string
    {
        if (!$previous instanceof ManagementReview || $previous->getTenant() === null) {
            return $this->t('a.no_previous', [], $locale);
        }
        $items = $this->actionItemRepository->findForManagementReview($previous->getTenant(), (int) $previous->getId());
        $lines = [$this->t('a.header', [
            '%review%' => (string) $previous->getTitle(),
            '%date%' => $previous->getReviewDate()?->format('d.m.Y') ?? '—',
        ], $locale)];
        foreach ($items as $item) {
            $lines[] = sprintf(
                '- %s — %s%s',
                (string) $item->getTitle(),
                $this->translator->trans('planning.action_item.status.' . $item->getStatus(), [], 'planning', $locale),
                $item->getDueDate() !== null && !in_array($item->getStatus(), [ActionItem::STATUS_DONE, ActionItem::STATUS_DISMISSED], true)
                    ? ' (' . $this->t('a.due', ['%date%' => $item->getDueDate()->format('d.m.Y')], $locale) . ')'
                    : '',
            );
        }
        foreach ($this->decisionRepository->findForReview($previous) as $decision) {
            $lines[] = '- ' . $this->t('a.decision', ['%title%' => (string) $decision->getTitle()], $locale);
        }
        if (count($lines) === 1) {
            $lines[] = $this->t('a.none', [], $locale);
        }

        return implode("\n", $lines);
    }

    /** 9.3.2 b — changes in external and internal issues: the context and the registers behind it. */
    public function contextChanges(Tenant $tenant, ?DateTimeInterface $since, string $locale): string
    {
        $lines = $this->contextLines($tenant, $since, $locale);
        $changes = $this->registerChanges->since(
            $tenant,
            $since,
            RegisterType::ISSUE_EXTERNAL,
            RegisterType::ISSUE_INTERNAL,
            RegisterType::LEGAL_REQUIREMENT,
            RegisterType::SCOPE_INTERFACE,
        );
        if ($changes !== []) {
            $lines[] = '';
            $lines[] = $since !== null
                ? $this->t('b.registers_since', ['%date%' => $since->format('d.m.Y')], $locale)
                : $this->t('b.registers', [], $locale);
            foreach ($changes as $entry) {
                $lines[] = sprintf(
                    '- %s: %s (%s)',
                    $this->translator->trans('register.type.' . $entry->getType()->value . '.title', [], 'register', $locale),
                    (string) $entry->getTitle(),
                    $this->t('b.entry_' . $this->changeKind($entry, $since), [], $locale),
                );
            }
        }

        return implode("\n", $lines);
    }

    /** @return list<string> */
    private function contextLines(Tenant $tenant, ?DateTimeInterface $since, string $locale): array
    {
        $context = $this->contextRepository->getContextForTenant($tenant);
        if (!$context instanceof ISMSContext) {
            return [$this->t('b.no_context', [], $locale)];
        }
        $changedAt = $context->getUpdatedAt();
        if ($since !== null && ($changedAt === null || $changedAt <= $since)) {
            return [$this->t('b.unchanged', ['%date%' => $since->format('d.m.Y')], $locale)];
        }
        $lines = [$this->t('b.changed', ['%date%' => $changedAt?->format('d.m.Y') ?? '—'], $locale)];
        foreach ([
            'b.external' => $context->getExternalIssues(),
            'b.internal' => $context->getInternalIssues(),
        ] as $key => $text) {
            if ($text !== null && trim($text) !== '') {
                $lines[] = '';
                $lines[] = $this->t($key, [], $locale);
                $lines[] = trim($text);
            }
        }

        return $lines;
    }

    /** new, changed or archived since the previous review */
    private function changeKind(IsmsRegisterEntry $entry, ?DateTimeInterface $since): string
    {
        if ($entry->isArchived()) {
            return 'archived';
        }

        return $since === null || $entry->getCreatedAt() > $since ? 'new' : 'changed';
    }

    /** 9.3.2 c — changes in needs and expectations of interested parties. */
    public function interestedPartyChanges(Tenant $tenant, ?DateTimeInterface $since, string $locale): string
    {
        $changed = array_filter(
            $this->interestedPartyRepository->findBy(['tenant' => $tenant]),
            static fn (InterestedParty $p): bool => $since === null
                || ($p->getCreatedAt() !== null && $p->getCreatedAt() > $since)
                || ($p->getUpdatedAt() !== null && $p->getUpdatedAt() > $since),
        );
        if ($changed === []) {
            return $since !== null
                ? $this->t('c.unchanged', ['%date%' => $since->format('d.m.Y')], $locale)
                : $this->t('c.none', [], $locale);
        }
        $lines = [$since !== null
            ? $this->t('c.header_since', ['%date%' => $since->format('d.m.Y')], $locale)
            : $this->t('c.header', [], $locale)];
        foreach ($changed as $party) {
            $new = $since !== null && $party->getCreatedAt() !== null && $party->getCreatedAt() > $since;
            $requirements = trim((string) $party->getRequirements());
            $lines[] = sprintf(
                '- %s%s%s',
                (string) $party->getName(),
                $new ? ' (' . $this->t('c.new', [], $locale) . ')' : '',
                $requirements !== '' ? ': ' . $this->oneLine($requirements) : '',
            );
        }

        return implode("\n", $lines);
    }

    /** 9.3.2 d 2 — incidents since the previous review, by severity. */
    public function incidents(Tenant $tenant, ?DateTimeInterface $since, string $locale): string
    {
        $incidents = array_filter(
            $this->incidentRepository->findByTenant($tenant),
            static fn (Incident $i): bool => $since === null
                || ($i->getDetectedAt() !== null && $i->getDetectedAt() > $since),
        );
        if ($incidents === []) {
            return $this->t('d2.no_incidents', [], $locale);
        }
        $bySeverity = [];
        foreach ($incidents as $incident) {
            $key = $incident->getSeverity()->value;
            $bySeverity[$key] = ($bySeverity[$key] ?? 0) + 1;
        }
        $lines = [$this->t('d2.incidents', ['%count%' => count($incidents)], $locale)];
        foreach ($bySeverity as $severity => $count) {
            $lines[] = sprintf('- %s: %d', $this->translator->trans('incident.severity.' . $severity, [], 'incident', $locale), $count);
        }

        return implode("\n", $lines);
    }

    /** 9.3.2 d 4 — fulfilment of the information security objectives. */
    public function objectives(Tenant $tenant, string $locale): string
    {
        $objectives = $this->objectiveRepository->findBy(['tenant' => $tenant]);
        if ($objectives === []) {
            return $this->t('d4.none', [], $locale);
        }
        $lines = [];
        foreach ($objectives as $objective) {
            $lines[] = sprintf(
                '- %s: %s%s',
                (string) $objective->getTitle(),
                $this->translator->trans('objective.status.' . $objective->getStatus(), [], 'objective', $locale),
                $this->progress($objective, $locale),
            );
        }

        return implode("\n", $lines);
    }

    /** 9.3.2 e — feedback from interested parties. */
    public function interestedPartyFeedback(Tenant $tenant, ?DateTimeInterface $since, string $locale): string
    {
        $lines = [];
        foreach ($this->interestedPartyRepository->findBy(['tenant' => $tenant]) as $party) {
            $feedback = trim((string) $party->getFeedback());
            $issues = trim((string) $party->getIssues());
            if ($feedback === '' && $issues === '' && $party->getSatisfactionLevel() === null) {
                continue;
            }
            if ($since !== null && ($party->getUpdatedAt() === null || $party->getUpdatedAt() <= $since)
                && ($party->getCreatedAt() === null || $party->getCreatedAt() <= $since)) {
                continue;
            }
            $parts = array_filter([
                $party->getSatisfactionLevel() !== null
                    ? $this->t('e.satisfaction', ['%level%' => $party->getSatisfactionLevel()], $locale)
                    : null,
                $feedback !== '' ? $this->oneLine($feedback) : null,
                $issues !== '' ? $this->t('e.issues', ['%issues%' => $this->oneLine($issues)], $locale) : null,
            ]);
            $lines[] = sprintf('- %s: %s', (string) $party->getName(), implode(' — ', $parts));
        }

        return $lines === [] ? $this->t('e.none', [], $locale) : implode("\n", $lines);
    }

    private function progress(ISMSObjective $objective, string $locale): string
    {
        $current = trim((string) $objective->getCurrentValue());
        $target = trim((string) $objective->getTargetValue());
        if ($current === '' && $target === '') {
            return '';
        }

        return ' (' . $this->t('d4.progress', [
            '%current%' => $current !== '' ? $current : '—',
            '%target%' => $target !== '' ? $target : '—',
            '%unit%' => (string) $objective->getUnit(),
        ], $locale) . ')';
    }

    private function oneLine(string $text): string
    {
        $text = (string) preg_replace('/\s+/', ' ', $text);

        return mb_strlen($text) > 200 ? mb_substr($text, 0, 199) . '…' : $text;
    }

    /** @param array<string, int|string> $params */
    private function t(string $key, array $params, string $locale): string
    {
        return $this->translator->trans('management_review.input.' . $key, $params, 'management_review', $locale);
    }
}
