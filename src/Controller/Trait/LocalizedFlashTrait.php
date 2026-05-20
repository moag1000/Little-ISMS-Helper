<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Localized flash-message helper for controllers.
 *
 * Replaces the raw `$this->addFlash($key, $this->translator->trans('XXX.success.created'))` // @todo H-06 flash-domain
 * pattern that silently falls back to the `messages` translation-domain when no
 * domain parameter is passed. Because most domain keys live in dedicated
 * `<domain>.<locale>.yaml` files, the fallback resolves to the literal key and
 * users see raw translation-IDs in the UI.
 *
 * Each consuming controller declares its flash-domain once via
 * {@see self::getFlashDomain()} and the trait routes all flash calls through
 * the {@see TranslatorInterface} bound to that domain.
 *
 * Usage:
 *
 *     class AssetController extends AbstractController
 *     {
 *         use LocalizedFlashTrait;
 *
 *         public function __construct(
 *             private readonly TranslatorInterface $translator,
 *         ) {}
 *
 *         protected function getFlashDomain(): string { return 'asset'; }
 *
 *         protected function getTranslator(): TranslatorInterface { return $this->translator; }
 *
 *         public function new(): Response
 *         {
 *             // ...
 *             $this->flashSuccess('asset.success.created');
 *         }
 *     }
 *
 * Foundation-Pattern P-5 — see var/junior-isb-audit/SOLUTIONS_FOUNDATION.md.
 */
trait LocalizedFlashTrait
{
    /**
     * Return the translation-domain that holds the flash-message keys for this
     * controller (e.g. `asset`, `risk`, `interested_party`).
     */
    abstract protected function getFlashDomain(): string;

    /**
     * Return the {@see TranslatorInterface} the controller already has injected
     * (usually `$this->translator`).
     */
    abstract protected function getTranslator(): TranslatorInterface;

    /**
     * Add a translated `success`-flash. `$key` is resolved against the
     * controller-declared flash-domain.
     *
     * @param array<string, mixed> $params Optional ICU placeholders.
     */
    protected function flashSuccess(string $key, array $params = []): void
    {
        $this->addFlash('success', $this->getTranslator()->trans($key, $params, $this->getFlashDomain()));
    }

    /**
     * Add a translated `error`-flash.
     *
     * @param array<string, mixed> $params Optional ICU placeholders.
     */
    protected function flashError(string $key, array $params = []): void
    {
        $this->addFlash('error', $this->getTranslator()->trans($key, $params, $this->getFlashDomain()));
    }

    /**
     * Add a translated `warning`-flash.
     *
     * @param array<string, mixed> $params Optional ICU placeholders.
     */
    protected function flashWarning(string $key, array $params = []): void
    {
        $this->addFlash('warning', $this->getTranslator()->trans($key, $params, $this->getFlashDomain()));
    }

    /**
     * Add a translated `info`-flash.
     *
     * @param array<string, mixed> $params Optional ICU placeholders.
     */
    protected function flashInfo(string $key, array $params = []): void
    {
        $this->addFlash('info', $this->getTranslator()->trans($key, $params, $this->getFlashDomain()));
    }
}
