<?php

declare(strict_types=1);

namespace App\Twig;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Intl\Countries;
use Twig\Attribute\AsTwigFilter;

/**
 * `{{ 'IE'|country_label }}` → "Irland" in the request locale (Symfony Intl).
 * Unknown codes come back unchanged, so free-text values still render.
 */
final class CountryNameExtension
{
    public function __construct(private readonly RequestStack $requestStack)
    {
    }

    #[AsTwigFilter('country_label')]
    public function countryLabel(?string $code): string
    {
        $code = strtoupper(trim((string) $code));
        if ($code === '' || !Countries::exists($code)) {
            return $code;
        }

        return Countries::getName($code, $this->requestStack->getCurrentRequest()?->getLocale() ?? 'de');
    }
}
