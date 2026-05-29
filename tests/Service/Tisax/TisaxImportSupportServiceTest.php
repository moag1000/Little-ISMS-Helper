<?php

declare(strict_types=1);

namespace App\Tests\Service\Tisax;

use App\Entity\Tenant;
use App\Service\Tisax\Dto\VdaIsaControlRow;
use App\Service\Tisax\TisaxImportSupportService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Unit tests for the pure (DB-independent) logic of TisaxImportSupportService:
 * the organisation-mismatch heuristic and the session DTO (de)serialisation
 * round-trip. The DB-touching applyMaturityOverwrites() path is covered by the
 * controller E2E test. Pulled from the container (the collaborator services are
 * final and thus unmockable — but unused by the methods under test anyway).
 */
final class TisaxImportSupportServiceTest extends KernelTestCase
{
    private TisaxImportSupportService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->service = self::getContainer()->get(TisaxImportSupportService::class);
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function orgNameProvider(): iterable
    {
        yield 'identical'                 => ['CANCOM GmbH', 'CANCOM GmbH', false];
        yield 'legal-form stripped'       => ['CANCOM GmbH', 'Cancom', false];
        yield 'case + suffix insensitive' => ['cancom ag', 'CANCOM', false];
        yield 'substring match'           => ['Muster Software GmbH', 'Muster Software', false];
        yield 'clearly different'         => ['CANCOM GmbH', 'Völlig Andere AG', true];
    }

    #[Test]
    #[DataProvider('orgNameProvider')]
    public function organisation_mismatch_heuristic(string $workbook, string $tenantName, bool $expectMismatch): void
    {
        $tenant = (new Tenant())->setName($tenantName);

        self::assertSame($expectMismatch, $this->service->isOrganisationMismatch($workbook, $tenant));
    }

    #[Test]
    public function organisation_mismatch_is_false_when_no_workbook_company(): void
    {
        $tenant = (new Tenant())->setName('CANCOM');

        self::assertFalse($this->service->isOrganisationMismatch(null, $tenant));
        self::assertFalse($this->service->isOrganisationMismatch('   ', $tenant));
    }

    #[Test]
    public function organisation_mismatch_is_false_when_tenant_null(): void
    {
        self::assertFalse($this->service->isOrganisationMismatch('CANCOM GmbH', null));
    }

    #[Test]
    public function serialise_deserialise_round_trip_preserves_maturity_current(): void
    {
        $original = new VdaIsaControlRow(
            controlId: '1.2.4',
            title: 'Round-trip control',
            titleEn: 'EN title',
            description: 'desc',
            mustLevel: 'must',
            shouldLevel: null,
            highLevel: null,
            veryHighLevel: null,
            iso27001Ref: 'A.5.1',
            auditEvidenceHint: null,
            rawRowIndex: 9,
            maturityCurrent: 2,
        );

        $restored = $this->service->deserialiseControls(
            $this->service->serialiseControls([$original]),
        );

        self::assertNotNull($restored);
        self::assertCount(1, $restored);
        self::assertSame('1.2.4', $restored[0]->controlId);
        self::assertSame(2, $restored[0]->maturityCurrent);
        self::assertSame('A.5.1', $restored[0]->iso27001Ref);
        self::assertSame('EN title', $restored[0]->titleEn);
    }

    #[Test]
    public function deserialise_returns_null_for_non_array(): void
    {
        self::assertNull($this->service->deserialiseControls(null));
        self::assertNull($this->service->deserialiseControls('not-an-array'));
    }
}
