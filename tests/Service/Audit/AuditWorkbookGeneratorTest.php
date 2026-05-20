<?php

declare(strict_types=1);

namespace App\Tests\Service\Audit;

use App\Entity\Tenant;
use App\Service\Audit\AuditWorkbookGenerator;
use App\Service\Audit\Generator\AuditWorkbookGeneratorInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[AllowMockObjectsWithoutExpectations]
final class AuditWorkbookGeneratorTest extends TestCase
{
    // ── getSupportedExportTypes ───────────────────────────────────────────────

    #[Test]
    public function itReturnsFourSupportedExportTypes(): void
    {
        $orchestrator = new AuditWorkbookGenerator([]);
        $types = $orchestrator->getSupportedExportTypes();

        self::assertCount(4, $types);
        self::assertContains('soa', $types);
        self::assertContains('control-implementation', $types);
        self::assertContains('compliance-fulfillment', $types);
        self::assertContains('risk-register', $types);
    }

    // ── getGeneratorFor ───────────────────────────────────────────────────────

    #[Test]
    public function itResolvesGeneratorForSupportedType(): void
    {
        $mockGenerator = $this->createMock(AuditWorkbookGeneratorInterface::class);
        $mockGenerator->method('supportsExportType')->willReturnCallback(
            fn(string $type): bool => $type === 'soa'
        );

        $orchestrator = new AuditWorkbookGenerator([$mockGenerator]);

        self::assertSame($mockGenerator, $orchestrator->getGeneratorFor('soa'));
    }

    #[Test]
    public function itThrowsForUnknownExportType(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown-type/');

        $orchestrator = new AuditWorkbookGenerator([]);
        $orchestrator->getGeneratorFor('unknown-type');
    }

    #[Test]
    public function itPicksFirstMatchingGenerator(): void
    {
        $generatorA = $this->createMock(AuditWorkbookGeneratorInterface::class);
        $generatorA->method('supportsExportType')->willReturn(false);

        $generatorB = $this->createMock(AuditWorkbookGeneratorInterface::class);
        $generatorB->method('supportsExportType')->willReturn(true);
        $generatorB->method('generate')->willReturn(new Spreadsheet());

        $orchestrator = new AuditWorkbookGenerator([$generatorA, $generatorB]);
        $tenant = $this->createTenantStub();

        $result = $orchestrator->generate('any', $tenant);

        self::assertInstanceOf(Spreadsheet::class, $result);
    }

    // ── generate ──────────────────────────────────────────────────────────────

    #[Test]
    public function itDelegatesGenerateToConcreteGenerator(): void
    {
        $spreadsheet = new Spreadsheet();

        $mockGenerator = $this->createMock(AuditWorkbookGeneratorInterface::class);
        $mockGenerator->method('supportsExportType')->willReturn(true);
        $mockGenerator->method('generate')->willReturn($spreadsheet);

        $orchestrator = new AuditWorkbookGenerator([$mockGenerator]);
        $tenant = $this->createTenantStub();

        $result = $orchestrator->generate('soa', $tenant, ['foo' => 'bar']);

        self::assertSame($spreadsheet, $result);
    }

    // ── streamToResponse ──────────────────────────────────────────────────────

    #[Test]
    public function itReturnsStreamedResponseWithCorrectHeaders(): void
    {
        $mockGenerator = $this->createMock(AuditWorkbookGeneratorInterface::class);
        $mockGenerator->method('supportsExportType')->willReturn(true);
        $mockGenerator->method('generate')->willReturn(new Spreadsheet());

        $orchestrator = new AuditWorkbookGenerator([$mockGenerator]);
        $tenant = $this->createTenantStub();

        $response = $orchestrator->streamToResponse('soa', $tenant, [], 'test-soa.xlsx');

        self::assertInstanceOf(StreamedResponse::class, $response);
        self::assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->headers->get('Content-Type')
        );
        self::assertStringContainsString('test-soa.xlsx', (string) $response->headers->get('Content-Disposition'));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function createTenantStub(): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getName')->willReturn('Test Organisation GmbH');
        return $tenant;
    }
}
