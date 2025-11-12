<?php

namespace App\Tests\Entity;

use App\Entity\ISMSContext;
use PHPUnit\Framework\TestCase;

class ISMSContextTest extends TestCase
{
    public function testNewISMSContextHasDefaultValues(): void
    {
        $context = new ISMSContext();

        $this->assertNull($context->getId());
        $this->assertNull($context->getOrganizationName());
        $this->assertNull($context->getIsmsScope());
        $this->assertNull($context->getScopeExclusions());
        $this->assertNull($context->getExternalIssues());
        $this->assertNull($context->getInternalIssues());
        $this->assertNull($context->getInterestedParties());
        $this->assertNull($context->getInterestedPartiesRequirements());
        $this->assertNull($context->getLegalRequirements());
        $this->assertNull($context->getRegulatoryRequirements());
        $this->assertNull($context->getContractualObligations());
        $this->assertNull($context->getIsmsPolicy());
        $this->assertNull($context->getRolesAndResponsibilities());
        $this->assertNull($context->getLastReviewDate());
        $this->assertNull($context->getNextReviewDate());
        $this->assertInstanceOf(\DateTimeImmutable::class, $context->getCreatedAt());
        $this->assertNull($context->getUpdatedAt());
    }

    public function testSetAndGetOrganizationName(): void
    {
        $context = new ISMSContext();
        $context->setOrganizationName('Example Corporation GmbH');

        $this->assertEquals('Example Corporation GmbH', $context->getOrganizationName());
    }

    public function testSetAndGetIsmsScope(): void
    {
        $context = new ISMSContext();
        $scope = 'IT infrastructure and cloud services for customer data processing';

        $context->setIsmsScope($scope);

        $this->assertEquals($scope, $context->getIsmsScope());
    }

    public function testSetAndGetScopeExclusions(): void
    {
        $context = new ISMSContext();
        $exclusions = 'Physical facilities management and third-party logistics';

        $context->setScopeExclusions($exclusions);

        $this->assertEquals($exclusions, $context->getScopeExclusions());
    }

    public function testSetAndGetExternalIssues(): void
    {
        $context = new ISMSContext();
        $issues = 'Market competition, regulatory changes, cyber threats';

        $context->setExternalIssues($issues);

        $this->assertEquals($issues, $context->getExternalIssues());
    }

    public function testSetAndGetInternalIssues(): void
    {
        $context = new ISMSContext();
        $issues = 'Resource constraints, legacy systems, training gaps';

        $context->setInternalIssues($issues);

        $this->assertEquals($issues, $context->getInternalIssues());
    }

    public function testSetAndGetInterestedParties(): void
    {
        $context = new ISMSContext();
        $parties = 'Customers, employees, shareholders, regulators';

        $context->setInterestedParties($parties);

        $this->assertEquals($parties, $context->getInterestedParties());
    }

    public function testSetAndGetInterestedPartiesRequirements(): void
    {
        $context = new ISMSContext();
        $requirements = 'Data protection, service availability, compliance reporting';

        $context->setInterestedPartiesRequirements($requirements);

        $this->assertEquals($requirements, $context->getInterestedPartiesRequirements());
    }

    public function testSetAndGetLegalRequirements(): void
    {
        $context = new ISMSContext();
        $legal = 'GDPR, BDSG, TMG, data protection laws';

        $context->setLegalRequirements($legal);

        $this->assertEquals($legal, $context->getLegalRequirements());
    }

    public function testSetAndGetRegulatoryRequirements(): void
    {
        $context = new ISMSContext();
        $regulatory = 'ISO 27001, NIS2 Directive, industry standards';

        $context->setRegulatoryRequirements($regulatory);

        $this->assertEquals($regulatory, $context->getRegulatoryRequirements());
    }

    public function testSetAndGetContractualObligations(): void
    {
        $context = new ISMSContext();
        $obligations = 'SLAs with customers, vendor agreements, partnership contracts';

        $context->setContractualObligations($obligations);

        $this->assertEquals($obligations, $context->getContractualObligations());
    }

    public function testSetAndGetIsmsPolicy(): void
    {
        $context = new ISMSContext();
        $policy = 'Our organization is committed to protecting information assets...';

        $context->setIsmsPolicy($policy);

        $this->assertEquals($policy, $context->getIsmsPolicy());
    }

    public function testSetAndGetRolesAndResponsibilities(): void
    {
        $context = new ISMSContext();
        $roles = 'CISO: Overall ISMS responsibility, DPO: Data protection, IT Manager: Technical controls';

        $context->setRolesAndResponsibilities($roles);

        $this->assertEquals($roles, $context->getRolesAndResponsibilities());
    }

    public function testSetAndGetLastReviewDate(): void
    {
        $context = new ISMSContext();
        $date = new \DateTime('2024-01-15');

        $context->setLastReviewDate($date);

        $this->assertEquals($date, $context->getLastReviewDate());
    }

    public function testSetAndGetNextReviewDate(): void
    {
        $context = new ISMSContext();
        $date = new \DateTime('2024-07-15');

        $context->setNextReviewDate($date);

        $this->assertEquals($date, $context->getNextReviewDate());
    }

    public function testSetAndGetCreatedAt(): void
    {
        $context = new ISMSContext();
        $date = new \DateTime('2024-01-01');

        $context->setCreatedAt($date);

        $this->assertEquals($date, $context->getCreatedAt());
    }

    public function testSetAndGetUpdatedAt(): void
    {
        $context = new ISMSContext();
        $date = new \DateTime('2024-01-20');

        $context->setUpdatedAt($date);

        $this->assertEquals($date, $context->getUpdatedAt());
    }

    public function testISMSContextCanStoreCompleteOrganizationProfile(): void
    {
        $context = new ISMSContext();

        $context->setOrganizationName('TechCorp GmbH');
        $context->setIsmsScope('IT services and data processing');
        $context->setExternalIssues('Cyber threats, competition');
        $context->setInternalIssues('Legacy systems');
        $context->setInterestedParties('Customers, employees, regulators');
        $context->setLegalRequirements('GDPR, BDSG');
        $context->setIsmsPolicy('We protect information assets...');
        $context->setLastReviewDate(new \DateTime('2024-01-01'));
        $context->setNextReviewDate(new \DateTime('2024-07-01'));

        $this->assertEquals('TechCorp GmbH', $context->getOrganizationName());
        $this->assertEquals('IT services and data processing', $context->getIsmsScope());
        $this->assertNotNull($context->getLastReviewDate());
        $this->assertNotNull($context->getNextReviewDate());
    }
}
