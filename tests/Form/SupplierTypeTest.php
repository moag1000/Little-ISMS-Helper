<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Form\SupplierType;
use App\Repository\SupplierCriticalityLevelRepository;
use App\Service\ModuleConfigurationService;
use App\Service\TenantContext;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Form\PreloadedExtension;
use Symfony\Component\Form\Test\TypeTestCase;

/**
 * Coverage for SupplierType module-gating (P-6 audit-s2).
 *
 * Verifies that regulatory field-blocks (DSGVO/DPA, DORA, LkSG, MaRisk) are
 * only present in the form when their respective compliance modules are
 * active. Core fields must always exist regardless of module configuration.
 *
 * Anti-Regression for the CLAUDE.md rule "every feature that relates to an
 * optional compliance framework MUST be module-gated".
 */
#[AllowMockObjectsWithoutExpectations]
final class SupplierTypeTest extends TypeTestCase
{
    /** @var array<string, bool> */
    private array $activeModules = [];

    protected function getExtensions(): array
    {
        $moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')
            ->willReturnCallback(fn (string $key): bool => $this->activeModules[$key] ?? false);

        $criticalityRepo = $this->createMock(SupplierCriticalityLevelRepository::class);
        $criticalityRepo->method('findActiveByTenant')->willReturn([]);

        $tenantContext = $this->createMock(TenantContext::class);
        $tenantContext->method('getCurrentTenant')->willReturn(new Tenant());

        $type = new SupplierType($criticalityRepo, $tenantContext, $moduleConfig);

        return [new PreloadedExtension([$type], [])];
    }

    // ── Core fields (always present) ─────────────────────────────────

    #[Test]
    public function coreFieldsAlwaysPresent(): void
    {
        $this->activeModules = []; // No regulatory modules active

        $form = $this->factory->create(SupplierType::class, new Supplier());

        // Core supplier info
        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('description'));
        self::assertTrue($form->has('contactPerson'));
        self::assertTrue($form->has('email'));
        self::assertTrue($form->has('serviceProvided'));
        self::assertTrue($form->has('criticality'));
        self::assertTrue($form->has('status'));

        // Security assessment (core, non-regulatory)
        self::assertTrue($form->has('securityScore'));
        self::assertTrue($form->has('lastSecurityAssessment'));

