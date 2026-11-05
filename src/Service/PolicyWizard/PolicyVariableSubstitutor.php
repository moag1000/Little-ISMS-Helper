<?php

declare(strict_types=1);

namespace App\Service\PolicyWizard;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Replaces `{{ var.name }}` markers in generated policy prose (bodies, extension
 * sections, GDPR document sections) with the run's collected variables.
 *
 * A value the organisation has not entered yet stays visible as
 * `[zu ergänzen: var.name]` so reviewers see the gap and the auditor score
 * counts it, instead of a silent hole in the policy text. Booleans render as
 * Ja/Nein.
 */
final class PolicyVariableSubstitutor
{
    /**
     * @param array<string, mixed> $variables
     */
    public static function apply(string $body, array $variables, TranslatorInterface $translator): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/u',
            static function (array $match) use ($variables, $translator): string {
                $value = $variables[$match[1]] ?? null;

                return match (true) {
                    $value === null, $value === '' => $translator->trans(
                        'policy_wizard.generator.unfilled_variable',
                        ['%name%' => $match[1]],
                        'policy_wizard',
                    ),
                    is_bool($value) => $translator->trans($value ? 'common.yes' : 'common.no', [], 'messages'),
                    is_scalar($value) || $value instanceof \Stringable => (string) $value,
                    default => '',
                };
            },
            $body,
        );
    }
}
