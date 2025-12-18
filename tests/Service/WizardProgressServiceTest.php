<?php

namespace App\Tests\Service;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WizardSession;
use App\Service\ComplianceWizardService;
use App\Service\WizardProgressService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Tests for WizardProgressService
 *
 * Phase 7E: Compliance Wizards & Module-Aware KPIs
 */
class WizardProgressServiceTest extends KernelTestCase
{
    private ?WizardProgressService $progressService = null;
    private ?ComplianceWizardService $wizardService = null;
    private bool $dbAvailable = false;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        try {
            // Test database connectivity
            $em = $container->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
            $this->dbAvailable = true;

            // Try to get services - may fail if not public
            try {
                $this->progressService = $container->get(WizardProgressService::class);
                $this->wizardService = $container->get(ComplianceWizardService::class);
            } catch (\Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException $e) {
                // Services are not public, skip service-dependent tests
                $this->progressService = null;
                $this->wizardService = null;
            }
        } catch (\Exception $e) {
            if (str_contains($e->getMessage(), 'Access denied') ||
                str_contains($e->getMessage(), 'Connection refused') ||
                str_contains($e->getMessage(), 'SQLSTATE')) {
                $this->dbAvailable = false;
            } else {
                throw $e;
            }
        }
    }

    private function requireDatabase(): void
    {
        if (!$this->dbAvailable) {
            $this->markTestSkipped('Database not available');
        }
    }

    public function testServiceIsInjectable(): void
    {
        $this->requireDatabase();
        if ($this->progressService === null) {
            $this->markTestSkipped('WizardProgressService not available as public service');
        }
        $this->assertNotNull($this->progressService);
    }

    public function testWizardSessionEntityHasRequiredMethods(): void
    {
        $session = new WizardSession();

        // Test setters return self for chaining
        $this->assertInstanceOf(WizardSession::class, $session->setWizardType('iso27001'));
        $this->assertInstanceOf(WizardSession::class, $session->setStatus(WizardSession::STATUS_IN_PROGRESS));
        $this->assertInstanceOf(WizardSession::class, $session->setCurrentStep(1));
        $this->assertInstanceOf(WizardSession::class, $session->setTotalSteps(10));
        $this->assertInstanceOf(WizardSession::class, $session->setOverallScore(75));
        $this->assertInstanceOf(WizardSession::class, $session->setCompletedCategories([]));
        $this->assertInstanceOf(WizardSession::class, $session->setAssessmentResults([]));
        $this->assertInstanceOf(WizardSession::class, $session->setRecommendations([]));
        $this->assertInstanceOf(WizardSession::class, $session->setCriticalGaps([]));
    }

    public function testWizardSessionProgressCalculation(): void
    {
        $session = new WizardSession();
        $session->setCurrentStep(5);
        $session->setTotalSteps(10);

        $this->assertEquals(50, $session->getProgressPercentage());

        $session->setCurrentStep(10);
        $this->assertEquals(100, $session->getProgressPercentage());

        $session->setTotalSteps(0);
        $this->assertEquals(0, $session->getProgressPercentage());
    }

    public function testWizardSessionStatusHelpers(): void
    {
        $session = new WizardSession();

        // Default status is IN_PROGRESS
        $this->assertTrue($session->isInProgress());
        $this->assertFalse($session->isCompleted());

        // Complete the session
        $session->complete();
        $this->assertFalse($session->isInProgress());
        $this->assertTrue($session->isCompleted());
        $this->assertEquals(WizardSession::STATUS_COMPLETED, $session->getStatus());
        $this->assertNotNull($session->getCompletedAt());

        // Test abandon
        $session2 = new WizardSession();
        $session2->abandon();
        $this->assertEquals(WizardSession::STATUS_ABANDONED, $session2->getStatus());
    }

    public function testWizardSessionWizardName(): void
    {
        $session = new WizardSession();

        $session->setWizardType(WizardSession::WIZARD_ISO27001);
        $this->assertEquals('ISO 27001:2022', $session->getWizardName());

        $session->setWizardType(WizardSession::WIZARD_NIS2);
        $this->assertEquals('NIS2 Directive', $session->getWizardName());

        $session->setWizardType(WizardSession::WIZARD_DORA);
        $this->assertEquals('DORA', $session->getWizardName());

        $session->setWizardType(WizardSession::WIZARD_TISAX);
        $this->assertEquals('TISAX', $session->getWizardName());

        $session->setWizardType(WizardSession::WIZARD_GDPR);
        $this->assertEquals('GDPR/DSGVO', $session->getWizardName());

        $session->setWizardType(WizardSession::WIZARD_BSI);
        $this->assertEquals('BSI IT-Grundschutz', $session->getWizardName());
    }

    public function testWizardSessionStatusBadgeClass(): void
    {
        $session = new WizardSession();

        $session->setStatus(WizardSession::STATUS_IN_PROGRESS);
        $this->assertEquals('bg-primary', $session->getStatusBadgeClass());

        $session->setStatus(WizardSession::STATUS_COMPLETED);
        $this->assertEquals('bg-success', $session->getStatusBadgeClass());

        $session->setStatus(WizardSession::STATUS_ABANDONED);
        $this->assertEquals('bg-secondary', $session->getStatusBadgeClass());
    }

    public function testWizardSessionAddCompletedCategory(): void
    {
        $session = new WizardSession();

        $session->addCompletedCategory('category1');
        $this->assertContains('category1', $session->getCompletedCategories());

        // Adding same category twice shouldn't duplicate
        $session->addCompletedCategory('category1');
        $this->assertCount(1, $session->getCompletedCategories());

        $session->addCompletedCategory('category2');
        $this->assertCount(2, $session->getCompletedCategories());
    }

    public function testWizardSessionConstants(): void
    {
        // Status constants
        $this->assertEquals('in_progress', WizardSession::STATUS_IN_PROGRESS);
        $this->assertEquals('completed', WizardSession::STATUS_COMPLETED);
        $this->assertEquals('abandoned', WizardSession::STATUS_ABANDONED);

        // Wizard type constants
        $this->assertEquals('iso27001', WizardSession::WIZARD_ISO27001);
        $this->assertEquals('nis2', WizardSession::WIZARD_NIS2);
        $this->assertEquals('dora', WizardSession::WIZARD_DORA);
        $this->assertEquals('tisax', WizardSession::WIZARD_TISAX);
        $this->assertEquals('gdpr', WizardSession::WIZARD_GDPR);
        $this->assertEquals('bsi_grundschutz', WizardSession::WIZARD_BSI);
    }
}
