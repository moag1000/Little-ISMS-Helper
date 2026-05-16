<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\Supplier;
use App\Template\Provider\CloudProviderTemplateProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CloudProviderTemplateProviderTest extends TestCase
{
    #[Test]
    public function emitsFiveProvidersPerLanguage(): void
    {
        $provider = new CloudProviderTemplateProvider();
        $templates = iterator_to_array($provider->provide());

        // AWS, Azure, GCP, M365, Workspace × 2 languages = 10
        $this->assertCount(10, $templates);
    }

    #[Test]
    public function allTemplatesShipDoraSensibleDefaults(): void
    {
        $provider = new CloudProviderTemplateProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(Supplier::class, $template->entityClass);
            $this->assertSame('suppliers', $template->module);

            $this->assertSame('medium', $template->prefill['criticality']);
            $this->assertTrue($template->prefill['hasISO27001'],
                'Hyperscaler entries must declare ISO 27001');
            $this->assertTrue($template->prefill['hasDPA']);
            $this->assertTrue($template->prefill['isDoraRelevant'],
                'Cloud providers are DORA-relevant by default');
            $this->assertSame('US', $template->prefill['countryOfHeadOffice']);
        }
    }
}
