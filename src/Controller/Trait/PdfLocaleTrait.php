<?php

declare(strict_types=1);

namespace App\Controller\Trait;

use Symfony\Component\HttpFoundation\Request;

/**
 * Resolves the locale for PDF rendering.
 *
 * Honors a ?locale= query parameter if the requested value is one of the
 * supported locales ('de', 'en').  Falls back to the current request locale.
 *
 * Usage:
 *   use PdfLocaleTrait;
 *   // then in a PDF route action:
 *   $locale = $this->resolvePdfLocale($request);
 *   $pdf = $this->localeSwitcher->runWithLocale($locale, fn() => ...);
 */
trait PdfLocaleTrait
{
    /**
     * Resolve the locale for PDF rendering.
     * Honors ?locale= query param if it is one of the supported locales,
     * falls back to the current request locale.
     */
    private function resolvePdfLocale(Request $request): string
    {
        $requested = $request->query->get('locale');
        $supported = ['de', 'en'];

        if ($requested !== null && in_array($requested, $supported, true)) {
            return $requested;
        }

        return $request->getLocale();
    }
}
