<?php

declare(strict_types=1);

namespace App\Controller\Api;

use App\AlvaHint\AlvaFormHint;
use App\Service\AlvaHint\AlvaHintFormEvaluator;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * P-19 — Form-Step-Inline-Hint API endpoint.
 *
 * Receives a normalized form-payload snapshot from the
 * `alva-hint-in-form` Stimulus controller, runs every applicable
 * {@see \App\AlvaHint\AlvaHintFormRuleInterface} via
 * {@see AlvaHintFormEvaluator}, and returns a list of inline hints —
 * already translated — ready for the Stimulus controller to render below
 * the anchor field via a pre-rendered Aurora alert.
 *
 * Whitelist of supported entity-type slugs is hardcoded here (not in the
 * registry) so unknown slugs short-circuit before any rule iteration —
 * mirrors the QuickCreateController pattern.
 *
 * Tenant-scoping is implicit: the evaluator pulls the active modules via
 * `ModuleConfigurationService` (which honours the current tenant) and
 * gates each rule against them. No explicit tenant check is needed for
 * the read-only endpoint.
 */
#[Route('/api/alva-hint/form', name: 'api_alva_hint_form_', methods: ['POST'])]
#[IsGranted('ROLE_USER')]
final class AlvaHintFormController extends AbstractController
{
    /**
     * Whitelisted entity-type slugs. Adding a new entity = add one line
     * here AND ship at least one {@see \App\AlvaHint\AlvaHintFormRuleInterface}
     * tagged with the same slug — the endpoint will return an empty list
     * (HTTP 200) for whitelisted entities without rules, which is the
     * "no hints at the moment" success case.
     */
    private const array SUPPORTED_ENTITY_TYPES = [
        'incident',
        'risk',
    ];

    public function __construct(
        private readonly AlvaHintFormEvaluator $evaluator,
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * POST /api/alva-hint/form/{entityType}
     *
     * Body: { "payload": { "fieldA": ..., "fieldB": ... }, "_token": "..." }
     * Returns: 200 { "ok": true, "hints": [ {key, field, tier, title, body, mood, action?}, ... ] }
     *      or 400 { "ok": false, "error": "Unknown entity type: ..." }
     *      or 419 { "ok": false, "error": "Invalid CSRF token." }
     */
    #[Route('/{entityType}', name: 'evaluate', methods: ['POST'])]
    public function evaluate(string $entityType, Request $request): JsonResponse
    {
        if (!in_array($entityType, self::SUPPORTED_ENTITY_TYPES, true)) {
            return $this->json([
                'ok' => false,
                'error' => 'Unknown entity type: ' . $entityType,
            ], 400);
        }

        $body = json_decode((string) $request->getContent(), true);
        if (!is_array($body)) {
            $body = $request->request->all();
        }

        $token = is_array($body) ? (string) ($body['_token'] ?? '') : '';
        if (!$this->isCsrfTokenValid('alva_hint_form', $token)) {
            return $this->json([
                'ok' => false,
                'error' => 'Invalid CSRF token.',
            ], 419);
        }

        /** @var array<string, mixed> $payload */
        $payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

        $hints = $this->evaluator->evaluate($entityType, $payload);

        return $this->json([
            'ok' => true,
            'hints' => array_map(
                fn(AlvaFormHint $h): array => $this->serializeHint($h),
                $hints,
            ),
        ]);
    }

    /**
     * Server-side translate the title/body/action-label so the Stimulus
     * controller can render plain strings — keeps the client free of any
     * Symfony-translator wiring and ensures the locale matches the
     * surrounding form chrome.
     *
     * @return array{
     *     key: string,
     *     field: string,
     *     tier: string,
     *     title: string,
     *     body: string,
     *     mood: string,
     *     action: array{label: string, url: string, method: string}|null
     * }
     */
    private function serializeHint(AlvaFormHint $hint): array
    {
        $action = null;
        if ($hint->action !== null) {
            try {
                $url = $this->generateUrl($hint->action['route'], $hint->action['params']);
            } catch (\Throwable) {
                $url = '';
            }
            if ($url !== '') {
                $action = [
                    'label' => $this->translator->trans(
                        $hint->action['label'],
                        [],
                        $hint->translationDomain,
                    ),
                    'url' => $url,
                    'method' => $hint->action['method'],
                ];
            }
        }

        return [
            'key' => $hint->key,
            'field' => $hint->field,
            'tier' => $hint->tier,
            'title' => $this->translator->trans(
                $hint->titleTranslationKey,
                [],
                $hint->translationDomain,
            ),
            'body' => $this->translator->trans(
                $hint->bodyTranslationKey,
                $hint->bodyParams,
                $hint->translationDomain,
            ),
            'mood' => $hint->mood,
            'action' => $action,
        ];
    }
}
