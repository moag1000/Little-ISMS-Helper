<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Locale Subscriber
 *
 * Handles automatic locale detection and persistence across requests.
 * Implements Symfony Best Practice for locale management.
 *
 * Features:
 * - Locale detection from routing parameters (_locale)
 * - Session-based locale persistence
 * - Fallback to configured default locale
 * - Priority-based event listener (runs before default Locale listener)
 *
 * Workflow:
 * 1. Check for explicit _locale in route
 * 2. Store locale in session if found
 * 3. Fall back to session locale if no explicit locale
 * 4. Fall back to default locale as last resort
 */
class LocaleSubscriber implements EventSubscriberInterface
{
    public function __construct(private string $defaultLocale = 'de')
    {
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();

        // Skip subrequests
        if (!$event->isMainRequest()) {
            return;
        }

        // Try to see if the locale has been set as a _locale routing parameter
        if ($locale = $request->attributes->get('_locale')) {
            // Locale in URL → save to session and set
            $request->getSession()->set('_locale', $locale);
            $request->setLocale($locale);
        } else {
            // No locale in URL → use session, browser preference, or default
            $locale = $request->getSession()->get('_locale')
                ?? $request->getPreferredLanguage(['de', 'en'])
                ?? $this->defaultLocale;

            $request->setLocale($locale);
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [
            // Must be registered before the default Locale listener
            KernelEvents::REQUEST => [['onKernelRequest', 20]],
        ];
    }
}
