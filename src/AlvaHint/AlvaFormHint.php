<?php

declare(strict_types=1);

namespace App\AlvaHint;

/**
 * Immutable inline hint payload returned by AlvaHintFormRuleInterface::evaluate().
 *
 * Separate DTO from {@see AlvaHint} because form-step inline hints have
 * different presentation semantics:
 *
 * - They attach to a specific form field (anchor point) — `field` carries
 *   the form-field name the Stimulus controller will mount the hint
 *   adjacent to.
 * - They are short-lived (one form interaction) and never dismissed
 *   server-side — dismiss tracking would be noise: the next time the user
 *   touches the same field the hint should re-evaluate from scratch.
 * - They never carry priorityTier-1 hard-deadline semantics: a form hint
 *   fires BEFORE save, so by definition no SLA can have started yet.
 *   Therefore `tier` here is a soft enum (info / warning / success) used
 *   purely for visual variant selection in the Aurora component.
 *
 * Translation keys are resolved client-side via the standard Symfony i18n
 * route (the JSON endpoint returns the raw key + params and lets the
 * Stimulus controller hand them to a small Twig-rendered fragment, or
 * pre-renders server-side — see AlvaHintFormController for the server
 * decision).
 *
 * @phpstan-type FormHintPayload array{
 *     key: string,
 *     field: string,
 *     tier: 'info'|'warning'|'success',
 *     title: string,
 *     body: string,
 *     translationDomain: string,
 *     bodyParams: array<string, scalar>,
 *     action?: array{route: string, params: array<string, mixed>, label: string, method: 'GET'|'POST'},
 *     mood?: string
 * }
 */
final readonly class AlvaFormHint
{
    /**
     * @param array<string, scalar>                                                                         $bodyParams
     * @param array{route: string, params: array<string, mixed>, label: string, method: 'GET'|'POST'}|null  $action
     */
    public function __construct(
        public string $key,
        public string $field,
        public string $tier,
        public string $titleTranslationKey,
        public string $bodyTranslationKey,
        public string $translationDomain = 'alva',
        public array $bodyParams = [],
        public ?array $action = null,
        public string $mood = 'thinking',
    ) {
        if (!in_array($tier, ['info', 'warning', 'success'], true)) {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException(
                'AlvaFormHint tier must be one of: info, warning, success — got: ' . $tier,
            );
        }
        if ($key === '') {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException('AlvaFormHint key must not be empty.');
        }
        if ($field === '') {
            throw new \App\Exception\InvalidArgument\InvalidArgumentException('AlvaFormHint field must not be empty.');
        }
    }

    /**
     * @return array{
     *     key: string,
     *     field: string,
     *     tier: string,
     *     title: string,
     *     body: string,
     *     translationDomain: string,
     *     bodyParams: array<string, scalar>,
     *     action: array{route: string, params: array<string, mixed>, label: string, method: string}|null,
     *     mood: string,
     * }
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'field' => $this->field,
            'tier' => $this->tier,
            'title' => $this->titleTranslationKey,
            'body' => $this->bodyTranslationKey,
            'translationDomain' => $this->translationDomain,
            'bodyParams' => $this->bodyParams,
            'action' => $this->action,
            'mood' => $this->mood,
        ];
    }
}
