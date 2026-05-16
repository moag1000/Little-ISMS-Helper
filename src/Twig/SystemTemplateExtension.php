<?php

declare(strict_types=1);

namespace App\Twig;

use App\Template\SystemTemplate;
use App\Template\SystemTemplateRegistry;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Attribute\AsTwigFunction;

/**
 * Twig bindings for SystemTemplateRegistry (Foundation P-14).
 *
 *  - system_templates(entityClass)   → list<SystemTemplate>
 *  - system_template_count(entityClass) → int (cheap check for empty-state CTAs)
 *
 * Templates are auto-filtered against the current tenant's active modules
 * via `SystemTemplateRegistry::findActive()`. Language is taken from the
 * current request locale.
 */
class SystemTemplateExtension
{
    public function __construct(
        private readonly SystemTemplateRegistry $registry,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @return list<SystemTemplate>
     */
    #[AsTwigFunction('system_templates')]
    public function systemTemplates(string $entityClass): array
    {
        return $this->registry->findActive($entityClass, $this->currentLanguage());
    }

    #[AsTwigFunction('system_template_count')]
    public function systemTemplateCount(string $entityClass): int
    {
        return count($this->systemTemplates($entityClass));
    }

    private function currentLanguage(): string
    {
        $request = $this->requestStack->getCurrentRequest();
        return $request?->getLocale() ?? 'de';
    }
}
