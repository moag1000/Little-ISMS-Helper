<?php

declare(strict_types=1);

namespace App\Tests\Service\Search;

use App\Repository\AssetRepository;
use App\Repository\AuditFindingRepository;
use App\Repository\BCExerciseRepository;
use App\Repository\BusinessContinuityPlanRepository;
use App\Repository\BusinessProcessRepository;
use App\Repository\ChangeRequestRepository;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\ConsentRepository;
use App\Repository\ControlRepository;
use App\Repository\CorrectiveActionRepository;
use App\Repository\CrisisTeamRepository;
use App\Repository\DataBreachRepository;
use App\Repository\DataProtectionImpactAssessmentRepository;
use App\Repository\DataSubjectRequestRepository;
use App\Repository\DocumentRepository;
use App\Repository\IncidentRepository;
use App\Repository\InterestedPartyRepository;
use App\Repository\InternalAuditRepository;
use App\Repository\ISMSObjectiveRepository;
use App\Repository\ManagementReviewRepository;
use App\Repository\PatchRepository;
use App\Repository\PersonRepository;
use App\Repository\ProcessingActivityRepository;
use App\Repository\RiskRepository;
use App\Repository\SupplierRepository;
use App\Repository\ThreatIntelligenceRepository;
use App\Repository\TrainingRepository;
use App\Repository\VulnerabilityRepository;
use App\Service\ModuleConfigurationService;
use App\Service\Search\SearchService;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Unit tests for SearchService module-gating behaviour.
 *
 * All repository mocks are dumb stubs — we only care whether the gate
 * returns early (empty array) or reaches the DB layer (non-empty array
 * when the repository is configured to return a fixture result).
 */
#[AllowMockObjectsWithoutExpectations]
class SearchServiceTest extends TestCase
{
    private MockObject $moduleConfig;
    private MockObject $urlGenerator;
    private MockObject $authChecker;

    // Repository mocks (all dumb stubs unless specifically configured)
    private MockObject $assetRepo;
    private MockObject $riskRepo;
    private MockObject $incidentRepo;
    private MockObject $trainingRepo;
    private MockObject $controlRepo;
    private MockObject $documentRepo;
    private MockObject $supplierRepo;
    private MockObject $processingActivityRepo;
    private MockObject $dpiaRepo;
    private MockObject $dataBreachRepo;
    private MockObject $auditFindingRepo;
    private MockObject $correctiveActionRepo;
    private MockObject $changeRequestRepo;
    private MockObject $internalAuditRepo;
    private MockObject $businessProcessRepo;
    private MockObject $bcPlanRepo;
    private MockObject $bcExerciseRepo;
    private MockObject $crisisTeamRepo;
    private MockObject $managementReviewRepo;
    private MockObject $ismsObjectiveRepo;
    private MockObject $vulnerabilityRepo;
    private MockObject $patchRepo;
    private MockObject $threatIntelRepo;
    private MockObject $personRepo;
    private MockObject $interestedPartyRepo;
    private MockObject $consentRepo;
    private MockObject $dataSubjectRequestRepo;
    private MockObject $complianceFrameworkRepo;
    private MockObject $complianceRequirementRepo;

    protected function setUp(): void
    {
        $this->moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $this->urlGenerator = $this->createMock(UrlGeneratorInterface::class);
        $this->authChecker = $this->createMock(AuthorizationCheckerInterface::class);

        // URL generator always returns a stub URL so navigation search doesn't crash
        $this->urlGenerator->method('generate')->willReturn('/stub');
        // Auth checker: deny everything by default (overridden per test)
        $this->authChecker->method('isGranted')->willReturn(false);

        $this->assetRepo = $this->createMock(AssetRepository::class);
        $this->riskRepo = $this->createMock(RiskRepository::class);
        $this->incidentRepo = $this->createMock(IncidentRepository::class);
        $this->trainingRepo = $this->createMock(TrainingRepository::class);
        $this->controlRepo = $this->createMock(ControlRepository::class);
        $this->documentRepo = $this->createMock(DocumentRepository::class);
        $this->supplierRepo = $this->createMock(SupplierRepository::class);
        $this->processingActivityRepo = $this->createMock(ProcessingActivityRepository::class);
        $this->dpiaRepo = $this->createMock(DataProtectionImpactAssessmentRepository::class);
        $this->dataBreachRepo = $this->createMock(DataBreachRepository::class);
        $this->auditFindingRepo = $this->createMock(AuditFindingRepository::class);
        $this->correctiveActionRepo = $this->createMock(CorrectiveActionRepository::class);
        $this->changeRequestRepo = $this->createMock(ChangeRequestRepository::class);
        $this->internalAuditRepo = $this->createMock(InternalAuditRepository::class);
        $this->businessProcessRepo = $this->createMock(BusinessProcessRepository::class);
        $this->bcPlanRepo = $this->createMock(BusinessContinuityPlanRepository::class);
        $this->bcExerciseRepo = $this->createMock(BCExerciseRepository::class);
        $this->crisisTeamRepo = $this->createMock(CrisisTeamRepository::class);
        $this->managementReviewRepo = $this->createMock(ManagementReviewRepository::class);
        $this->ismsObjectiveRepo = $this->createMock(ISMSObjectiveRepository::class);
        $this->vulnerabilityRepo = $this->createMock(VulnerabilityRepository::class);
        $this->patchRepo = $this->createMock(PatchRepository::class);
        $this->threatIntelRepo = $this->createMock(ThreatIntelligenceRepository::class);
        $this->personRepo = $this->createMock(PersonRepository::class);
        $this->interestedPartyRepo = $this->createMock(InterestedPartyRepository::class);
        $this->consentRepo = $this->createMock(ConsentRepository::class);
        $this->dataSubjectRequestRepo = $this->createMock(DataSubjectRequestRepository::class);
        $this->complianceFrameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $this->complianceRequirementRepo = $this->createMock(ComplianceRequirementRepository::class);
    }

