<?php

declare(strict_types=1);

namespace App\Tests\Service\ComplianceWizard\Check\PolicyWizard\Bsi;

use App\Entity\Tenant;
use App\Service\ComplianceWizard\Check\PolicyWizard\Bsi\WorksCouncilEvidenceAttachedCheck;
use App\Service\PolicyWizard\WorksCouncilEvidenceCheck;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * W5 Gap-C — Works-Council BR-evidence Compliance-Check tests.
 *
 * Spec: `docs/plans/policy-wizard/07-phase4-sprint-reconciliation.md`
 * line 261-262 (Auditor "Auditor-specific gaps" Works-Council).
 */
#[AllowMockObjectsWithoutExpectations]
final class WorksCouncilEvidenceAttachedCheckTest extends TestCase
{
    private WorksCouncilEvidenceCheck&MockObject $inventory;
    private WorksCouncilEvidenceAttachedCheck $check;

    protected function setUp(): void
    {
        $this->inventory = $this->createMock(WorksCouncilEvidenceCheck::class);
        $this->check = new WorksCouncilEvidenceAttachedCheck($this->inventory);
    }

    #[Test]
    public function testPassesWhenAllAttachmentsPresent(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $this->inventory->method('inspect')->willReturn([
            'evaluated_documents' => 4,
            'covered' => 4,
            'missing' => [],
            'tenant_id' => 1,
        ]);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame(4, $result->details['evaluated_documents']);
        self::assertSame(4, $result->details['covered']);
        self::assertNull($result->gap);
    }

    #[Test]
    public function testFailsAndReportsMissingTopics(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        $this->inventory->method('inspect')->willReturn([
            'evaluated_documents' => 4,
            'covered' => 1,
            'missing' => [
                ['document_id' => 11, 'topic' => 'logging', 'filename' => 'logging.pdf'],
                ['document_id' => 12, 'topic' => 'monitoring', 'filename' => 'monitoring.pdf'],
                ['document_id' => 13, 'topic' => 'telework', 'filename' => 'telework.pdf'],
            ],
            'tenant_id' => 1,
        ]);

        $result = $this->check->run($tenant);

        self::assertFalse($result->passed);
        self::assertSame(25.0, $result->score);
        self::assertSame(4, $result->details['evaluated_documents']);
        self::assertSame(3, $result->details['missing_count']);

        self::assertNotNull($result->gap);
        self::assertSame('high', $result->gap['priority']);
        self::assertSame('app_policy_wizard_index', $result->gap['route']);
        self::assertSame('policy_wizard', $result->gap['translation_domain']);
        self::assertContains('logging', $result->gap['items']);
        self::assertContains('monitoring', $result->gap['items']);
        self::assertContains('telework', $result->gap['items']);
        self::assertSame(
            'compliance_check.works_council_evidence_attached.fail_message',
            $result->gap['title'],
        );
    }

    #[Test]
    public function testVacuousPassWhenNoRelevantDocuments(): void
    {
        $tenant = $this->createMock(Tenant::class);
        $tenant->method('getId')->willReturn(1);

        // Greenfield tenant or no workplace-monitoring policies seeded.
        $this->inventory->method('inspect')->willReturn([
            'evaluated_documents' => 0,
            'covered' => 0,
            'missing' => [],
            'tenant_id' => 1,
        ]);

        $result = $this->check->run($tenant);

        self::assertTrue($result->passed);
        self::assertSame(100.0, $result->score);
        self::assertSame('no_workplace_monitoring_documents', $result->details['reason']);

        // Null tenant gracefully fails without throwing.
        $nullResult = $this->check->run(null);
        self::assertFalse($nullResult->passed);
        self::assertSame('no_tenant', $nullResult->details['reason']);
    }
}
