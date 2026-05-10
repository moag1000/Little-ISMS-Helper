<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard\Rollup;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ComplianceFrameworkRepository;
use App\Repository\ComplianceRequirementRepository;
use App\Repository\DocumentRepository;
use App\Repository\PolicyAcknowledgementRepository;
use App\Service\PolicyWizard\Rollup\KonzernTrendCalculator;
use App\Service\PolicyWizard\Rollup\KonzernTrendReport;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * CISO Task #130 — KonzernTrendCalculator unit tests.
 *
 * Pure unit tests: no DB roundtrip. Repository doubles return canned
 * data so the trend slices can be asserted in isolation.
 */
#[AllowMockObjectsWithoutExpectations]
final class KonzernTrendCalculatorTest extends TestCase
{
    /** @var array<int, list<Document>> tenantId => Documents */
    private array $documentsByTenant = [];

    /** @var array<int, list<PolicyAcknowledgement>> tenantId => Acks */
    private array $acksByTenant = [];

    protected function setUp(): void
    {
        $this->documentsByTenant = [];
        $this->acksByTenant = [];
    }

    #[Test]
    public function testEmptyKonzernReturnsZeroFilledReport(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        $calculator = $this->makeCalculator();
        $report = $calculator->calculateQuarterlyTrend(
            konzernRoot: $konzern,
            quartersBack: 4,
            asOfDate: new DateTimeImmutable('2026-05-01'),
        );

        $this->assertInstanceOf(KonzernTrendReport::class, $report);
        $this->assertCount(4, $report->quarters);
        // 4 quarters back from 2026-Q2 = [2025-Q3, 2025-Q4, 2026-Q1, 2026-Q2]
        $this->assertSame('2025-Q3', $report->quarters[0]);
        $this->assertSame('2026-Q2', $report->quarters[3]);

        $this->assertCount(1, $report->perSubsidiary);
        $row = $report->perSubsidiary[0];
        $this->assertSame(2, $row['tenant_id']);
        $this->assertSame([0, 0, 0, 0], $row['document_counts']);
        $this->assertSame([0, 0, 0, 0], $row['approval_counts']);
        $this->assertSame(0.0, $row['latest_score']);
        $this->assertSame('stable', $row['direction']);
        $this->assertNull($report->estimatedAleEur, 'ALE is skeleton — must stay null');
    }

    #[Test]
    public function testSingleQuarterBucketCounted(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        // Two docs in 2026-Q1 (one published, one draft).
        $this->documentsByTenant[2] = [
            $this->makeDocument(101, $tochter, 'iso27001_a51', 'published', new DateTimeImmutable('2026-02-15')),
            $this->makeDocument(102, $tochter, 'iso27001_a52', 'draft',     new DateTimeImmutable('2026-03-10')),
            // Outside window — must NOT count.
            $this->makeDocument(103, $tochter, 'iso27001_a53', 'published', new DateTimeImmutable('2024-01-15')),
            // Archived — must NOT count.
            $this->makeDocument(104, $tochter, 'iso27001_a54', 'archived',  new DateTimeImmutable('2026-02-01')),
        ];

        $calculator = $this->makeCalculator();
        $report = $calculator->calculateQuarterlyTrend(
            konzernRoot: $konzern,
            quartersBack: 4,
            asOfDate: new DateTimeImmutable('2026-05-01'),
        );

        $row = $report->perSubsidiary[0];
        // Cumulative document counts across [2025-Q3, 2025-Q4, 2026-Q1, 2026-Q2]:
        // both docs land in Q1 (idx 2) → cumulative = [0, 0, 2, 2].
        $this->assertSame([0, 0, 2, 2], $row['document_counts']);
        $this->assertSame([0, 0, 1, 1], $row['approval_counts']);
        // Score(2026-Q2) = 100 * 1/2 = 50.0
        $this->assertSame(50.0, $row['compliance_scores'][3]);
    }

