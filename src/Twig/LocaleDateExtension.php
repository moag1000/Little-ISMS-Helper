<?php

declare(strict_types=1);

namespace App\Twig;

use DateTimeInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Locale-aware date formatting for consistent display across DE/EN.
 *
 * Usage in templates:
 *   {{ entity.createdAt|locale_date }}           → "29.04.2026" (DE) / "2026-04-29" (EN)
 *   {{ entity.createdAt|locale_datetime }}        → "29.04.2026 09:15" (DE) / "2026-04-29 09:15" (EN)
 */
class LocaleDateExtension extends AbstractExtension
{
    private const array DATE_FORMATS = [
        'de' => 'd.m.Y',
        'en' => 'Y-m-d',
    ];

    private const array DATETIME_FORMATS = [
        'de' => 'd.m.Y H:i',
        'en' => 'Y-m-d H:i',
    ];

    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('locale_date', $this->formatDate(...)),
            new TwigFilter('locale_datetime', $this->formatDateTime(...)),
        ];
    }

    public function formatDate(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        $locale = $this->getLocale();
        $format = self::DATE_FORMATS[$locale] ?? self::DATE_FORMATS['en'];

        return $date->format($format);
    }

    public function formatDateTime(?DateTimeInterface $date): string
    {
        if ($date === null) {
            return '-';
        }

        $locale = $this->getLocale();
        $format = self::DATETIME_FORMATS[$locale] ?? self::DATETIME_FORMATS['en'];

        return $date->format($format);
    }

    private function getLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        if ($request === null) {
            return 'en';
        }

        $locale = $request->getLocale();

        // Normalize: "de_DE" → "de", "en_US" → "en"
        return substr($locale, 0, 2);
    }
}
