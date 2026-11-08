<?php

declare(strict_types=1);

namespace App\Mcp;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Prompts the assistant offers as ready-made starts (Claude: "/" menu or
 * "+" > prompts), one per persona task that the tools answer well. Texts in
 * the `mcp` domain, in the caller's language.
 */
class McpPrompts
{
    /** name => argument names (all optional) */
    private const array PROMPTS = [
        'my_day_briefing' => [],
        'audit_readiness' => ['framework'],
        'management_briefing' => [],
        'draft_from_record' => ['resource', 'id'],
    ];

    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    /** @return list<array<string, mixed>> */
    public function list(McpSession $session): array
    {
        $list = [];
        foreach (self::PROMPTS as $name => $arguments) {
            $list[] = [
                'name' => $name,
                'title' => $this->t("mcp.prompt.$name.title", $session),
                'description' => $this->t("mcp.prompt.$name.description", $session),
                'arguments' => array_map(fn (string $arg): array => [
                    'name' => $arg,
                    'description' => $this->t("mcp.prompt_argument.$arg", $session),
                    'required' => $name === 'draft_from_record',
                ], $arguments),
            ];
        }

        return $list;
    }

    /**
     * @param array<mixed> $arguments
     * @return array<string, mixed>
     */
    public function get(string $name, array $arguments, McpSession $session): array
    {
        if (!array_key_exists($name, self::PROMPTS)) {
            throw new McpProtocolException('Unknown prompt: ' . $name, -32602);
        }
        $params = [];
        foreach (self::PROMPTS[$name] as $arg) {
            $value = $arguments[$arg] ?? null;
            $params['%' . $arg . '%'] = is_scalar($value) && (string) $value !== '' ? mb_substr((string) $value, 0, 100) : '';
        }
        if ($name === 'audit_readiness' && $params['%framework%'] === '') {
            $params['%framework%'] = 'ISO27001';
        }

        return [
            'description' => $this->t("mcp.prompt.$name.description", $session),
            'messages' => [[
                'role' => 'user',
                'content' => ['type' => 'text', 'text' => $this->t("mcp.prompt.$name.text", $session, $params)],
            ]],
        ];
    }

    /** Server instructions sent with initialize: how the tools fit together. */
    public function instructions(McpSession $session): string
    {
        return $this->t('mcp.instructions', $session, ['%organisation%' => (string) $session->tenant->getName()]);
    }

    /** @param array<string, string> $params */
    private function t(string $key, McpSession $session, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'mcp', $session->locale);
    }
}
