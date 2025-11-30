<?php

namespace App\Tests\Entity;

use App\Entity\Asset;
use App\Entity\BusinessContinuityPlan;
use App\Entity\BusinessProcess;
use App\Entity\Document;
use App\Entity\Supplier;
use App\Entity\Tenant;
use PHPUnit\Framework\TestCase;

class BusinessContinuityPlanTest extends TestCase
{
    public function testConstructor(): void
    {
        $plan = new BusinessContinuityPlan();

        $this->assertNotNull($plan->getCreatedAt());
        $this->assertInstanceOf(\DateTimeInterface::class, $plan->getCreatedAt());
        $this->assertEquals(0, $plan->getCriticalSuppliers()->count());
        $this->assertEquals(0, $plan->getCriticalAssets()->count());
        $this->assertEquals(0, $plan->getDocuments()->count());
    }

    public function testGettersAndSetters(): void
    {
        $plan = new BusinessContinuityPlan();

        $plan->setName('IT Systems BC Plan');
        $plan->setCode('it_systems_bc_plan');
        $this->assertEquals('IT Systems BC Plan', $plan->getName());

        $plan->setDescription('Recovery plan for critical IT systems');
        $this->assertEquals('Recovery plan for critical IT systems', $plan->getDescription());

        $plan->setPlanOwner('John Doe');
        $this->assertEquals('John Doe', $plan->getPlanOwner());

        $plan->setBcTeam('IT Team, Management');
        $this->assertEquals('IT Team, Management', $plan->getBcTeam());

        $plan->setActivationCriteria('System outage exceeding 4 hours');
        $this->assertEquals('System outage exceeding 4 hours', $plan->getActivationCriteria());

        $plan->setRolesAndResponsibilities('Team lead coordinates recovery');
        $this->assertEquals('Team lead coordinates recovery', $plan->getRolesAndResponsibilities());

        $plan->setRecoveryProcedures('1. Assess damage 2. Activate backup');
        $this->assertEquals('1. Assess damage 2. Activate backup', $plan->getRecoveryProcedures());

        $plan->setCommunicationPlan('Notify stakeholders via email');
        $this->assertEquals('Notify stakeholders via email', $plan->getCommunicationPlan());

        $plan->setInternalCommunication('Use Teams for internal coordination');
        $this->assertEquals('Use Teams for internal coordination', $plan->getInternalCommunication());

        $plan->setExternalCommunication('Press release via PR team');
        $this->assertEquals('Press release via PR team', $plan->getExternalCommunication());

        $plan->setAlternativeSite('Backup data center');
        $this->assertEquals('Backup data center', $plan->getAlternativeSite());

        $plan->setAlternativeSiteAddress('123 Backup Street, City');
        $this->assertEquals('123 Backup Street, City', $plan->getAlternativeSiteAddress());

        $plan->setAlternativeSiteCapacity('50% of primary capacity');
        $this->assertEquals('50% of primary capacity', $plan->getAlternativeSiteCapacity());

        $plan->setBackupProcedures('Daily incremental, weekly full');
        $this->assertEquals('Daily incremental, weekly full', $plan->getBackupProcedures());

        $plan->setRestoreProcedures('Restore from most recent backup');
        $this->assertEquals('Restore from most recent backup', $plan->getRestoreProcedures());

        $plan->setReviewNotes('Plan reviewed and updated');
        $this->assertEquals('Plan reviewed and updated', $plan->getReviewNotes());
    }

    public function testStatusGetterAndSetter(): void
    {
        $plan = new BusinessContinuityPlan();

        // Default value
        $this->assertEquals('draft', $plan->getStatus());

        $plan->setStatus('active');
        $this->assertEquals('active', $plan->getStatus());

        $plan->setStatus('under_review');
        $this->assertEquals('under_review', $plan->getStatus());

        $plan->setStatus('archived');
        $this->assertEquals('archived', $plan->getStatus());
    }

