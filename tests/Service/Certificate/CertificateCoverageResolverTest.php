<?php

declare(strict_types=1);

namespace App\Tests\Service\Certificate;

use App\Entity\CertificateCoverageRule;
use App\Entity\ComplianceCertificate;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Repository\CertificateCoverageRuleRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Service\Certificate\CertificateCoverageResolver;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CertificateCoverageResolverTest extends TestCase
{
    #[Test]
    public function returnsRuleRequirementIdsWhenMatched(): void
    {
        // Arrange: a real CertificateCoverageRule with requiredClass='3' and ids ['A','B']
        $rule = new CertificateCoverageRule();
        $rule->setFrameworkCode('ISO27001');
        $rule->setRequiredClass('3');
        $rule->setRequiredScopeTags([]);
        $rule->setRequirementIds(['A', 'B']);

        $ruleRepo = $this->createMock(CertificateCoverageRuleRepository::class);
        $ruleRepo->expects($this->once())
            ->method('findActiveByFramework')
            ->with('ISO27001')
            ->willReturn([$rule]);

        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->expects($this->never())->method($this->anything());

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->expects($this->never())->method($this->anything());

        $cert = new ComplianceCertificate();
        $cert->setFrameworkCode('ISO27001');
        $cert->setCertClass('3');
        $cert->setScopeTags([]);

        $resolver = new CertificateCoverageResolver($ruleRepo, $frameworkRepo, $requirementRepo);

        // Act
        $result = $resolver->resolve($cert);

        // Assert
        $this->assertSame(['A', 'B'], $result->requirementIds);
        $this->assertFalse($result->isFallback);
    }

    #[Test]
    public function fallsBackToAllFrameworkRequirementsWhenNoRule(): void
    {
        // Arrange: no rules match; framework has 2 requirements
        $ruleRepo = $this->createMock(CertificateCoverageRuleRepository::class);
        $ruleRepo->expects($this->once())
            ->method('findActiveByFramework')
            ->with('SOC2')
            ->willReturn([]);

        $framework = new ComplianceFramework();
        $framework->setCode('SOC2');
        $framework->setName('SOC 2');
        $framework->setVersion('2023');
        $framework->setApplicableIndustry('all');
        $framework->setRegulatoryBody('AICPA');

        $req1 = new ComplianceRequirement();
        $req1->setRequirementId('X');
        $req1->setTitle('Req X');
        $req1->setDescription('Description X');
        $req1->setPriority('high');
        $req1->setFramework($framework);

        $req2 = new ComplianceRequirement();
        $req2->setRequirementId('Y');
        $req2->setTitle('Req Y');
        $req2->setDescription('Description Y');
        $req2->setPriority('medium');
        $req2->setFramework($framework);

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['code' => 'SOC2'])
            ->willReturn($framework);

        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->expects($this->once())
            ->method('findByFramework')
            ->with($framework)
            ->willReturn([$req1, $req2]);

        $cert = new ComplianceCertificate();
        $cert->setFrameworkCode('SOC2');
        $cert->setCertClass(null);
        $cert->setScopeTags([]);

        $resolver = new CertificateCoverageResolver($ruleRepo, $frameworkRepo, $requirementRepo);

        // Act
        $result = $resolver->resolve($cert);

        // Assert
        $this->assertEqualsCanonicalizing(['X', 'Y'], $result->requirementIds);
        $this->assertTrue($result->isFallback);
    }

    #[Test]
    public function emptyWhenNoRuleAndUnknownFramework(): void
    {
        // Arrange: no rules, frameworkRepo returns null
        $ruleRepo = $this->createMock(CertificateCoverageRuleRepository::class);
        $ruleRepo->expects($this->once())
            ->method('findActiveByFramework')
            ->with('UNKNOWN')
            ->willReturn([]);

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->expects($this->once())
            ->method('findOneBy')
            ->with(['code' => 'UNKNOWN'])
            ->willReturn(null);

        $requirementRepo = $this->createMock(ComplianceRequirementRepository::class);
        $requirementRepo->expects($this->never())->method($this->anything());

        $cert = new ComplianceCertificate();
        $cert->setFrameworkCode('UNKNOWN');
        $cert->setCertClass(null);
        $cert->setScopeTags([]);

        $resolver = new CertificateCoverageResolver($ruleRepo, $frameworkRepo, $requirementRepo);

        // Act
        $result = $resolver->resolve($cert);

        // Assert
        $this->assertSame([], $result->requirementIds);
        $this->assertTrue($result->isFallback);
    }
}
