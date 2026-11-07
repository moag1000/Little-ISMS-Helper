<?php

declare(strict_types=1);

namespace App\Service\Suggestion;

use App\Entity\Tenant;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Suggestion texts are written once, at generation time, in the
 * organisation's language (Tenant.locale) — like any other record text.
 * Keys live in the `suggestions` domain.
 */
final readonly class SuggestionText
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** @param array<string, string|int|float> $params */
    public function t(Tenant $tenant, string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params, 'suggestions', self::locale($tenant));
    }

    public static function locale(Tenant $tenant): string
    {
        $locale = strtolower(substr((string) ($tenant->getLocale() ?? 'de'), 0, 2));

        return in_array($locale, ['de', 'en'], true) ? $locale : 'de';
    }
}