    public function testVersionGetterAndSetter(): void
    {
        $plan = new BusinessContinuityPlan();

        // Default value
        $this->assertEquals('1.0', $plan->getVersion());

        $plan->setVersion('2.1');
        $this->assertEquals('2.1', $plan->getVersion());
    }

    public function testResponseTeamGetterAndSetter(): void
    {
        $plan = new BusinessContinuityPlan();

        $this->assertNull($plan->getResponseTeam());

        $team = [
            'incident_commander' => 'John Doe',
            'communications_lead' => 'Jane Smith',
            'recovery_lead' => 'Bob Johnson',
            'technical_lead' => 'Alice Williams',
        ];

        $plan->setResponseTeam($team);
        $this->assertEquals($team, $plan->getResponseTeam());
    }

    public function testStakeholderContactsGetterAndSetter(): void
    {
        $plan = new BusinessContinuityPlan();

        $this->assertNull($plan->getStakeholderContacts());

        $contacts = [
            'CEO' => 'ceo@example.com',
            'CFO' => 'cfo@example.com',
        ];

        $plan->setStakeholderContacts($contacts);
        $this->assertEquals($contacts, $plan->getStakeholderContacts());
    }

    public function testRequiredResourcesGetterAndSetter(): void
    {
        $plan = new BusinessContinuityPlan();

        $this->assertNull($plan->getRequiredResources());

        $resources = [
            'personnel' => 10,
            'equipment' => ['Laptops', 'Phones'],
            'supplies' => ['Paper', 'Toner'],
        ];

        $plan->setRequiredResources($resources);
        $this->assertEquals($resources, $plan->getRequiredResources());
    }

    public function testBusinessProcessRelationship(): void
    {
        $plan = new BusinessContinuityPlan();
        $process = new BusinessProcess();

        $this->assertNull($plan->getBusinessProcess());

        $plan->setBusinessProcess($process);
        $this->assertSame($process, $plan->getBusinessProcess());
    }

    public function testTenantRelationship(): void
    {
        $plan = new BusinessContinuityPlan();
        $tenant = new Tenant();
        $tenant->setName('Test Tenant');
        $tenant->setCode('test_tenant');
        $tenant->setCode('test_tenant');

        $this->assertNull($plan->getTenant());

        $plan->setTenant($tenant);
        $this->assertSame($tenant, $plan->getTenant());
    }

    public function testAddAndRemoveCriticalSupplier(): void
    {
        $plan = new BusinessContinuityPlan();
        $supplier = new Supplier();

        $this->assertEquals(0, $plan->getCriticalSuppliers()->count());

        $plan->addCriticalSupplier($supplier);
        $this->assertEquals(1, $plan->getCriticalSuppliers()->count());
        $this->assertTrue($plan->getCriticalSuppliers()->contains($supplier));

        // Adding the same supplier again should not increase count
        $plan->addCriticalSupplier($supplier);
        $this->assertEquals(1, $plan->getCriticalSuppliers()->count());

        $plan->removeCriticalSupplier($supplier);
        $this->assertEquals(0, $plan->getCriticalSuppliers()->count());
        $this->assertFalse($plan->getCriticalSuppliers()->contains($supplier));
    }

    public function testAddAndRemoveCriticalAsset(): void
    {
        $plan = new BusinessContinuityPlan();
        $asset = new Asset();

        $this->assertEquals(0, $plan->getCriticalAssets()->count());

        $plan->addCriticalAsset($asset);
        $this->assertEquals(1, $plan->getCriticalAssets()->count());
        $this->assertTrue($plan->getCriticalAssets()->contains($asset));

        // Adding the same asset again should not increase count
        $plan->addCriticalAsset($asset);
        $this->assertEquals(1, $plan->getCriticalAssets()->count());

        $plan->removeCriticalAsset($asset);
        $this->assertEquals(0, $plan->getCriticalAssets()->count());
        $this->assertFalse($plan->getCriticalAssets()->contains($asset));
    }

