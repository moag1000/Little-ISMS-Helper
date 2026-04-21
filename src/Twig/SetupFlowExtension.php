<?php

declare(strict_types=1);

namespace App\Twig;

use App\Setup\SetupFlow;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * FairyAurora v3.0 — Setup-Flow Twig-Functions
 *
 * Usage in templates/setup/_layout.html.twig:
 *   {% set step = setup_flow(active_step|default('welcome')) %}
 *   {{ step.mood }} / {{ step.line }} / {{ step.sub }}
 *
 * Plan § 13 Pattern-Source.
 */
class SetupFlowExtension extends AbstractExtension
{
    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('setup_flow', [$this, 'getStep']),
            new TwigFunction('setup_flow_total', [$this, 'total']),
            new TwigFunction('setup_flow_phases', [$this, 'phases']),
        ];
    }

    public function getStep(string $id): ?array
    {
        $request = $this->requestStack->getCurrentRequest();
        $locale = $request !== null ? $request->getLocale() : 'de';
        return SetupFlow::get($id, $locale);
    }

    public function total(): int
    {
        return SetupFlow::total();
    }

    public function phases(): array
    {
        return SetupFlow::phases();
    }
}
