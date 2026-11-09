<?php

declare(strict_types=1);

namespace App\Service\Job;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * The language an async job writes its output in (W10, I18N-6).
 *
 * At dispatch the locale of the person who started the job goes into the job
 * arguments as `locale`; both runners execute the job under
 * {@see LocaleSwitcher::runWithLocale()}. Without this a Messenger worker ran
 * every export in `app.default_locale`, so an English user got a German VVT
 * or risk register. Twig's `app.locale` (the `<html lang>` of every PDF) and
 * the translator follow the switcher.
 *
 * A job that names its own `locale` (certification bundle: chosen in the
 * form) keeps it; an unknown value leaves the current locale in place.
 */
class JobLocale
{
    /**
     * @param list<string> $supportedLocales
     */
    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly RequestStack $requestStack,
        #[Autowire('%app.supported_locales%')]
        private readonly array $supportedLocales = ['de', 'en'],
    ) {
    }

    /**
     * Adds the dispatching person's locale unless the job names one.
     *
     * @param array<string, mixed> $args
     *
     * @return array<string, mixed>
     */
    public function stamp(array $args): array
    {
        if (is_string($args['locale'] ?? null) && $args['locale'] !== '') {
            return $args;
        }
        $args['locale'] = $this->requestStack->getCurrentRequest()?->getLocale()
            ?? $this->localeSwitcher->getLocale();

        return $args;
    }

    /**
     * Runs $job in the locale named by the job arguments.
     *
     * @template T
     *
     * @param array<string, mixed> $args
     * @param callable(): T        $job
     *
     * @return T
     */
    public function run(array $args, callable $job): mixed
    {
        $locale = $args['locale'] ?? null;
        if (!is_string($locale) || !in_array($locale, $this->supportedLocales, true)) {
            return $job();
        }

        return $this->localeSwitcher->runWithLocale($locale, $job);
    }
}