    // -------------------------------------------------------------------------
    // Helpers: instantiate SearchService with current (or custom) mocks
    // -------------------------------------------------------------------------

    private function buildServiceWith(
        ModuleConfigurationService $moduleConfig,
        AuthorizationCheckerInterface $authChecker,
    ): SearchService {
        return new SearchService(
            $this->assetRepo,
            $this->riskRepo,
            $this->incidentRepo,
            $this->trainingRepo,
            $this->controlRepo,
            $this->documentRepo,
            $this->supplierRepo,
            $this->processingActivityRepo,
            $this->dpiaRepo,
            $this->dataBreachRepo,
            $this->auditFindingRepo,
            $this->correctiveActionRepo,
            $this->changeRequestRepo,
            $this->internalAuditRepo,
            $this->businessProcessRepo,
            $this->bcPlanRepo,
            $this->bcExerciseRepo,
            $this->crisisTeamRepo,
            $this->managementReviewRepo,
            $this->ismsObjectiveRepo,
            $this->vulnerabilityRepo,
            $this->patchRepo,
            $this->threatIntelRepo,
            $this->personRepo,
            $this->interestedPartyRepo,
            $this->consentRepo,
            $this->dataSubjectRequestRepo,
            $this->complianceFrameworkRepo,
            $this->complianceRequirementRepo,
            $this->urlGenerator,
            $authChecker,
            $moduleConfig,
        );
    }

    private function buildService(): SearchService
    {
        return new SearchService(
            $this->assetRepo,
            $this->riskRepo,
            $this->incidentRepo,
            $this->trainingRepo,
            $this->controlRepo,
            $this->documentRepo,
            $this->supplierRepo,
            $this->processingActivityRepo,
            $this->dpiaRepo,
            $this->dataBreachRepo,
            $this->auditFindingRepo,
            $this->correctiveActionRepo,
            $this->changeRequestRepo,
            $this->internalAuditRepo,
            $this->businessProcessRepo,
            $this->bcPlanRepo,
            $this->bcExerciseRepo,
            $this->crisisTeamRepo,
            $this->managementReviewRepo,
            $this->ismsObjectiveRepo,
            $this->vulnerabilityRepo,
            $this->patchRepo,
            $this->threatIntelRepo,
            $this->personRepo,
            $this->interestedPartyRepo,
            $this->consentRepo,
            $this->dataSubjectRequestRepo,
            $this->complianceFrameworkRepo,
            $this->complianceRequirementRepo,
            $this->urlGenerator,
            $this->authChecker,
            $this->moduleConfig,
        );
    }

    /**
     * Configure the module mock to return false for all modules (all inactive).
     */
    private function allModulesInactive(): void
    {
        $this->moduleConfig->method('isModuleActive')->willReturn(false);
    }

    /**
     * Configure the module mock to return true for all modules (all active).
     */
    private function allModulesActive(): void
    {
        $this->moduleConfig->method('isModuleActive')->willReturn(true);
    }

    // -------------------------------------------------------------------------
    // Privacy module gate tests
    // -------------------------------------------------------------------------

