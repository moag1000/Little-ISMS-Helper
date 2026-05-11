<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit\Generator;

use App\Entity\Control;
use App\Entity\Tenant;
use App\Repository\ControlRepository;
use App\Service\Audit\Generator\ControlImplementationWorkbookGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[AllowMockObjectsWithoutExpectations]
final class ControlImplementationWorkbookGeneratorTest extends TestCase
{
    #[Test]
    public function itSupportsOnlyControlImplementationType(): void
    {
        $generator = new ControlImplementationWorkbookGenerator($this->createStub(ControlRepository::class));

        self::assertTrue($generator->supportsExportType('control-implementation'));
        self::assertFalse($generator->supportsExportType('soa'));
        self::assertFalse($generator->supportsExportType('risk-register'));
    }

    #[Test]
    public function itReturnsNonEmptySpreadsheet(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new ControlImplementationWorkbookGenerator($repo);
        $result = $generator->generate($this->buildTenantStub());

        self::assertInstanceOf(Spreadsheet::class, $result);
    }

    #[Test]
    public function itProducesTwoSheets(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new ControlImplementationWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        self::assertCount(2, $spreadsheet->getAllSheets());
    }

    #[Test]
    public function itHasExpectedSheetNames(): void
    {
        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([]);

        $generator = new ControlImplementationWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $names = array_map(fn($s): string => $s->getTitle(), $spreadsheet->getAllSheets());

        self::assertContains('Cover', $names);
        self::assertContains('Implementations', $names);
    }

    #[Test]
    public function implementationsSheetHasDataRowPerControl(): void
    {
        $control = $this->buildControlStub('8.1', 'User endpoint devices');

        $repo = $this->createStub(ControlRepository::class);
        $repo->method('findBy')->willReturn([$control]);

        $generator = new ControlImplementationWorkbookGenerator($repo);
        $spreadsheet = $generator->generate($this->buildTenantStub());

        $sheet = $spreadsheet->getSheetByName('Implementations');
        self::assertNotNull($sheet);
        self::assertSame('8.1', $sheet->getCell('A2')->getValue());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function buildTenantStub(): Tenant
    {
        $t = $this->createStub(Tenant::class);
        $t->method('getName')->willReturn('Test GmbH');
        return $t;
    }

    private function buildControlStub(string $id, string $name): Control
    {
        $c = $this->createStub(Control::class);
        $c->method('getControlId')->willReturn($id);
        $c->method('getName')->willReturn($name);
        $c->method('getCategory')->willReturn('Technological');
        $c->method('isApplicable')->willReturn(true);
        $c->method('getEffectiveResponsiblePerson')->willReturn(null);
        $c->method('getImplementationStatus')->willReturn('not_started');
        $c->method('getImplementationPercentage')->willReturn(0);
        $c->method('getLastReviewDate')->willReturn(null);
        $c->method('getNextReviewDate')->willReturn(null);
        $c->method('getTargetDate')->willReturn(null);
        $c->method('getLastEffectivenessTest')->willReturn(null);
        $c->method('getEffectiveness')->willReturn(null);
        $c->method('getControlMaturity')->willReturn(null);
        $c->method('getEvidenceDocuments')->willReturn(new ArrayCollection());
        return $c;
    }
}
