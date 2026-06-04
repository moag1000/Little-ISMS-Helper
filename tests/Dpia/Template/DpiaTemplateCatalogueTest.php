<?php

declare(strict_types=1);

namespace App\Tests\Dpia\Template;

use App\Dpia\Template\DpiaTemplateDto;
use App\Dpia\Template\DpiaTemplateCatalogue;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DpiaTemplateCatalogueTest extends TestCase
{
    private DpiaTemplateCatalogue $catalogue;

    protected function setUp(): void
    {
        $this->catalogue = new DpiaTemplateCatalogue();
    }

    #[Test]
    public function returnsExactlyThreeTemplates(): void
    {
        $this->assertCount(3, $this->catalogue->all());
    }

    #[Test]
    public function allTemplatesHaveUniqueKeys(): void
    {
        $keys = array_map(fn (DpiaTemplateDto $t) => $t->key, $this->catalogue->all());
        $this->assertSame(array_unique($keys), $keys);
    }

    #[Test]
    public function healthcareTemplateHasBdsgSect22LegalBasis(): void
    {
        $tpl = $this->catalogue->find('healthcare_bdsg_sect22');
        $this->assertNotNull($tpl);
        $this->assertSame('art9_bdsg22', $tpl->legalBasis);
        $this->assertContains('health', $tpl->dataCategories);
    }

    #[Test]
    public function financialServicesTemplateHasDoraLegislativeCompliance(): void
    {
        $tpl = $this->catalogue->find('financial_services_dora');
        $this->assertNotNull($tpl);
        $this->assertStringContainsString('DORA', $tpl->legislativeCompliance);
        $this->assertSame('high', $tpl->riskLevel);
    }

    #[Test]
    public function aiActTemplateHasHighRiskAndAnnexIIIReference(): void
    {
        $tpl = $this->catalogue->find('ai_act_annex_iii');
        $this->assertNotNull($tpl);
        $this->assertStringContainsString('Annex III', $tpl->legislativeCompliance);
        $this->assertContains('behavioral', $tpl->dataCategories);
        $this->assertSame('high', $tpl->riskLevel);
    }

    #[Test]
    public function findReturnsNullForUnknownKey(): void
    {
        $this->assertNull($this->catalogue->find('unknown_key'));
    }

    #[Test]
    public function allTemplatesHaveNonEmptyRequiredFields(): void
    {
        foreach ($this->catalogue->all() as $tpl) {
            $this->assertNotEmpty($tpl->processingDescription, "Empty processingDescription for {$tpl->key}");
            $this->assertNotEmpty($tpl->processingPurposes, "Empty processingPurposes for {$tpl->key}");
            $this->assertNotEmpty($tpl->necessityAssessment, "Empty necessityAssessment for {$tpl->key}");
            $this->assertNotEmpty($tpl->proportionalityAssessment, "Empty proportionalityAssessment for {$tpl->key}");
            $this->assertNotEmpty($tpl->technicalMeasures, "Empty technicalMeasures for {$tpl->key}");
            $this->assertNotEmpty($tpl->organizationalMeasures, "Empty organizationalMeasures for {$tpl->key}");
            $this->assertNotEmpty($tpl->dataCategories, "Empty dataCategories for {$tpl->key}");
            $this->assertNotEmpty($tpl->dataSubjectCategories, "Empty dataSubjectCategories for {$tpl->key}");
            $this->assertNotEmpty($tpl->identifiedRisks, "Empty identifiedRisks for {$tpl->key}");
            $this->assertNotEmpty($tpl->usageHint, "Empty usageHint for {$tpl->key}");
            $this->assertNotEmpty($tpl->nameTransKey, "Empty nameTransKey for {$tpl->key}");
            $this->assertNotEmpty($tpl->icon, "Empty icon for {$tpl->key}");
        }
    }
}