    #[Test]
    public function testPrivacyEntitiesHiddenWhenModuleInactive(): void
    {
        $this->moduleConfig->method('isModuleActive')->willReturnMap([
            ['privacy', false],
            // All others irrelevant for this test
        ]);

        $service = $this->buildService();

        $this->assertSame([], $service->searchProcessingActivities('test', null));
        $this->assertSame([], $service->searchDpias('test', null));
        $this->assertSame([], $service->searchDataBreaches('test', null));
        $this->assertSame([], $service->searchConsents('test', null));
        $this->assertSame([], $service->searchDataSubjectRequests('test', null));
    }

    #[Test]
    public function testBcmEntitiesHiddenWhenModuleInactive(): void
    {
        $this->moduleConfig->method('isModuleActive')->willReturnMap([
            ['bcm', false],
        ]);

        $service = $this->buildService();

        $this->assertSame([], $service->searchBusinessProcesses('test', null));
        $this->assertSame([], $service->searchBcPlans('test', null));
        $this->assertSame([], $service->searchBcExercises('test', null));
        $this->assertSame([], $service->searchCrisisTeams('test', null));
    }

    #[Test]
    public function testComplianceEntitiesHiddenWhenModuleInactive(): void
    {
        $this->moduleConfig->method('isModuleActive')->willReturnMap([
            ['compliance', false],
        ]);

        $service = $this->buildService();

        $this->assertSame([], $service->searchComplianceFrameworks('test'));
        $this->assertSame([], $service->searchComplianceRequirements('test'));
    }

    #[Test]
    public function testAuditsEntitiesHiddenWhenModuleInactive(): void
    {
        $this->moduleConfig->method('isModuleActive')->willReturnMap([
            ['audits', false],
        ]);

        $service = $this->buildService();

        $this->assertSame([], $service->searchAuditFindings('test', null));
        $this->assertSame([], $service->searchInternalAudits('test', null));
    }

    #[Test]
    public function testCoreEntitySearchObjectivesAlwaysQueries(): void
    {
        // searchObjectives has NO module gate (objectives module is always required).
        // Even with every other module inactive, the method must not short-circuit —
        // confirmed by the repo being called exactly once.
        $this->moduleConfig->method('isModuleActive')->willReturn(false);

        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->ismsObjectiveRepo->expects($this->once())
            ->method('createQueryBuilder')
            ->willReturn($qb);

        $service = $this->buildService();
        $result = $service->searchObjectives('anything', null);

        $this->assertSame([], $result);
    }

    // -------------------------------------------------------------------------
    // Navigation gate tests
    // -------------------------------------------------------------------------

    #[Test]
    public function testNavigationGatedByModule(): void
    {
        // controls inactive → app_soa_index must not appear in nav results
        $moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')->willReturnCallback(
            static fn(string $key): bool => $key !== 'controls'
        );

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        // Grant all roles so role-check is not the limiting factor
        $authChecker->method('isGranted')->willReturn(true);

        $service = $this->buildServiceWith($moduleConfig, $authChecker);
        $results = $service->searchNavigation('SoA');

