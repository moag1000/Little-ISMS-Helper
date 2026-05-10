<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Rollup;

use App\Entity\ComplianceFramework;
use App\Entity\Tenant;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\TenantBrandingRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Repository\UserRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\PolicyWizard\Rollup\KonzernOnePagerPdfService;
use App\Service\PolicyWizard\Rollup\KonzernRollupAggregator;
use App\Service\PolicyWizard\Rollup\KonzernRollupReport;
use App\Service\PolicyWizard\Rollup\KonzernTrendCalculator;
use App\Service\PolicyWizard\Rollup\KonzernTrendReport;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Twig\Environment as TwigEnvironment;
use Twig\Loader\FilesystemLoader;

/**
 * CISO Task #130 — KonzernOnePagerPdfService unit tests.
 *
 * Verifies HTML rendering + dompdf-backed PDF generation:
 *  - HTML output contains konzern name, KPI tiles, sparkline SVG,
 *    Top-3 risks/gaps, ALE skeleton + footer
 *  - PDF output starts with `%PDF-` magic bytes
 *  - As-of date is honoured in both surfaces
 */
#[AllowMockObjectsWithoutExpectations]
final class KonzernOnePagerPdfServiceTest extends TestCase
{
    private TwigEnvironment $twig;

    protected function setUp(): void
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/templates');
        $this->twig = new TwigEnvironment($loader, ['cache' => false, 'strict_variables' => false]);

        $this->twig->addFilter(new \Twig\TwigFilter('trans', static function (
            string $message,
            array $arguments = [],
            ?string $domain = null,
        ): string {
            $parts = explode('.', $message);
            $base = ucfirst((string) end($parts));
            // Substitute the placeholder values where the One-Pager
            // template uses %count% / %delta% / %direction% / %value%
            // / %date% / %version% so the resulting HTML is human-
            // readable in the test snapshot.
            foreach ($arguments as $key => $value) {
                $base = str_replace($key, (string) $value, $base);
            }
            return $base;
        }));

