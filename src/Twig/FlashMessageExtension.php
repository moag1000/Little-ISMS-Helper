<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Renders a flash message that a controller stored as a bare translation key
 * (`addFlash('success', 'tenant.flash.created')`) as its translated text.
 *
 * Flashes are written in many controllers; most translate before adding, but
 * well over a hundred call sites store the key itself. The flash renderer in
 * base.html.twig had no translation step, so users saw "tenant.flash.created".
 * `|fa_flash` closes that in one place: a message that looks like a key and
 * exists in a translation domain is translated; any other text (already
 * translated, user data, a URL) is returned unchanged.
 *
 * Domain lookup: the domain named by the key's first segment
 * (`tenant.flash.created` → `tenant`), then `messages`, then every other domain.
 */
final class FlashMessageExtension extends AbstractExtension
{
    private const string KEY_PATTERN = '/^[a-z0-9_]+(\.[a-z0-9_]+)+$/';

    public function __construct(
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function getFilters(): array
    {
        return [new TwigFilter('fa_flash', $this->resolve(...))];
    }

    public function resolve(mixed $message): string
    {
        if (is_iterable($message)) {
            $parts = [];
            foreach ($message as $part) {
                $parts[] = $this->resolve($part);
            }

            return implode(', ', $parts);
        }

        $text = (string) $message;
        if (preg_match(self::KEY_PATTERN, $text) !== 1 || !$this->translator instanceof TranslatorBagInterface) {
            return $text;
        }

        $catalogue = $this->translator->getCatalogue();
        $first = strstr($text, '.', true);
        $domains = array_unique([$first, 'messages', ...$catalogue->getDomains()]);
        foreach ($domains as $domain) {
            if ($catalogue->has($text, $domain)) {
                return $this->translator->trans($text, [], $domain);
            }
        }

        return $text;
    }
}
