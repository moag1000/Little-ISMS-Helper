<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\Risk;
use App\Template\Provider\Iso27005ThreatCatalogProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class Iso27005ThreatCatalogProviderTest extends TestCase
{
    #[Test]
    public function emitsOneTemplatePerLanguage(): void
    {
        $provider = new Iso27005ThreatCatalogProvider();
        $templates = iterator_to_array($provider->provide());

        $this->assertCount(2, $templates);
        $languages = array_map(fn ($t) => $t->language, $templates);
        $this->assertEqualsCanonicalizing(['de', 'en'], $languages);
    }

    #[Test]
    public function eachTemplateContainsAtLeastThirtyThreats(): void
    {
        $provider = new Iso27005ThreatCatalogProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(Risk::class, $template->entityClass);
            $this->assertSame('risks', $template->module);
            $this->assertNotNull($template->items, sprintf('Template %s must be bulk-mode', $template->key));
            $this->assertGreaterThanOrEqual(28, count($template->items),
                'ISO 27005 catalog should ship ~30 threats');
        }
    }

    #[Test]
    public function everyItemHasMandatoryFields(): void
    {
        $provider = new Iso27005ThreatCatalogProvider();
        foreach ($provider->provide() as $template) {
            foreach ($template->items as $i => $item) {
                foreach (['title', 'category', 'threat', 'description'] as $field) {
                    $this->assertArrayHasKey($field, $item,
                        sprintf('%s item %d missing field %s', $template->key, $i, $field));
                    $this->assertNotEmpty($item[$field]);
                }
            }
        }
    }

    #[Test]
    public function prefillIncludesConservativeProbabilityAndImpact(): void
    {
        $provider = new Iso27005ThreatCatalogProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(2, $template->prefill['probability']);
            $this->assertSame(3, $template->prefill['impact']);
        }
    }
}
