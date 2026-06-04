<?php

declare(strict_types=1);

namespace App\Tests\Service\PreFiller;

use App\Dpia\Template\DpiaTemplateCatalogue;
use App\Entity\DataProtectionImpactAssessment;
use App\Service\PreFiller\SectoralDpiaPreFiller;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class SectoralDpiaPreFillerTest extends TestCase
{
    private SectoralDpiaPreFiller $filler;

    protected function setUp(): void
    {
        $this->filler = new SectoralDpiaPreFiller(new DpiaTemplateCatalogue());
    }

    #[Test]
    public function returnsNullForUnknownKey(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $result = $this->filler->applyTemplate('unknown_key', $dpia);
        $this->assertNull($result);
    }

    #[Test]
    public function doesNotModifyDpiaForUnknownKey(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $this->filler->applyTemplate('unknown_key', $dpia);
        $this->assertNull($dpia->getProcessingDescription());
    }

    /** @return array<string, array{0: string}> */
    public static function templateKeyProvider(): array
    {
        return [
            'healthcare' => ['healthcare_bdsg_sect22'],
            'financial_services' => ['financial_services_dora'],
            'ai_act' => ['ai_act_annex_iii'],
        ];
    }

    #[Test]
    #[DataProvider('templateKeyProvider')]
    public function fillsAllMandatoryFields(string $key): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $result = $this->filler->applyTemplate($key, $dpia);

        $this->assertNotNull($result, "applyTemplate() returned null for key '$key'");
        $this->assertNotEmpty($dpia->getProcessingDescription(), "processingDescription empty for $key");
        $this->assertNotEmpty($dpia->getProcessingPurposes(), "processingPurposes empty for $key");
        $this->assertNotEmpty($dpia->getNecessityAssessment(), "necessityAssessment empty for $key");
        $this->assertNotEmpty($dpia->getProportionalityAssessment(), "proportionalityAssessment empty for $key");
        $this->assertNotEmpty($dpia->getLegalBasis(), "legalBasis empty for $key");
        $this->assertNotEmpty($dpia->getLegislativeCompliance(), "legislativeCompliance empty for $key");
        $this->assertNotEmpty($dpia->getTechnicalMeasures(), "technicalMeasures empty for $key");
        $this->assertNotEmpty($dpia->getOrganizationalMeasures(), "organizationalMeasures empty for $key");
        $this->assertNotEmpty($dpia->getDataCategories(), "dataCategories empty for $key");
        $this->assertNotEmpty($dpia->getDataSubjectCategories(), "dataSubjectCategories empty for $key");
        $this->assertNotEmpty($dpia->getIdentifiedRisks(), "identifiedRisks empty for $key");
        $this->assertNotEmpty($dpia->getRiskLevel(), "riskLevel empty for $key");
        $this->assertSame('draft', $dpia->getStatus());
    }

    #[Test]
    public function healthcareTemplateSetsBdsgSect22LegalBasis(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $this->filler->applyTemplate('healthcare_bdsg_sect22', $dpia);
        $this->assertSame('art9_bdsg22', $dpia->getLegalBasis());
    }

    #[Test]
    public function financialServicesTemplateSetsRequiresSupervisoryConsultation(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $this->filler->applyTemplate('financial_services_dora', $dpia);
        $this->assertTrue($dpia->getRequiresSupervisoryConsultation());
    }

    #[Test]
    public function aiActTemplateSetsHighRiskLevel(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $this->filler->applyTemplate('ai_act_annex_iii', $dpia);
        $this->assertSame('high', $dpia->getRiskLevel());
    }

    #[Test]
    public function doesNotOverwriteExistingTitleWhenAlreadySet(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $dpia->setTitle('My Custom DPIA');
        $this->filler->applyTemplate('healthcare_bdsg_sect22', $dpia);
        // Title should NOT be overwritten — filler only touches blank fields
        $this->assertSame('My Custom DPIA', $dpia->getTitle());
    }

    #[Test]
    public function doesNotOverwriteExistingProcessingDescriptionWhenAlreadySet(): void
    {
        $dpia = new DataProtectionImpactAssessment();
        $dpia->setProcessingDescription('Existing description set by user');
        $this->filler->applyTemplate('financial_services_dora', $dpia);
        $this->assertSame('Existing description set by user', $dpia->getProcessingDescription());
    }

    #[Test]
    public function getCatalogueReturnsCorrectCatalogueInstance(): void
    {
        $catalogue = $this->filler->getCatalogue();
        $this->assertCount(3, $catalogue->all());
    }
}