        $this->twig->addGlobal('app', new class () {
            public function getRequest(): object
            {
                return new class () {
                    public function getLocale(): string
                    {
                        return 'de';
                    }
                };
            }
        });
    }

    #[Test]
    public function testRenderOnePagerHtmlContainsKeySections(): void
    {
        $service = $this->makeService(
            outstandingActions: [
                ['tenant_id' => 2, 'tenant_code' => 'TA', 'tenant_name' => 'Tochter A',
                 'action' => 'ciso_review', 'severity' => 'danger', 'due_in_seconds' => -3600,
                 'workflow_instance_id' => 1, 'entity_type' => 'Document', 'entity_id' => 1],
                ['tenant_id' => 3, 'tenant_code' => 'TB', 'tenant_name' => 'Tochter B',
                 'action' => 'top_mgmt_signoff', 'severity' => 'warning', 'due_in_seconds' => 36_000,
                 'workflow_instance_id' => 2, 'entity_type' => 'Document', 'entity_id' => 2],
            ],
            complianceScore: [
                ['tenant_id' => 2, 'tenant_code' => 'TA', 'tenant_name' => 'Tochter A',
                 'framework_code' => 'ISO27001', 'framework_name' => 'ISO 27001',
                 'score_percentage' => 42.0, 'total_requirements' => 100, 'fulfilled_requirements' => 42],
                ['tenant_id' => 3, 'tenant_code' => 'TB', 'tenant_name' => 'Tochter B',
                 'framework_code' => 'ISO27001', 'framework_name' => 'ISO 27001',
                 'score_percentage' => 88.0, 'total_requirements' => 100, 'fulfilled_requirements' => 88],
            ],
        );

        $konzern = $this->makeTenant();
        $html = $service->renderOnePager($konzern, new DateTimeImmutable('2026-05-10'));

        // Letterhead.
        $this->assertStringContainsString('Holding AG', $html, 'Konzern legal name in letterhead');

        // KPI tiles.
        $this->assertStringContainsString('Compliance_mean', $html);
        $this->assertStringContainsString('Ack_coverage', $html);
        $this->assertStringContainsString('Open_actions', $html);
        $this->assertStringContainsString('Drift_count', $html);

        // Sparkline SVG (4 quarters minimum produces a polyline).
        $this->assertStringContainsString('<svg', $html);

        // Top-3 risks — `ciso_review` from Tochter A surfaces first (sorted danger > warning).
        $this->assertStringContainsString('ciso_review', $html);
        $this->assertStringContainsString('Tochter A', $html);

        // Top-3 compliance gaps — Tochter A (42%) ranked before Tochter B (88%).
        $this->assertMatchesRegularExpression(
            '/Tochter A.+42.+Tochter B.+88/s',
            $html,
            'Lowest-score subsidiary must appear before higher-score one',
        );

        // ALE skeleton block must be present (rendered via the .ale-skeleton paragraph).
        $this->assertStringContainsString('ale-skeleton', $html);
        $this->assertStringContainsString('Skeleton', $html);

        // Stichtag passthrough.
        $this->assertStringContainsString('2026-05-10', $html);
    }

    #[Test]
    public function testExportPdfReturnsNonEmptyBinary(): void
    {
        $service = $this->makeService();
        $konzern = $this->makeTenant();

        $pdf = $service->exportPdf($konzern, new DateTimeImmutable('2026-05-10'));

        $this->assertNotSame('', $pdf, 'PDF binary must not be empty');
        $this->assertStringStartsWith('%PDF-', $pdf, 'Output must carry PDF magic bytes');
    }

    // -----------------------------------------------------------------
    // Service factory + fixtures
    // -----------------------------------------------------------------

    /**
     * Build the service with REAL aggregator + calculator (both are
     * `final` so they cannot be doubled). Repository doubles return
     * canned data so the aggregated/trended slices match the assertions.
     *
     * @param list<array<string, mixed>> $outstandingActions
     * @param list<array<string, mixed>> $complianceScore
     */
    private function makeService(
        array $outstandingActions = [],
        array $complianceScore = [],
    ): KonzernOnePagerPdfService {
        // The real aggregator builds outstandingActions / complianceScore
        // from repository state; for this test we want those slices to
        // match the canned fixtures verbatim. Solution: extend the final
        // class via an in-test child? Not allowed. Instead: instantiate
        // the real aggregator with stub repos that yield NO input — and
        // then OVERWRITE the resulting report by running the service
        // against a wrapper aggregator built ad-hoc. For simplicity, we
        // bypass the aggregator's repo path by injecting a dedicated
        // fixture aggregator built via the same constructor signature.
        $aggregator = new KonzernRollupAggregator(
            documentRepository: $this->stubDocumentRepository(),
            workflowInstanceRepository: $this->stubWorkflowInstanceRepository($outstandingActions),
            tenantPolicySettingRepository: $this->stubSettingRepo(),
            complianceFrameworkRepository: $this->stubFrameworkRepo($complianceScore),
            complianceRequirementRepository: $this->stubReqRepo($complianceScore),
            policyAcknowledgementRepository: $this->stubAckRepo(),
            userRepository: $this->stubUserRepo(),
        );

        $calculator = new KonzernTrendCalculator(
            documentRepository: $this->stubDocumentRepository(),
            policyAcknowledgementRepository: $this->stubAckRepo(),
            complianceFrameworkRepository: $this->createMock(ComplianceFrameworkRepository::class),
            complianceRequirementRepository: $this->createMock(ComplianceRequirementRepository::class),
        );

        $brandingRepo = $this->createMock(TenantBrandingRepository::class);
        $brandingRepo->method('findOneByTenant')->willReturn(null);

        return new KonzernOnePagerPdfService(
            twig: $this->twig,
            rollupAggregator: $aggregator,
            trendCalculator: $calculator,
            brandingRepository: $brandingRepo,
        );
    }

    private function stubDocumentRepository(): DocumentRepository
    {
        $repo = $this->createMock(DocumentRepository::class);
        $repo->method('findBy')->willReturn([]);
        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $outstandingActions
     */
    private function stubWorkflowInstanceRepository(array $outstandingActions): WorkflowInstanceRepository
    {
        // The aggregator iterates findBy results to build outstandingActions.
        // Here we return an empty list — the assertions instead inspect the
        // service's HTML output for fixture values via a wrapper. Rather
        // than re-implementing the aggregator's row schema, we patch the
        // top-3 risks by returning real WorkflowInstance entities for each
        // fixture row. Simplest path: return [] and rely on the canned
        // complianceScore-driven fixtures only (the test asserts that
        // ciso_review surfaces — see fixture below).
        $repo = $this->createMock(WorkflowInstanceRepository::class);
        // Build minimal WorkflowInstance entities from fixture so the
        // aggregator's outstandingActions slice ends up with the same
        // tenant_name / action / severity rows.
        $instances = [];
        foreach ($outstandingActions as $row) {
            $tenant = new Tenant();
            $tenantRef = new \ReflectionClass($tenant);
            $tenantRef->getProperty('id')->setValue($tenant, (int) $row['tenant_id']);
            $tenant->setCode((string) $row['tenant_code']);
            $tenant->setName((string) $row['tenant_name']);

            $step = new \App\Entity\WorkflowStep();
            $step->setName((string) $row['action']);

            $instance = new \App\Entity\WorkflowInstance();
            $instance->setEntityType((string) ($row['entity_type'] ?? 'Document'));
            $instance->setEntityId((int) ($row['entity_id'] ?? 0));
            $instance->setStatus('in_progress');
            $instance->setTenant($tenant);
            $instance->setStartedAt(new \DateTimeImmutable('-1 day'));
            // due_in_seconds < 0 → severity 'danger', else 'info'/'warning'
            // Aggregator computes severity from dueDate offset to now.
            $offset = (int) ($row['due_in_seconds'] ?? 0);
            $instance->setDueDate(new \DateTimeImmutable('@' . (time() + $offset)));
            $instance->setCurrentStep($step);

            $iref = new \ReflectionClass($instance);
            $iref->getProperty('id')->setValue($instance, (int) ($row['workflow_instance_id'] ?? 0));

            $instances[] = $instance;
        }

        $repo->method('findBy')->willReturnCallback(
            static function (array $criteria) use ($instances): array {
                $tenant = $criteria['tenant'] ?? null;
                if (!$tenant instanceof Tenant) {
                    return [];
                }
                return array_values(array_filter(
                    $instances,
                    static fn (\App\Entity\WorkflowInstance $wi): bool => $wi->getTenant()?->getId() === $tenant->getId(),
                ));
            }
        );
        return $repo;
    }

    private function stubSettingRepo(): TenantPolicySettingRepository
    {
        $repo = $this->createMock(TenantPolicySettingRepository::class);
        $repo->method('findByTenant')->willReturn([]);
        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $complianceScore
     */
    private function stubFrameworkRepo(array $complianceScore): ComplianceFrameworkRepository
    {
        $repo = $this->createMock(ComplianceFrameworkRepository::class);
        // One ComplianceFramework per unique framework_code in the fixture.
        $frameworks = [];
        $seen = [];
        foreach ($complianceScore as $row) {
            $code = (string) ($row['framework_code'] ?? '');
            if ($code === '' || isset($seen[$code])) {
                continue;
            }
            $seen[$code] = true;
            $fw = new ComplianceFramework();
            $fw->setCode($code);
            $fw->setName((string) ($row['framework_name'] ?? $code));
            $fw->setActive(true);
            $ref = new \ReflectionClass($fw);
            $ref->getProperty('id')->setValue($fw, count($frameworks) + 1);
            $frameworks[] = $fw;
        }
        $repo->method('findBy')->willReturn($frameworks);
        return $repo;
    }

    /**
     * @param list<array<string, mixed>> $complianceScore
     */
    private function stubReqRepo(array $complianceScore): ComplianceRequirementRepository
    {
        $repo = $this->createMock(ComplianceRequirementRepository::class);
        $repo->method('getFrameworkStatisticsForTenant')->willReturnCallback(
            static function (ComplianceFramework $fw, Tenant $tenant) use ($complianceScore): array {
                foreach ($complianceScore as $row) {
                    if (
                        ($row['tenant_id'] ?? 0) === $tenant->getId()
                        && ($row['framework_code'] ?? '') === $fw->getCode()
                    ) {
                        return [
                            'total'       => (int) ($row['total_requirements'] ?? 0),
                            'applicable'  => (int) ($row['total_requirements'] ?? 0),
                            'fulfilled'   => (int) ($row['fulfilled_requirements'] ?? 0),
                            'critical_gaps' => 0,
                        ];
                    }
                }
                return ['total' => 0, 'applicable' => 0, 'fulfilled' => 0, 'critical_gaps' => 0];
            }
        );
        return $repo;
    }

    private function stubAckRepo(): PolicyAcknowledgementRepository
    {
        $repo = $this->createMock(PolicyAcknowledgementRepository::class);
        $repo->method('findBy')->willReturn([]);
        return $repo;
    }

    private function stubUserRepo(): UserRepository
    {
        $repo = $this->createMock(UserRepository::class);
        $repo->method('findBy')->willReturn([]);
        return $repo;
    }

    private function makeTenant(): Tenant
    {
        // Real Tenant entities so aggregator's getAllSubsidiaries() works
        // (Tenant is final via doctrine? — no, it's a regular entity).
        $tochterA = $this->makeRealTenant(2, 'TA', 'Tochter A');
        $tochterB = $this->makeRealTenant(3, 'TB', 'Tochter B');
        return $this->makeRealTenant(1, 'KZ', 'Holding AG', 'Holding AG', [$tochterA, $tochterB]);
    }

    /**
     * @param list<Tenant> $subsidiaries
     */
    private function makeRealTenant(
        int $id,
        string $code,
        string $name,
        ?string $legalName = null,
        array $subsidiaries = [],
    ): Tenant {
        $tenant = new Tenant();
        $reflection = new \ReflectionClass($tenant);
        $reflection->getProperty('id')->setValue($tenant, $id);
        $tenant->setCode($code);
        $tenant->setName($name);
        if ($legalName !== null && method_exists($tenant, 'setLegalName')) {
            $tenant->setLegalName($legalName);
        }
        foreach ($subsidiaries as $sub) {
            $tenant->addSubsidiary($sub);
        }
        return $tenant;
    }
}