        // ISO certifications (informational, no module gate needed)
        self::assertTrue($form->has('hasISO27001'));
        self::assertTrue($form->has('hasISO22301'));
        self::assertTrue($form->has('certifications'));
    }

    #[Test]
    public function vereinTenantWithoutModulesHasNoRegulatoryFields(): void
    {
        $this->activeModules = []; // Verein / KMU scenario

        $form = $this->factory->create(SupplierType::class, new Supplier());

        // No GDPR / privacy
        self::assertFalse($form->has('hasDPA'));
        self::assertFalse($form->has('dpaSignedDate'));
        self::assertFalse($form->has('gdprProcessorStatus'));
        self::assertFalse($form->has('gdprTransferMechanism'));

        // No DORA
        self::assertFalse($form->has('isDoraRelevant'));
        self::assertFalse($form->has('leiCode'));
        self::assertFalse($form->has('naceCode'));
        self::assertFalse($form->has('ictCriticality'));
        self::assertFalse($form->has('subcontractorChain'));

        // No LkSG
        self::assertFalse($form->has('lksgReportingObligation'));
        self::assertFalse($form->has('lksgRiskCategory'));
        self::assertFalse($form->has('lksgHumanRightsRiskScore'));

        // No MaRisk
        self::assertFalse($form->has('outsourcingClassification'));
        self::assertFalse($form->has('bafinNotificationRequired'));
    }

    // ── DSGVO / Privacy gate ─────────────────────────────────────────

    #[Test]
    public function privacyModuleActivatesGdprFields(): void
    {
        $this->activeModules = ['privacy' => true];

        $form = $this->factory->create(SupplierType::class, new Supplier());

        self::assertTrue($form->has('hasDPA'));
        self::assertTrue($form->has('dpaSignedDate'));
        self::assertTrue($form->has('gdprProcessorStatus'));
        self::assertTrue($form->has('gdprTransferMechanism'));
        self::assertTrue($form->has('gdprAvContractSigned'));
        self::assertTrue($form->has('gdprAvContractDate'));
    }

    // ── DORA / nis2_dora gate ────────────────────────────────────────

    #[Test]
    public function nis2DoraModuleActivatesDoraFields(): void
    {
        $this->activeModules = ['nis2_dora' => true];

        $form = $this->factory->create(SupplierType::class, new Supplier());

        self::assertTrue($form->has('isDoraRelevant'));
        self::assertTrue($form->has('leiCode'));
        self::assertTrue($form->has('naceCode'));
        self::assertTrue($form->has('countryOfHeadOffice'));
        self::assertTrue($form->has('ictCriticality'));
        self::assertTrue($form->has('ictFunctionType'));
        self::assertTrue($form->has('substitutability'));
        self::assertTrue($form->has('hasSubcontractors'));
        self::assertTrue($form->has('subcontractorChain'));
        self::assertTrue($form->has('processingLocations'));
        self::assertTrue($form->has('lastDoraAuditDate'));
        self::assertTrue($form->has('hasExitStrategy'));
    }

    // ── LkSG gate ────────────────────────────────────────────────────

    #[Test]
    public function lksgModuleActivatesLksgFields(): void
    {
        $this->activeModules = ['lksg' => true];

        $form = $this->factory->create(SupplierType::class, new Supplier());

        self::assertTrue($form->has('lksgReportingObligation'));
        self::assertTrue($form->has('lksgRiskCategory'));
        self::assertTrue($form->has('lksgHumanRightsRiskScore'));
        self::assertTrue($form->has('lksgEnvironmentalRiskScore'));
        self::assertTrue($form->has('lksgRiskAnalysisDate'));
        self::assertTrue($form->has('lksgComplaintMechanism'));
        self::assertTrue($form->has('lksgPreventionMeasures'));
    }

    // ── MaRisk gate ──────────────────────────────────────────────────

    #[Test]
    public function mariskModuleActivatesMariskFields(): void
    {
        $this->activeModules = ['marisk' => true];

        $form = $this->factory->create(SupplierType::class, new Supplier());

        self::assertTrue($form->has('outsourcingClassification'));
        self::assertTrue($form->has('outsourcingDueDiligenceCompleted'));
        self::assertTrue($form->has('outsourcingExitStrategy'));
        self::assertTrue($form->has('bafinNotificationRequired'));
        self::assertTrue($form->has('boardLevelRiskAcceptance'));
    }

    // ── Module isolation — one module should not leak into others ────

    #[Test]
    public function doraModuleDoesNotLeakIntoGdprFields(): void
    {
        $this->activeModules = ['nis2_dora' => true]; // DORA only

        $form = $this->factory->create(SupplierType::class, new Supplier());

        // DORA present
        self::assertTrue($form->has('isDoraRelevant'));

        // GDPR absent — privacy not active
        self::assertFalse($form->has('hasDPA'));
        self::assertFalse($form->has('gdprProcessorStatus'));

        // LkSG absent
        self::assertFalse($form->has('lksgReportingObligation'));

        // MaRisk absent
        self::assertFalse($form->has('outsourcingClassification'));
    }

    #[Test]
    public function allModulesActivePresentsAllFields(): void
    {
        $this->activeModules = [
            'privacy' => true,
            'nis2_dora' => true,
            'lksg' => true,
            'marisk' => true,
        ];

        $form = $this->factory->create(SupplierType::class, new Supplier());

        // Sampled fields from each module
        self::assertTrue($form->has('hasDPA'));               // privacy
        self::assertTrue($form->has('isDoraRelevant'));       // nis2_dora
        self::assertTrue($form->has('lksgRiskCategory'));     // lksg
        self::assertTrue($form->has('outsourcingClassification')); // marisk
    }
}
