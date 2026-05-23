<?php

declare(strict_types=1);

namespace App\Tests\Form;

use App\Entity\Supplier;
use App\Form\SupplierType;
use App\Service\ModuleConfigurationService;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Coverage for SupplierType module-gating (P-6 audit-s2) +
 * S14 Cluster A C1-05 supportedAssets EntityType linkage.
 *
 * Verifies that regulatory field-blocks (DSGVO/DPA, DORA, LkSG, MaRisk) are
 * only present in the form when their respective compliance modules are
 * active. Core fields must always exist regardless of module configuration.
 *
 * Anti-Regression for the CLAUDE.md rule "every feature that relates to an
 * optional compliance framework MUST be module-gated".
 *
 * Switched from TypeTestCase → KernelTestCase in S14 because SupplierType now
 * wires EntityType('supportedAssets'); EntityType requires a real
 * ManagerRegistry from the container.
 */
#[AllowMockObjectsWithoutExpectations]
final class SupplierTypeTest extends KernelTestCase
{
    private FormFactoryInterface $formFactory;
    /** @var array<string, bool> */
    private array $activeModules = [];

    protected function setUp(): void
    {
        self::bootKernel();

        // Swap ModuleConfigurationService for a controllable mock so each test
        // can toggle module activation independently.
        $moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')
            ->willReturnCallback(fn (string $key): bool => $this->activeModules[$key] ?? false);

        static::getContainer()->set(ModuleConfigurationService::class, $moduleConfig);

        $this->formFactory = static::getContainer()->get(FormFactoryInterface::class);
    }

    private function buildForm(): \Symfony\Component\Form\FormInterface
    {
        return $this->formFactory->create(SupplierType::class, new Supplier());
    }

    // ── Core fields (always present) ─────────────────────────────────

    #[Test]
    public function coreFieldsAlwaysPresent(): void
    {
        $this->activeModules = []; // No regulatory modules active

        $form = $this->buildForm();

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

        $form = $this->buildForm();

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

    // ── DSGVO / Privacy module ───────────────────────────────────────

    #[Test]
    public function privacyModuleActivatesDsgvoFields(): void
    {
        $this->activeModules = ['privacy' => true];

        $form = $this->buildForm();

        // GDPR Art. 28 — DPA fields
        self::assertTrue($form->has('hasDPA'));
        self::assertTrue($form->has('dpaSignedDate'));
        self::assertTrue($form->has('gdprProcessorStatus'));
        self::assertTrue($form->has('gdprTransferMechanism'));
        self::assertTrue($form->has('gdprAvContractSigned'));
        self::assertTrue($form->has('gdprAvContractDate'));

        // Other modules still off
        self::assertFalse($form->has('isDoraRelevant'));
        self::assertFalse($form->has('lksgReportingObligation'));
        self::assertFalse($form->has('outsourcingClassification'));
    }

    // ── DORA / nis2_dora module ──────────────────────────────────────

    #[Test]
    public function doraModuleActivatesDoraFields(): void
    {
        $this->activeModules = ['nis2_dora' => true];

        $form = $this->buildForm();

        // DORA Art. 28 — Register of Information
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

        // Other modules still off
        self::assertFalse($form->has('hasDPA'));
        self::assertFalse($form->has('lksgReportingObligation'));
        self::assertFalse($form->has('outsourcingClassification'));
    }

    // ── LkSG module ──────────────────────────────────────────────────

    #[Test]
    public function lksgModuleActivatesLksgFields(): void
    {
        $this->activeModules = ['lksg' => true];

        $form = $this->buildForm();

        // LkSG (Lieferkettensorgfaltspflichtengesetz)
        self::assertTrue($form->has('lksgReportingObligation'));
        self::assertTrue($form->has('lksgRiskCategory'));
        self::assertTrue($form->has('lksgHumanRightsRiskScore'));
        self::assertTrue($form->has('lksgEnvironmentalRiskScore'));
        self::assertTrue($form->has('lksgRiskAnalysisDate'));
        self::assertTrue($form->has('lksgComplaintMechanism'));
        self::assertTrue($form->has('lksgPreventionMeasures'));

        // Other modules still off
        self::assertFalse($form->has('hasDPA'));
        self::assertFalse($form->has('isDoraRelevant'));
        self::assertFalse($form->has('outsourcingClassification'));
    }

    // ── MaRisk module ────────────────────────────────────────────────

    #[Test]
    public function mariskModuleActivatesMariskFields(): void
    {
        $this->activeModules = ['marisk' => true];

        $form = $this->buildForm();

        // MaRisk AT 9 — Outsourcing management
        self::assertTrue($form->has('outsourcingClassification'));
        self::assertTrue($form->has('outsourcingDueDiligenceCompleted'));
        self::assertTrue($form->has('outsourcingDueDiligenceDate'));
        self::assertTrue($form->has('outsourcingExitStrategy'));
        self::assertTrue($form->has('bafinNotificationRequired'));
        self::assertTrue($form->has('bafinNotificationDate'));
        self::assertTrue($form->has('riskBearingCapacityImpact'));
        self::assertTrue($form->has('boardLevelRiskAcceptance'));
        self::assertTrue($form->has('complianceFunctionInvolvement'));
        self::assertTrue($form->has('internalAuditFunctionInvolvement'));

        // Other modules still off
        self::assertFalse($form->has('hasDPA'));
        self::assertFalse($form->has('isDoraRelevant'));
        self::assertFalse($form->has('lksgReportingObligation'));
    }

    // ── Combined modules ─────────────────────────────────────────────

    #[Test]
    public function multiCertifiedCustomerHasAllModuleFields(): void
    {
        $this->activeModules = [
            'privacy' => true,
            'nis2_dora' => true,
            'lksg' => true,
            'marisk' => true,
        ];

        $form = $this->buildForm();

        // All module-gated field groups should be present
        self::assertTrue($form->has('hasDPA'), 'privacy module → hasDPA');
        self::assertTrue($form->has('isDoraRelevant'), 'nis2_dora module → isDoraRelevant');
        self::assertTrue($form->has('lksgReportingObligation'), 'lksg module → lksgReportingObligation');
        self::assertTrue($form->has('outsourcingClassification'), 'marisk module → outsourcingClassification');

        // Core fields still present
        self::assertTrue($form->has('name'));
        self::assertTrue($form->has('criticality'));
    }

    // ── Backwards-compat assertion ───────────────────────────────────

    #[Test]
    public function privacyAndDoraFieldsBackwardsCompatible(): void
    {
        // Confirms that fields originally added to SupplierType when
        // privacy/DORA were not yet gated still appear when those modules
        // are active. Anti-regression for the migration to module-gating.
        $this->activeModules = ['privacy' => true, 'nis2_dora' => true];

        $form = $this->buildForm();

        // Pre-existing privacy fields
        self::assertTrue($form->has('hasDPA'));
        self::assertTrue($form->has('dpaSignedDate'));
        self::assertTrue($form->has('gdprProcessorStatus'));

        // Pre-existing DORA fields
        self::assertTrue($form->has('isDoraRelevant'));
        self::assertTrue($form->has('leiCode'));
        self::assertTrue($form->has('substitutability'));
    }

    // ── S14 Cluster A C1-05 — supportedAssets EntityType ─────────────

    #[Test]
    public function supportedAssetsFieldExistsRegardlessOfModule(): void
    {
        $this->activeModules = [];

        $form = $this->buildForm();

        // ISO 27001 A.5.21 supplier-asset linkage is not module-gated —
        // every supplier may reference internal assets.
        self::assertTrue($form->has('supportedAssets'));
    }
}