        $titles = array_column($results, 'title');
        $this->assertNotContains('SoA – Statement of Applicability', $titles, 'SoA nav must be hidden when controls module is inactive');
    }

    #[Test]
    public function testNavigationSoaAppearsWhenControlsActive(): void
    {
        // controls active → app_soa_index must appear
        $moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')->willReturn(true);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $service = $this->buildServiceWith($moduleConfig, $authChecker);
        $results = $service->searchNavigation('SoA');

        $titles = array_column($results, 'title');
        $this->assertContains('SoA – Statement of Applicability', $titles, 'SoA nav must appear when controls module is active');
    }

    #[Test]
    public function testNavigationCoreRoutesAlwaysAppearRegardlessOfModules(): void
    {
        // All modules inactive — core ungated routes must still match (role_user granted)
        $moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')->willReturn(false);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        $service = $this->buildServiceWith($moduleConfig, $authChecker);
        $results = $service->searchNavigation('Dashboard');

        $titles = array_column($results, 'title');
        $this->assertContains('Dashboard', $titles, 'Core dashboard nav must always appear');
    }

    // -------------------------------------------------------------------------
    // testCoreEntitiesAlwaysVisible
    // -------------------------------------------------------------------------

    #[Test]
    public function testCoreEntitiesAlwaysVisible(): void
    {
        // Even with all other modules inactive, objectives must be queryable
        // (no early return). We assert it doesn't throw and returns an array.
        $this->moduleConfig->method('isModuleActive')->willReturn(false);

        $query = $this->createMock(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createMock(QueryBuilder::class);
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('setMaxResults')->willReturnSelf();
        $qb->method('andWhere')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->ismsObjectiveRepo->method('createQueryBuilder')->willReturn($qb);

        $service = $this->buildService();

        $result = $service->searchObjectives('isms-ziel', null);
        $this->assertIsArray($result, 'searchObjectives must always return an array regardless of module state');
    }

    // -------------------------------------------------------------------------
    // Navigation alias tests (alias/synonym matching)
    // -------------------------------------------------------------------------

    private function buildAdminAuthService(): SearchService
    {
        $moduleConfig = $this->createMock(ModuleConfigurationService::class);
        $moduleConfig->method('isModuleActive')->willReturn(true);

        $authChecker = $this->createMock(AuthorizationCheckerInterface::class);
        $authChecker->method('isGranted')->willReturn(true);

        return $this->buildServiceWith($moduleConfig, $authChecker);
    }

    #[Test]
    public function testNavigationMatchesByAlias(): void
    {
        $service = $this->buildAdminAuthService();

        $results = $service->searchNavigation('organisation');

        $routes = array_column($results, 'url');
        // Should find the tenant management / org-settings entry via alias
        $this->assertNotEmpty($results, 'Query "organisation" must match at least one nav entry via alias');
    }

    #[Test]
    public function testNavigationMatchesByGermanAndEnglishAliases(): void
    {
        $service = $this->buildAdminAuthService();

        $deResults = $service->searchNavigation('Berechtigung');
        $enResults = $service->searchNavigation('Permission');

        $this->assertNotEmpty($deResults, '"Berechtigung" must match roles/permissions nav entry');
        $this->assertNotEmpty($enResults, '"Permission" must match roles/permissions nav entry');

        // Both queries must find the same route (Rollenverwaltung)
        $deTitles = array_column($deResults, 'title');
        $enTitles = array_column($enResults, 'title');
        $this->assertContains('Rollenverwaltung', $deTitles, 'Rollenverwaltung must appear for "Berechtigung"');
        $this->assertContains('Rollenverwaltung', $enTitles, 'Rollenverwaltung must appear for "Permission"');
    }

    #[Test]
    public function testNavigationAliasCaseInsensitive(): void
    {
        $service = $this->buildAdminAuthService();

        $upper = $service->searchNavigation('ORGANISATION');
        $lower = $service->searchNavigation('organisation');
        $mixed = $service->searchNavigation('Organisation');

        $this->assertNotEmpty($upper, 'Uppercase alias query must match');
        $this->assertNotEmpty($lower, 'Lowercase alias query must match');
        $this->assertNotEmpty($mixed, 'Mixed-case alias query must match');

        // All three must return the same count of results
        $this->assertSameSize($upper, $lower, 'Case variants must return same result count');
        $this->assertSameSize($lower, $mixed, 'Case variants must return same result count');
    }

    #[Test]
    public function testNavigationAliasShortQueryRejected(): void
    {
        $service = $this->buildAdminAuthService();

        $single = $service->searchNavigation('a');
        $empty = $service->searchNavigation('');
        $whitespace = $service->searchNavigation('  ');

        $this->assertSame([], $single, 'Single-character query must return empty');
        $this->assertSame([], $empty, 'Empty query must return empty');
        $this->assertSame([], $whitespace, 'Whitespace-only query must return empty');
    }

    #[Test]
    public function testNavigationBackupAliasMatches(): void
    {
        $service = $this->buildAdminAuthService();

        $results = $service->searchNavigation('Backup');
        $titles = array_column($results, 'title');

        $this->assertContains('Backup', $titles, '"Backup" query must return the Backup nav entry');
    }

    #[Test]
    public function testNavigationLogoAliasFindsBranding(): void
    {
        $service = $this->buildAdminAuthService();

        $results = $service->searchNavigation('Logo');
        $titles = array_column($results, 'title');

        $this->assertContains('Branding & Logo', $titles, '"Logo" alias must find the Branding nav entry');
    }

    #[Test]
    public function testNavigationReportsAliasFinder(): void
    {
        $service = $this->buildAdminAuthService();

        $results = $service->searchNavigation('Reports');
        $this->assertNotEmpty($results, '"Reports" must match at least one report-related nav entry');

        $titles = array_column($results, 'title');
        $this->assertContains('Berichte', $titles, '"Reports" alias must match the Berichte entry');
    }

    #[Test]
    public function testNavigationMfaAliasMatches(): void
    {
        $service = $this->buildAdminAuthService();

        $results = $service->searchNavigation('2FA');
        $titles = array_column($results, 'title');

        $this->assertContains('MFA / Zwei-Faktor-Authentifizierung', $titles, '"2FA" alias must find MFA nav entry');
    }
}
