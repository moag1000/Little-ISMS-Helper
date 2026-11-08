<?php

declare(strict_types=1);

namespace App\Tests\Service\Import;

use App\Entity\Control;
use App\Entity\Incident;
use App\Service\Import\EvidenceFieldFilter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W3: a delta import does not overwrite evidence on an existing record
 * (report times, test dates, effectiveness, approvals — #[ApiReadOnlyEvidence]).
 * S11: a new record does not bring it along either (that test used to allow it);
 * only `importable` current-state values (a control's implementation status) pass.
 */
final class EvidenceFieldFilterTest extends TestCase
{
    #[Test]
    public function anUpdateKeepsTheRecordedEvidence(): void
    {
        $data = ['title' => 'Neu', 'effectiveness' => 'effective', 'lastEffectivenessTest' => new \DateTimeImmutable()];

        self::assertSame(['title' => 'Neu'], EvidenceFieldFilter::forUpdate(Control::class, $data));
    }

    #[Test]
    public function reportTimesOfAnIncidentAreEvidence(): void
    {
        $fields = EvidenceFieldFilter::evidenceFields(Incident::class);

        foreach (['earlyWarningReportedAt', 'closedAt', 'nationalAuthorityNotified', 'authorityReferenceNumber'] as $f) {
            self::assertContains($f, $fields);
        }
    }

    #[Test]
    public function bothImportPathsApplyTheFilterToUpdates(): void
    {
        $root = dirname(__DIR__, 3) . '/src/Service/Import/';
        foreach (['BulkImportOrchestrator.php', 'DeltaCalculator.php'] as $file) {
            self::assertStringContainsString('EvidenceFieldFilter::forUpdate(', (string) file_get_contents($root . $file), $file);
        }
    }

    #[Test]
    public function aNewRecordIsCreatedWithoutEvidence(): void
    {
        $data = ['title' => 'Alt', 'earlyWarningReportedAt' => new \DateTimeImmutable('-1 year'), 'closedAt' => new \DateTimeImmutable()];

        self::assertSame(['title' => 'Alt'], EvidenceFieldFilter::forCreate(Incident::class, $data));
        self::assertStringContainsString(
            'EvidenceFieldFilter::forCreate(',
            (string) file_get_contents(dirname(__DIR__, 3) . '/src/Service/Import/BulkImportOrchestrator.php'),
        );
    }

    #[Test]
    public function theImplementationStatusIsApiReadOnlyButImportable(): void
    {
        self::assertContains('implementationStatus', EvidenceFieldFilter::evidenceFields(Control::class));
        self::assertNotContains('implementationStatus', EvidenceFieldFilter::importProtectedFields(Control::class));
        self::assertSame(
            ['implementationStatus' => 'implemented', 'implementationPercentage' => 100],
            EvidenceFieldFilter::forUpdate(Control::class, ['implementationStatus' => 'implemented', 'implementationPercentage' => 100]),
        );
    }
}
