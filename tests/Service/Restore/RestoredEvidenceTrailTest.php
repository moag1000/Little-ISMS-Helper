<?php

declare(strict_types=1);

namespace App\Tests\Service\Restore;

use App\Entity\Asset;
use App\Entity\Risk;
use App\Service\AuditLogger;
use App\Service\Restore\RestoredEvidenceTrail;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * W3: approvals and report times a restore brings in carry their provenance
 * (the backup) in the audit log — they were not decided in this system.
 */
final class RestoredEvidenceTrailTest extends TestCase
{
    #[Test]
    public function restoredApprovalsAreNamedWithTheirSource(): void
    {
        $trail = new RestoredEvidenceTrail();
        $trail->collect(Risk::class, [
            ['id' => 7, 'title' => 'R', 'formallyAccepted' => true, 'acceptanceApprovedAt' => '2026-01-01'],
            ['id' => 8, 'title' => 'S', 'formallyAccepted' => false],
        ]);
        $trail->collect(Asset::class, [['id' => 1, 'name' => 'A']]);

        $logger = $this->createMock(AuditLogger::class);
        $logger->expects($this->once())->method('logBulk')->with(
            'restore.evidence',
            'Risk',
            ['source' => '2026-11-01', 'records' => 1],
            [[
                'action' => 'restore.evidence',
                'entity_id' => 7,
                'new_values' => ['restored_fields' => ['acceptanceApprovedAt', 'formallyAccepted'], 'source' => '2026-11-01'],
            ]],
        )->willReturn('batch');

        $trail->write($logger, '2026-11-01');
    }
}
