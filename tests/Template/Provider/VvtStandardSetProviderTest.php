<?php

declare(strict_types=1);

namespace App\Tests\Template\Provider;

use App\Entity\ProcessingActivity;
use App\Template\Provider\VvtStandardSetProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class VvtStandardSetProviderTest extends TestCase
{
    #[Test]
    public function emitsFiveStandardVvtsPerLanguage(): void
    {
        $provider = new VvtStandardSetProvider();
        $templates = iterator_to_array($provider->provide());

        // 5 VVTs × 2 languages = 10 templates
        $this->assertCount(10, $templates);
    }

    #[Test]
    public function allTemplatesAreProcessingActivityEntities(): void
    {
        $provider = new VvtStandardSetProvider();
        foreach ($provider->provide() as $template) {
            $this->assertSame(ProcessingActivity::class, $template->entityClass);
            $this->assertSame('privacy', $template->module);
        }
    }

    #[Test]
    public function prefillCarriesGdprMandatoryFields(): void
    {
        $provider = new VvtStandardSetProvider();
        foreach ($provider->provide() as $template) {
            $this->assertArrayHasKey('purposes', $template->prefill);
            $this->assertArrayHasKey('dataSubjectCategories', $template->prefill);
            $this->assertArrayHasKey('personalDataCategories', $template->prefill);
            $this->assertArrayHasKey('legalBasis', $template->prefill);
            $this->assertArrayHasKey('retentionPeriodDays', $template->prefill);
            $this->assertArrayHasKey('technicalOrganizationalMeasures', $template->prefill);
            $this->assertNotEmpty($template->prefill['purposes']);
            $this->assertGreaterThan(0, $template->prefill['retentionPeriodDays']);
        }
    }

    #[Test]
    public function legalBasisIsValidGdprValue(): void
    {
        $valid = ['consent', 'contract', 'legal_obligation', 'vital_interest', 'public_task', 'legitimate_interest', 'pre_contractual'];
        $provider = new VvtStandardSetProvider();
        foreach ($provider->provide() as $template) {
            $this->assertContains($template->prefill['legalBasis'], $valid,
                sprintf('Template %s legal_basis "%s" must be GDPR Art. 6 value',
                    $template->key, $template->prefill['legalBasis']));
        }
    }
}
