<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Rollup;

use App\Entity\ComplianceFramework;
use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\TenantPolicySetting;
use App\Entity\User;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\TenantPolicySettingRepository;
use App\Repository\UserRepository;
use App\Repository\WorkflowInstanceRepository;
use App\Service\PolicyWizard\Rollup\KonzernRollupAggregator;
use App\Service\PolicyWizard\Rollup\KonzernRollupReport;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Policy-Wizard W7-B — KonzernRollupAggregator unit tests.
 *
 * Pure unit tests: no DB roundtrip. Repository doubles return canned
 * data so the aggregator's six dashboard slices can be asserted in
 * isolation.
 */
#[AllowMockObjectsWithoutExpectations]
final class KonzernRollupAggregatorTest extends TestCase
{
    /** @var array<int, list<Document>> tenantId => Documents */
    private array $documentsByTenant = [];

    /** @var array<int, list<WorkflowInstance>> tenantId => WorkflowInstances */
    private array $workflowsByTenant = [];

    /** @var array<int, list<TenantPolicySetting>> tenantId => Settings */
    private array $settingsByTenant = [];

    /** @var array<int, list<PolicyAcknowledgement>> tenantId => Acks */
    private array $acksByTenant = [];

    /** @var array<int, list<User>> tenantId => Users */
    private array $usersByTenant = [];

    /** @var list<ComplianceFramework> */
    private array $frameworks = [];

    /** @var array<string, array{total:int, applicable:int, fulfilled:int, critical_gaps?:int}> "tenantId|frameworkId" */
    private array $frameworkStats = [];

    protected function setUp(): void
    {
        $this->documentsByTenant = [];
        $this->workflowsByTenant = [];
        $this->settingsByTenant = [];
        $this->acksByTenant = [];
        $this->usersByTenant = [];
        $this->frameworks = [];
        $this->frameworkStats = [];
    }

    #[Test]
    public function testAggregateReturnsEmptyForStandaloneTenant(): void
    {
        $standalone = $this->makeTenant(1, 'STAND', 'Standalone GmbH', subsidiaries: []);
        $aggregator = $this->makeAggregator();

        $report = $aggregator->aggregateForKonzern($standalone);

        $this->assertInstanceOf(KonzernRollupReport::class, $report);
        $this->assertSame(0, $report->subsidiaryCount);
        $this->assertTrue($report->isEmpty());
        $this->assertSame([], $report->policyCoverageMatrix);
        $this->assertSame([], $report->outstandingActions);
        $this->assertSame([], $report->complianceScore);
        $this->assertSame([], $report->settingsDriftRows);
        $this->assertSame([], $report->acknowledgmentCoverage);
        $this->assertCount(1, $report->tenantTree);
        $this->assertSame('STAND', $report->tenantTree[0]['code']);
    }

