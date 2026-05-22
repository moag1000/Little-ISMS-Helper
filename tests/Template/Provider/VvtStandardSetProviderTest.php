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
    public function emitsSixStandardVvtsPerLanguage(): void
    {
        $provider = new VvtStandardSetProvider();
        $templates = iterator_to_array($provider->provide());

        // 6 VVTs × 2 languages = 12 templates (recruiting, payroll, customer_master,
        // marketing_newsletter, contract_partner, hr_personnel_file — M-05 added the last)
        $this->assertCount(12, $templates);
    }

    #[Test]
    public function hrPersonnelFileTemplateIsExposed(): void
    {
        $provider = new VvtStandardSetProvider();
        $de = null;
        $en = null;
        foreach ($provider->provide() as $template) {
            if ($template->key === 'vvt.standard.hr_personnel_file.de') {
                $de = $template;
            } elseif ($template->key === 'vvt.standard.hr_personnel_file.en') {
                $en = $template;
            }
        }
        $this->assertNotNull($de, 'HR-Personalakte DE template missing');
        $this->assertNotNull($en, 'HR-Personalakte EN template missing');
        $this->assertSame('HR-Personalakte', $de->name);
        $this->assertSame('contract', $de->prefill['legalBasis']);
        $this->assertContains('employees', $de->prefill['dataSubjectCategories']);
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
