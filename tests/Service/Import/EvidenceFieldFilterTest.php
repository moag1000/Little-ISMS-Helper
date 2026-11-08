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
 * (report times, test dates, effectiveness, approvals — #[ApiReadOnlyEvidence]);
 * a new record may bring it along (migration from an old tool).
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
}
