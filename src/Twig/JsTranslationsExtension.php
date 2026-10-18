<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Translation\MessageCatalogueInterface;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\LocaleAwareInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Attribute\AsTwigFunction;

/**
 * js_ui_translations() — the JavaScript UI dictionary.
 *
 * Every message under `ui.js.` in the `ui` domain is shipped to the browser
 * (base.html.twig → window.translations.ui) with the prefix stripped, and
 * read there through faT() (assets/js/fa-i18n.js):
 *
 *   translations/ui.de.yaml   ui.js.form_layout.all_filled: '%total% Felder · alle ausgefüllt'
 *   JavaScript                faT('form_layout.all_filled', { '%total%': 12 })
 *
 * Adding a key to both YAML files is all a controller needs — no list to
 * keep in sync in the layout. Messages missing in the current locale fall
 * back to the fallback locale, like |trans does.
 */
final class JsTranslationsExtension
{
    public const string PREFIX = 'ui.js.';
    private const string DOMAIN = 'ui';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    /**
     * @return array<string, string>
     */
    #[AsTwigFunction('js_ui_translations')]
    public function jsUiTranslations(?string $locale = null): array
    {
        if (!$this->translator instanceof TranslatorBagInterface) {
            return [];
        }
        $locale ??= $this->translator instanceof LocaleAwareInterface ? $this->translator->getLocale() : null;

        $messages = [];
        // Fallback locales first, so the requested locale wins.
        $catalogues = [];
        for ($catalogue = $this->translator->getCatalogue($locale); $catalogue instanceof MessageCatalogueInterface; $catalogue = $catalogue->getFallbackCatalogue()) {
            array_unshift($catalogues, $catalogue);
        }
        foreach ($catalogues as $catalogue) {
            foreach ($catalogue->all(self::DOMAIN) as $key => $message) {
                if (str_starts_with((string) $key, self::PREFIX)) {
                    $messages[substr((string) $key, strlen(self::PREFIX))] = (string) $message;
                }
            }
        }
        ksort($messages);

        return $messages;
    }
}
