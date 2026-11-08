<?php

declare(strict_types=1);

namespace App\Mcp\Tool;

use App\Mcp\McpSession;
use App\Service\Deadline\Obligation;
use App\Service\Deadline\ObligationInbox;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Mein Tag": the caller's open duties with deadlines from every deadline
 * source (reviews, approvals, reporting duties, treatment plans, …) — the
 * same list as My Day and the morning digest.
 */
final class MyDayTool extends AbstractMcpTool
{
    public function __construct(
        private readonly ObligationInbox $inbox,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function name(): string
    {
        return 'my_day';
    }

    public function description(): string
    {
        return 'The caller\'s open duties and decisions with due dates (My Day): soonest first (overdue at the top), undated last. '
            . 'Each item names the kind of duty, the norm clause, whether it is a decision, and links the record.';
    }

    public function inputSchema(McpSession $session): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'only_overdue' => ['type' => 'boolean', 'default' => false],
                'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 200, 'default' => 50],
            ],
        ];
    }

    public function call(array $arguments, McpSession $session): array
    {
        $onlyOverdue = ($arguments['only_overdue'] ?? false) === true;
        $limit = max(1, min(200, (int) self::int($arguments, 'limit', 50)));
        $items = array_values(array_filter(
            $this->inbox->forUser($session->user, $session->tenant),
            static fn (Obligation $o): bool => !$onlyOverdue || $o->overdue,
        ));

        $rows = [];
        foreach (array_slice($items, 0, $limit) as $o) {
            $profile = $o->profile();
            $link = $o->link();
            $rows[] = [
                'title' => $o->title,
                'kind' => $o->source->label(),
                'group' => $this->translator->trans('my_day.section.' . $profile->bucket, [], 'my_day', $session->locale),
                'due' => self::date($o->dueDate),
                'overdue' => $o->overdue,
                'urgent' => $o->urgent,
                'decision' => $profile->decision,
                'norm' => $profile->normReference,
                'url' => $link !== null ? $this->link($link->route, $link->parameters, $session) : null,
            ];
        }

        return [
            'total' => count($items),
            'overdue' => count(array_filter($items, static fn (Obligation $o): bool => $o->overdue)),
            'items' => $rows,
            '_audit' => ['count' => count($rows)],
        ];
    }

    /** @param array<string, scalar> $parameters */
    private function link(string $route, array $parameters, McpSession $session): ?string
    {
        try {
            return $this->urls->generate($route, $parameters + ['_locale' => $session->locale], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable) {
            return null;
        }
    }
}