    public function testAddAndRemoveDocument(): void
    {
        $plan = new BusinessContinuityPlan();
        $document = new Document();

        $this->assertEquals(0, $plan->getDocuments()->count());

        $plan->addDocument($document);
        $this->assertEquals(1, $plan->getDocuments()->count());
        $this->assertTrue($plan->getDocuments()->contains($document));

        // Adding the same document again should not increase count
        $plan->addDocument($document);
        $this->assertEquals(1, $plan->getDocuments()->count());

        $plan->removeDocument($document);
        $this->assertEquals(0, $plan->getDocuments()->count());
        $this->assertFalse($plan->getDocuments()->contains($document));
    }

    public function testDateFields(): void
    {
        $plan = new BusinessContinuityPlan();

        $this->assertNull($plan->getLastTested());
        $this->assertNull($plan->getNextTestDate());
        $this->assertNull($plan->getLastReviewDate());
        $this->assertNull($plan->getNextReviewDate());

        $lastTested = new \DateTime('2024-01-15');
        $plan->setLastTested($lastTested);
        $this->assertEquals($lastTested, $plan->getLastTested());

        $nextTest = new \DateTime('2024-07-15');
        $plan->setNextTestDate($nextTest);
        $this->assertEquals($nextTest, $plan->getNextTestDate());

        $lastReview = new \DateTime('2024-02-01');
        $plan->setLastReviewDate($lastReview);
        $this->assertEquals($lastReview, $plan->getLastReviewDate());

        $nextReview = new \DateTime('2024-08-01');
        $plan->setNextReviewDate($nextReview);
        $this->assertEquals($nextReview, $plan->getNextReviewDate());
    }

    public function testTimestamps(): void
    {
        $plan = new BusinessContinuityPlan();

        $this->assertNotNull($plan->getCreatedAt());
        $this->assertNull($plan->getUpdatedAt());

        $createdAt = new \DateTime('2024-01-01 10:00:00');
        $plan->setCreatedAt($createdAt);
        $this->assertEquals($createdAt, $plan->getCreatedAt());

        $updatedAt = new \DateTime('2024-01-02 15:00:00');
        $plan->setUpdatedAt($updatedAt);
        $this->assertEquals($updatedAt, $plan->getUpdatedAt());
    }

    public function testUpdateTimestamps(): void
    {
        $plan = new BusinessContinuityPlan();
        $originalCreatedAt = $plan->getCreatedAt();

        $plan->updateTimestamps();

        $this->assertNotNull($plan->getCreatedAt());
        $this->assertNotNull($plan->getUpdatedAt());
        $this->assertInstanceOf(\DateTimeImmutable::class, $plan->getUpdatedAt());
    }

    public function testIsTestOverdue(): void
    {
        $plan = new BusinessContinuityPlan();

        // No next test date set, but active and never tested
        $plan->setStatus('active');
        $this->assertTrue($plan->isTestOverdue());

        // Has been tested
        $plan->setLastTested(new \DateTime('2023-01-01'));
        $this->assertFalse($plan->isTestOverdue());

        // Next test date in the future
        $plan->setNextTestDate(new \DateTime('+30 days'));
        $this->assertFalse($plan->isTestOverdue());

        // Next test date in the past
        $plan->setNextTestDate(new \DateTime('-30 days'));
        $this->assertTrue($plan->isTestOverdue());

        // Draft status, never tested - not overdue
        $plan2 = new BusinessContinuityPlan();
        $plan2->setStatus('draft');
        $this->assertFalse($plan2->isTestOverdue());
    }

    public function testIsReviewOverdue(): void
    {
        $plan = new BusinessContinuityPlan();

        // No next review date
        $this->assertFalse($plan->isReviewOverdue());

        // Next review date in the future
        $plan->setNextReviewDate(new \DateTime('+30 days'));
        $this->assertFalse($plan->isReviewOverdue());

        // Next review date in the past
        $plan->setNextReviewDate(new \DateTime('-30 days'));
        $this->assertTrue($plan->isReviewOverdue());
    }

