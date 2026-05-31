<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ComplianceFrameworkTest extends TestCase
{
    #[Test]
    public function testNewFrameworkHasDefaultValues(): void
    {
        $framework = new ComplianceFramework();

        $this->assertNull($framework->id);
        $this->assertNull($framework->getName());
        $this->assertNull($framework->getCode());
        $this->assertNull($framework->getDescription());
        $this->assertNull($framework->getVersion());
        $this->assertInstanceOf(\DateTimeImmutable::class, $framework->getCreatedAt());
        $this->assertNull($framework->getUpdatedAt());
        $this->assertCount(0, $framework->requirements);
    }

    #[Test]
    public function testSetAndGetName(): void
    {
        $framework = new ComplianceFramework();
        $framework->setName('ISO 27001:2022');

        $this->assertEquals('ISO 27001:2022', $framework->getName());
    }

    #[Test]
    public function testSetAndGetCode(): void
    {
        $framework = new ComplianceFramework();
        $framework->setCode('ISO27001');

        $this->assertEquals('ISO27001', $framework->getCode());
    }

    #[Test]
    public function testSetAndGetDescription(): void
    {
        $framework = new ComplianceFramework();
        $framework->setDescription('Information security management system standard');

        $this->assertEquals('Information security management system standard', $framework->getDescription());
    }

    #[Test]
    public function testSetAndGetVersion(): void
    {
        $framework = new ComplianceFramework();
        $framework->setVersion('2022');

        $this->assertEquals('2022', $framework->getVersion());
    }

    #[Test]
    public function testSetAndGetApplicableIndustry(): void
    {
        $framework = new ComplianceFramework();
        $framework->setApplicableIndustry('General');

        $this->assertEquals('General', $framework->getApplicableIndustry());
    }

    #[Test]
    public function testSetAndGetRegulatoryBody(): void
    {
        $framework = new ComplianceFramework();
        $framework->setRegulatoryBody('ISO/IEC');

        $this->assertEquals('ISO/IEC', $framework->getRegulatoryBody());
    }

    #[Test]
    public function testSetAndGetMandatory(): void
    {
        $framework = new ComplianceFramework();

        $framework->setMandatory(true);
        $this->assertTrue($framework->isMandatory());

        $framework->setMandatory(false);
        $this->assertFalse($framework->isMandatory());
    }

    #[Test]
    public function testAddAndRemoveRequirement(): void
    {
        $framework = new ComplianceFramework();
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('A.5.1 Policies for information security');

        $this->assertCount(0, $framework->requirements);

        $framework->addRequirement($requirement);
        $this->assertCount(1, $framework->requirements);
        $this->assertTrue($framework->requirements->contains($requirement));
        $this->assertSame($framework, $requirement->getFramework());

        $framework->removeRequirement($requirement);
        $this->assertCount(0, $framework->requirements);
        $this->assertFalse($framework->requirements->contains($requirement));
    }

    #[Test]
    public function testAddRequirementDoesNotDuplicate(): void
    {
        $framework = new ComplianceFramework();
        $requirement = new ComplianceRequirement();
        $requirement->setTitle('A.5.1 Policies for information security');

        $framework->addRequirement($requirement);
        $framework->addRequirement($requirement); // Add same requirement again

        $this->assertCount(1, $framework->requirements);
    }

    /**
     * BUG 1 regression — getCompliancePercentage() must count ONLY top-level
     * requirements in its denominator. The EU-mapping decomposition import added
     * ~5000 `sub_requirement` rows; counting them would dilute the % ~25×.
     *
     * Scenario: 1 fulfilled top-level requirement + 3 sub_requirements.
     * Correct result = 100% (1/1), NOT 25% (1/4).
     */
    #[Test]
    public function testCompliancePercentageDenominatorExcludesSubRequirements(): void
    {
        $framework = new ComplianceFramework();

        $framework->addRequirement($this->fulfilledTopLevelRequirement('CORE-1'));

        // Three imported sub_requirements — must NOT enter the denominator.
        for ($i = 1; $i <= 3; $i++) {
            $framework->addRequirement($this->subRequirement('SUB-' . $i));
        }

        $this->assertSame(
            100.0,
            $framework->getCompliancePercentage(),
            'Denominator must be top-level only (1/1 = 100%), not diluted by sub_requirements (1/4 = 25%)',
        );
    }

    /**
     * BUG 1 regression — adding more sub_requirements must not change the %.
     */
    #[Test]
    public function testCompliancePercentageIsInvariantToSubRequirementCount(): void
    {
        $framework = new ComplianceFramework();
        $framework->addRequirement($this->fulfilledTopLevelRequirement('CORE-1'));
        $framework->addRequirement($this->unfulfilledTopLevelRequirement('CORE-2'));

        $baseline = $framework->getCompliancePercentage();
        $this->assertSame(50.0, $baseline, '1 of 2 top-level requirements fulfilled = 50%');

        for ($i = 1; $i <= 40; $i++) {
            $framework->addRequirement($this->subRequirement('SUB-' . $i));
        }

        $this->assertSame(
            $baseline,
            $framework->getCompliancePercentage(),
            'Compliance % must be invariant to the number of imported sub_requirements',
        );
    }

    /**
     * Only sub_requirements present → no top-level denominator entries → 0.0
     * (division-by-zero guard), proving subs are never counted as denominator.
     */
    #[Test]
    public function testCompliancePercentageWithOnlySubRequirementsIsZero(): void
    {
        $framework = new ComplianceFramework();
        $framework->addRequirement($this->subRequirement('SUB-ONLY-1'));
        $framework->addRequirement($this->subRequirement('SUB-ONLY-2'));

        $this->assertSame(0.0, $framework->getCompliancePercentage());
    }

    private function fulfilledTopLevelRequirement(string $id): ComplianceRequirement
    {
        // Anonymous subclass forces getStatus() into the "fulfilled" vocabulary
        // that getCompliancePercentage() counts, without needing fulfillment rows.
        $req = new class extends ComplianceRequirement {
            public function getStatus(): string
            {
                return 'compliant';
            }
        };
        $req->setRequirementId($id)->setTitle('Top-level ' . $id)->setRequirementType('core');

        return $req;
    }

    private function unfulfilledTopLevelRequirement(string $id): ComplianceRequirement
    {
        $req = new class extends ComplianceRequirement {
            public function getStatus(): string
            {
                return 'not_implemented';
            }
        };
        $req->setRequirementId($id)->setTitle('Top-level ' . $id)->setRequirementType('core');

        return $req;
    }

    private function subRequirement(string $id): ComplianceRequirement
    {
        $parent = new ComplianceRequirement();
        $parent->setRequirementType('core');

        // Even if a sub-req reports 'compliant', it must be excluded by virtue of
        // having a parent + requirementType='sub_requirement'.
        $req = new class extends ComplianceRequirement {
            public function getStatus(): string
            {
                return 'compliant';
            }
        };
        $req->setRequirementId($id)
            ->setTitle('Sub ' . $id)
            ->setRequirementType('sub_requirement')
            ->setParentRequirement($parent);

        return $req;
    }
}