    #[Test]
    public function testEightQuartersTrendWithDeltaAndDirection(): void
    {
        $tochter = $this->makeTenant(2, 'TA', 'Tochter A');
        $konzern = $this->makeTenant(1, 'KZ', 'Holding AG', subsidiaries: [$tochter]);

        // Seed published docs across Q1..Q4 to drive a clear upward trend.
        $this->documentsByTenant[2] = [
            $this->makeDocument(201, $tochter, 'iso27001_a51', 'published', new DateTimeImmutable('2024-08-01')),
            $this->makeDocument(202, $tochter, 'iso27001_a52', 'published', new DateTimeImmutable('2024-11-01')),
            $this->makeDocument(203, $tochter, 'iso27001_a53', 'published', new DateTimeImmutable('2025-02-01')),
            $this->makeDocument(204, $tochter, 'iso27001_a54', 'published', new DateTimeImmutable('2025-05-01')),
            $this->makeDocument(205, $tochter, 'iso27001_a55', 'published', new DateTimeImmutable('2025-08-01')),
            $this->makeDocument(206, $tochter, 'iso27001_a56', 'published', new DateTimeImmutable('2025-11-01')),
            $this->makeDocument(207, $tochter, 'iso27001_a57', 'published', new DateTimeImmutable('2026-02-01')),
            $this->makeDocument(208, $tochter, 'iso27001_a58', 'published', new DateTimeImmutable('2026-04-15')),
        ];

        $calculator = $this->makeCalculator();
        $report = $calculator->calculateQuarterlyTrend(
            konzernRoot: $konzern,
            quartersBack: 8,
            asOfDate: new DateTimeImmutable('2026-05-01'),
        );

        $this->assertCount(8, $report->quarters);
        $row = $report->perSubsidiary[0];
        $this->assertCount(8, $row['document_counts']);
        // Cumulative: 1, 2, 3, 4, 5, 6, 7, 8 — monotonically increasing.
        $this->assertSame([1, 2, 3, 4, 5, 6, 7, 8], $row['document_counts']);
        // All approved → score stays 100% across the board.
        $this->assertSame(100.0, $row['compliance_scores'][7]);
        $this->assertSame('stable', $row['direction'], '100→100 is stable');

        $this->assertCount(8, $report->konzernAverage['document_counts']);
        $this->assertSame(100.0, $report->konzernAverage['latest_score']);
    }

    // -----------------------------------------------------------------
    // Service factory + fixture helpers
    // -----------------------------------------------------------------

    private function makeCalculator(): KonzernTrendCalculator
    {
        $documentRepo = $this->createMock(DocumentRepository::class);
        $documentRepo->method('findBy')->willReturnCallback(
            function (array $criteria) {
                $tenant = $criteria['tenant'] ?? null;
                if (!$tenant instanceof Tenant) {
                    return [];
                }
                return $this->documentsByTenant[$tenant->getId()] ?? [];
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

        $frameworkRepo = $this->createMock(ComplianceFrameworkRepository::class);
        $frameworkRepo->method('findBy')->willReturn([]);

        $reqRepo = $this->createMock(ComplianceRequirementRepository::class);
        $reqRepo->method('getFrameworkStatisticsForTenant')->willReturn([
            'total' => 0, 'applicable' => 0, 'fulfilled' => 0,
        ]);

        return new KonzernTrendCalculator(
            documentRepository: $documentRepo,
            policyAcknowledgementRepository: $ackRepo,
            complianceFrameworkRepository: $frameworkRepo,
            complianceRequirementRepository: $reqRepo,
        );
    }

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

    private function makeDocument(
        int $id,
        Tenant $tenant,
        string $category,
        string $status,
        DateTimeImmutable $uploadedAt,
    ): Document {
        $doc = new Document();
        $doc->setTenant($tenant);
        $doc->setCategory($category);
        $doc->setStatus($status);
        $doc->setIsArchived($status === 'archived');
        $doc->setFilename('doc-' . $id . '.pdf');
        $doc->setOriginalFilename('doc-' . $id . '.pdf');
        $doc->setMimeType('application/pdf');
        $doc->setFileSize(1234);
        $doc->setFilePath('/tmp/doc-' . $id);
        $doc->setUploadedAt($uploadedAt);
        $doc->setUpdatedAt($uploadedAt);

        $reflection = new \ReflectionClass($doc);
        $idProp = $reflection->getProperty('id');
        $idProp->setValue($doc, $id);
        return $doc;
    }
}