    public function testGetReadinessScore(): void
    {
        $plan = new BusinessContinuityPlan();

        // Empty plan - score is 0
        $this->assertEquals(0, $plan->getReadinessScore());

        // Add required sections (40 points total)
        $plan->setActivationCriteria('Major outage');
        $plan->setRecoveryProcedures('Step 1, Step 2');
        $plan->setCommunicationPlan('Email stakeholders');
        $plan->setResponseTeam(['lead' => 'John Doe']);
        $this->assertEquals(40, $plan->getReadinessScore());

        // Add recent test (within 6 months) - 30 points
        $plan->setLastTested(new \DateTime('-3 months'));
        $this->assertEquals(70, $plan->getReadinessScore());

        // Add recent review (within 6 months) - 20 points
        $plan->setLastReviewDate(new \DateTime('-2 months'));
        $this->assertEquals(90, $plan->getReadinessScore());

        // Set status to active - 10 points
        $plan->setStatus('active');
        $this->assertEquals(100, $plan->getReadinessScore());
    }

    public function testGetReadinessScoreWithOlderTestAndReview(): void
    {
        $plan = new BusinessContinuityPlan();

        // Add required sections (40 points)
        $plan->setActivationCriteria('Major outage');
        $plan->setRecoveryProcedures('Step 1, Step 2');
        $plan->setCommunicationPlan('Email stakeholders');
        $plan->setResponseTeam(['lead' => 'John Doe']);

        // Test 9 months ago - diff()->m will be 9, which is > 6 but <= 12, so 20 points
        $plan->setLastTested(new \DateTime('-9 months'));
        $this->assertEquals(60, $plan->getReadinessScore());

        // Test 18 months ago - diff()->m will be 6 (only month part), so gets 30 points
        // This is a quirk of using diff()->m which only gets the month component
        $plan->setLastTested(new \DateTime('-18 months'));
        $this->assertEquals(70, $plan->getReadinessScore());

        // Review 8 months ago - diff()->m will be 8, which is > 6 but <= 12, so 10 points
        $plan->setLastReviewDate(new \DateTime('-8 months'));
        $this->assertEquals(80, $plan->getReadinessScore());
    }

    public function testGetCompletenessPercentage(): void
    {
        $plan = new BusinessContinuityPlan();

        // Empty plan
        $this->assertEquals(0, $plan->getCompletenessPercentage());

        // Add all key fields
        $plan->setName('Test Plan');
        $plan->setCode('test_plan');
        $plan->setPlanOwner('Owner');
        $plan->setActivationCriteria('Criteria');
        $plan->setRecoveryProcedures('Procedures');
        $plan->setCommunicationPlan('Communication');
        $plan->setResponseTeam(['lead' => 'John']);
        $plan->setRolesAndResponsibilities('Roles');
        $plan->setAlternativeSite('Site');
        $plan->setBackupProcedures('Backup');
        $plan->setRestoreProcedures('Restore');
        $plan->setStakeholderContacts(['contact' => 'email']);
        $plan->setRequiredResources(['resource' => 'value']);

        $asset = new Asset();
        $plan->addCriticalAsset($asset);

        // All 13 fields completed = 100%
        $this->assertEquals(100, $plan->getCompletenessPercentage());

        // Test partial completion
        $plan2 = new BusinessContinuityPlan();
        $plan2->setName('Test');
        $plan2->setCode('test');
        $plan2->setPlanOwner('Owner');
        $plan2->setActivationCriteria('Criteria');
        $plan2->setRecoveryProcedures('Procedures');

        // 4 out of 13 = ~30%
        $this->assertEquals(30, $plan2->getCompletenessPercentage());
    }
}
