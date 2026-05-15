<?php

declare(strict_types=1);

namespace App\Tests\Service\PreFiller;

use App\Entity\Asset;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\Risk;
use App\Service\PreFiller\DpiaPreFiller;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class DpiaPreFillerTest extends TestCase
{
    #[Test]
    public function copiesRiskTitleDescriptionAndAssetOntoDpia(): void
    {
        $asset = new Asset();
        $risk = (new Risk())
            ->setTitle('Customer-PII unauthorised access')
            ->setDescription('Web-portal exposes customer name+e-mail without MFA.')
            ->setAsset($asset);

        $dpia = new DataProtectionImpactAssessment();

        (new DpiaPreFiller())->fromRisk($risk, $dpia);

        $this->assertSame('DPIA: Customer-PII unauthorised access', $dpia->getTitle());
        $this->assertStringContainsString(
            'Web-portal exposes customer name+e-mail without MFA.',
            (string) $dpia->getProcessingDescription(),
        );
        $this->assertSame($asset, $dpia->getRelatedAsset());
        $this->assertSame('draft', $dpia->getStatus());
        $this->assertNotEmpty($dpia->getProcessingPurposes());
        $this->assertNotEmpty($dpia->getNecessityAssessment());
    }

    #[Test]
    public function emptyRiskDescriptionFallsBackToPlaceholder(): void
    {
        $risk = (new Risk())->setTitle('Untitled');

        $dpia = new DataProtectionImpactAssessment();

        (new DpiaPreFiller())->fromRisk($risk, $dpia);

        $this->assertStringContainsString(
            'Pre-filled from Risk',
            (string) $dpia->getProcessingDescription(),
        );
        $this->assertSame('draft', $dpia->getStatus());
        $this->assertNull($dpia->getRelatedAsset());
    }
}