    #[Test]
    public function testAggregateReturnsAllSubsidiaries(): void
    {
        $tochterA = $this->makeTenant(2, 'TA', 'Tochter A');
        $tochterB = $this->makeTenant(3, 'TB', 'Tochter B');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochterA, $tochterB]);

        $aggregator = $this->makeAggregator();
        $report = $aggregator->aggregateForKonzern($konzern);

        $this->assertSame(2, $report->subsidiaryCount);
        $this->assertFalse($report->isEmpty());
        $this->assertCount(1, $report->tenantTree, 'tree has the konzern as the single root');
        $this->assertCount(2, $report->tenantTree[0]['children']);
        $childCodes = array_column($report->tenantTree[0]['children'], 'code');
        $this->assertContains('TA', $childCodes);
        $this->assertContains('TB', $childCodes);
    }

    #[Test]
    public function testPolicyCoverageMatrixCorrect(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        // Two ISO 27001 docs + one DORA doc + one undefined → "Other".
        $this->setDocuments($tochter, [
            $this->makeDocument(101, $tochter, 'iso27001_a811', 'active'),
            $this->makeDocument(102, $tochter, 'iso27001_a51', 'published'),
            $this->makeDocument(103, $tochter, 'dora_ict_risk', 'published'),
            $this->makeDocument(104, $tochter, '', 'active'),
            // archived must NOT count
            $this->makeDocument(105, $tochter, 'iso27001_a81', 'archived'),
        ]);

        $aggregator = $this->makeAggregator();
        $report = $aggregator->aggregateForKonzern($konzern);

        $tochterRow = null;
        foreach ($report->policyCoverageMatrix as $row) {
            if ($row['tenant_id'] === 2) {
                $tochterRow = $row;
                break;
            }
        }
        $this->assertNotNull($tochterRow, 'Tochter row must be present in coverage matrix');
        $this->assertSame(2, $tochterRow['standards']['ISO 27001']['policy_count']);
        $this->assertSame(1, $tochterRow['standards']['DORA']['policy_count']);
        $this->assertSame(1, $tochterRow['standards']['Other']['policy_count']);
        $this->assertArrayNotHasKey('NIS2', $tochterRow['standards']);
    }

    #[Test]
    public function testSettingsDriftDetected(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        // Tochter override 192 vs Konzern floor 256 → KonzernPushDownService
        // would have stored a `_meta.settings_drift_detected` marker.
        $driftedSetting = $this->makeSetting(2, 'crypto.minimum_key_length', [
            'value' => 192,
            '_meta' => [
                'settings_drift_detected' => true,
                'drift_parent_value'      => 256,
                'drift_detected_at'       => '2026-05-08T12:00:00+00:00',
            ],
        ]);
        $driftedSetting->setOverrideMode('floor_only');
        $this->setSettings($tochter, [$driftedSetting]);

        // A non-drifted setting must NOT show up.
        $cleanSetting = $this->makeSetting(2, 'unrelated.thing', 'plain');
        $this->setSettings($tochter, [$driftedSetting, $cleanSetting]);

        $aggregator = $this->makeAggregator();
        $report = $aggregator->aggregateForKonzern($konzern);

        $this->assertCount(1, $report->settingsDriftRows);
        $row = $report->settingsDriftRows[0];
        $this->assertSame('crypto.minimum_key_length', $row['setting_key']);
        $this->assertSame(256, $row['konzern_value']);
        $this->assertSame(192, $row['tochter_value']);
        $this->assertSame('floor_only', $row['override_mode']);
    }

    #[Test]
    public function testAcknowledgmentCoverageCalculated(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        $publishedDoc = $this->makeDocument(201, $tochter, 'iso27001_a51', 'published');
        $this->setDocuments($tochter, [
            $publishedDoc,
            $this->makeDocument(202, $tochter, 'iso27001_a52', 'published'),
        ]);

        $userA = $this->makeUser(11);
        $userB = $this->makeUser(12);
        $this->setUsers($tochter, [$userA, $userB]);

        // 1 ack out of 2 docs × 2 users = 4 expected → 25%.
        $ack = $this->makeAcknowledgement($publishedDoc, $userA);
        $this->setAcknowledgements($tochter, [$ack]);

        $aggregator = $this->makeAggregator();
        $report = $aggregator->aggregateForKonzern($konzern);

        $tochterRow = null;
        foreach ($report->acknowledgmentCoverage as $row) {
            if ($row['tenant_id'] === 2) {
                $tochterRow = $row;
                break;
            }
        }
        $this->assertNotNull($tochterRow);
        $this->assertSame(2, $tochterRow['published_documents_count']);
        $this->assertSame(2, $tochterRow['total_users']);
        $this->assertSame(1, $tochterRow['acknowledgements_count']);
        $this->assertSame(25.0, $tochterRow['coverage_percentage']);
    }

    #[Test]
    public function testOutstandingActionsAggregated(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        $overdueInstance = $this->makeWorkflowInstance(
            id: 5001,
            entityType: 'Document',
            entityId: 999,
            status: 'in_progress',
            dueDate: new DateTimeImmutable('-2 days'),
            currentStepName: 'ciso_review',
        );
        $upcomingInstance = $this->makeWorkflowInstance(
            id: 5002,
            entityType: 'Document',
            entityId: 1000,
            status: 'pending',
            dueDate: new DateTimeImmutable('+10 days'),
            currentStepName: 'top_mgmt_signoff',
        );
        $this->setWorkflowInstances($tochter, [$overdueInstance, $upcomingInstance]);

        $aggregator = $this->makeAggregator();
        $report = $aggregator->aggregateForKonzern($konzern);

        $this->assertCount(2, $report->outstandingActions);
        // Overdue must be ranked first (severity=danger).
        $this->assertSame(5001, $report->outstandingActions[0]['workflow_instance_id']);
        $this->assertSame('danger', $report->outstandingActions[0]['severity']);
        $this->assertSame('ciso_review', $report->outstandingActions[0]['action']);
        $this->assertLessThan(0, $report->outstandingActions[0]['due_in_seconds']);

        $this->assertSame(5002, $report->outstandingActions[1]['workflow_instance_id']);
        $this->assertSame('info', $report->outstandingActions[1]['severity']);
    }

    // -----------------------------------------------------------------
    // Service factory + repository doubles
    // -----------------------------------------------------------------

    private function makeAggregator(): KonzernRollupAggregator
    {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findBy')->willReturnCallback(
            function (array $criteria) {
                $tenant = $criteria['tenant'] ?? null;
                if (!$tenant instanceof Tenant) {
                    return [];
                }
                $tid = $tenant->getId();
                $all = $this->documentsByTenant[$tid] ?? [];
                if (isset($criteria['status'])) {
                    $statuses = (array) $criteria['status'];
                    return array_values(array_filter(
                        $all,
                        static fn (Document $d): bool => in_array($d->getStatus(), $statuses, true),
                    ));
                }
                return $all;
            }
        );

        $workflowRepo = $this->createMock(WorkflowInstanceRepository::class);
        $workflowRepo->method('findBy')->willReturnCallback(
            function (array $criteria) {
                $tenant = $criteria['tenant'] ?? null;
                if (!$tenant instanceof Tenant) {
                    return [];
                }
                $tid = $tenant->getId();
                $all = $this->workflowsByTenant[$tid] ?? [];
                if (isset($criteria['status'])) {
                    $statuses = (array) $criteria['status'];
                    return array_values(array_filter(
                        $all,
                        static fn (WorkflowInstance $wi): bool => in_array($wi->getStatus(), $statuses, true),
                    ));
                }
                return $all;
            }
        );

        $settingRepo = $this->createMock(TenantPolicySettingRepository::class);
        $settingRepo->method('findByTenant')->willReturnCallback(
            function (Tenant $tenant): array {
                $tid = $tenant->getId();
                return $this->settingsByTenant[$tid] ?? [];
            }
        );

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findBy')->willReturn($this->frameworks);

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
        $reqRepo->method('getFrameworkStatisticsForTenant')->willReturnCallback(
            function (ComplianceFramework $framework, Tenant $tenant) {
                $key = $tenant->getId() . '|' . $framework->getId();
                return $this->frameworkStats[$key] ?? [
                    'total' => 0, 'applicable' => 0, 'fulfilled' => 0, 'critical_gaps' => 0,
                ];
            }
        );

        $ackRepo = $this->createMock(PolicyAcknowledgementRepository::class);
        $ackRepo->method('findBy')->willReturnCallback(
            function (array $criteria) {
                $tenant = $criteria['tenant'] ?? null;
                if (!$tenant instanceof Tenant) {
                    return [];
                }
                return $this->acksByTenant[$tenant->getId()] ?? [];
            }
        );

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findBy')->willReturnCallback(
            function (array $criteria) {
                $tenant = $criteria['tenant'] ?? null;
                if (!$tenant instanceof Tenant) {
                    return [];
                }
                return $this->usersByTenant[$tenant->getId()] ?? [];
            }
        );

        return new KonzernRollupAggregator(
            documentRepository: $documentRepo,
            workflowInstanceRepository: $workflowRepo,
            tenantPolicySettingRepository: $settingRepo,
            complianceFrameworkRepository: $frameworkRepo,
            complianceRequirementRepository: $reqRepo,
            policyAcknowledgementRepository: $ackRepo,
            userRepository: $userRepo,
        );
    }

    // -----------------------------------------------------------------
    // Fixture builders
    // -----------------------------------------------------------------

    /**
     * @param list<Tenant> $subsidiaries
     */
    private function makeTenant(int $id, string $code, string $name, array $subsidiaries = []): Tenant
    {
        $tenant = new Tenant();
        $reflection = new \ReflectionClass($tenant);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($tenant, $id);

        $tenant->setCode($code);
        $tenant->setName($name);

        foreach ($subsidiaries as $sub) {
            $tenant->addSubsidiary($sub);
        }

        return $tenant;
    }

    private function makeDocument(int $id, Tenant $tenant, string $category, string $status): Document
    {
        $doc = new Document();
        $doc->setTenant($tenant);
        if ($category !== '') {
            $doc->setCategory($category);
        }
        $doc->setStatus($status);
        $doc->setIsArchived($status === 'archived');
        $doc->setFilename('doc-' . $id . '.pdf');
        $doc->setOriginalFilename('doc-' . $id . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(1234);
        $doc->setFilePath('/tmp/doc-' . $id);
        $doc->setUpdatedAt(new \DateTimeImmutable('2026-05-01 10:00:00'));

        $reflection = new \ReflectionClass($doc);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($doc, $id);
        return $doc;
    }

    private function makeSetting(int $tenantId, string $key, mixed $value): TenantPolicySetting
    {
        $setting = new TenantPolicySetting();
        $setting->setKey($key);
        $setting->setValue($value);
        $setting->setOverrideMode('free');
        return $setting;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $user->setEmail('user-' . $id . '@example.test');
        $reflection = new \ReflectionClass($user);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($user, $id);
        return $user;
    }

    private function makeAcknowledgement(Document $doc, User $user): PolicyAcknowledgement
    {
        $ack = new PolicyAcknowledgement();
        $ack->setDocument($doc);
        $ack->setUser($user);
        $ack->setAcknowledgementMethod('web_click');
        $ack->setDocumentVersion('1.0');
        return $ack;
    }

    private function makeWorkflowInstance(
        int $id,
        string $entityType,
        int $entityId,
        string $status,
        ?DateTimeImmutable $dueDate,
        string $currentStepName,
    ): WorkflowInstance {
        $instance = new WorkflowInstance();
        $instance->setEntityType($entityType);
        $instance->setEntityId($entityId);
        $instance->setStatus($status);
        $instance->setDueDate($dueDate);
        $instance->setStartedAt(new DateTimeImmutable('-1 day'));

        $step = new WorkflowStep();
        $step->setName($currentStepName);
        $instance->setCurrentStep($step);

        $reflection = new \ReflectionClass($instance);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($instance, $id);
        return $instance;
    }

    /**
     * @param list<Document> $documents
     */
    private function setDocuments(Tenant $tenant, array $documents): void
    {
        $this->documentsByTenant[$tenant->getId()] = $documents;
    }

    /**
     * @param list<TenantPolicySetting> $settings
     */
    private function setSettings(Tenant $tenant, array $settings): void
    {
        $this->settingsByTenant[$tenant->getId()] = $settings;
    }

    /**
     * @param list<User> $users
     */
    private function setUsers(Tenant $tenant, array $users): void
    {
        $this->usersByTenant[$tenant->getId()] = $users;
    }

    /**
     * @param list<PolicyAcknowledgement> $acks
     */
    private function setAcknowledgements(Tenant $tenant, array $acks): void
    {
        $this->acksByTenant[$tenant->getId()] = $acks;
    }

    /**
     * @param list<WorkflowInstance> $instances
     */
    private function setWorkflowInstances(Tenant $tenant, array $instances): void
    {
        $this->workflowsByTenant[$tenant->getId()] = $instances;
    }
}
