<?php

declare(strict_types=1);

namespace App\Service\Audit;

use App\Entity\Tenant;
use App\Service\Audit\Generator\AuditWorkbookGeneratorInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orchestrator for all audit-workbook generators.
 *
 * Receives the full tagged-iterator collection of AuditWorkbookGeneratorInterface
 * implementations and routes generate() calls to the correct generator based on
 * the requested export type.
 *
 * Usage from a controller (F40.3):
 *   return $generator->streamToResponse('soa', $tenant, [], 'soa-export.xlsx');
 */
final class AuditWorkbookGenerator
{
    /** @var list<AuditWorkbookGeneratorInterface> */
    private array $generators = [];

    /** Supported export types — kept in sync with known generators. */
    private const SUPPORTED_EXPORT_TYPES = [
        'soa',
        'control-implementation',
        'compliance-fulfillment',
        'risk-register',
    ];

    /**
     * @param iterable<AuditWorkbookGeneratorInterface> $generators
     */
    public function __construct(iterable $generators)
    {
        foreach ($generators as $generator) {
            $this->generators[] = $generator;
        }
    }

    /**
     * Resolve the concrete generator for the given export type.
     *
     * @throws \InvalidArgumentException when no generator handles the export type
     */
    public function getGeneratorFor(string $exportType): AuditWorkbookGeneratorInterface
    {
        foreach ($this->generators as $generator) {
            if ($generator->supportsExportType($exportType)) {
                return $generator;
            }
        }

        throw new \InvalidArgumentException(sprintf(
            'No audit-workbook generator found for export type "%s". Supported types: %s.',
            $exportType,
            implode(', ', self::SUPPORTED_EXPORT_TYPES)
        ));
    }

    /**
     * Returns the list of export types that are registered in this application.
     *
     * @return list<string>
     */
    public function getSupportedExportTypes(): array
    {
        return self::SUPPORTED_EXPORT_TYPES;
    }

    /**
     * Generate a Spreadsheet workbook for the given tenant and export type.
     *
     * @param array<string, mixed> $options
     */
    public function generate(string $exportType, Tenant $tenant, array $options = []): Spreadsheet
    {
        return $this->getGeneratorFor($exportType)->generate($tenant, $options);
    }

    /**
     * Stream the generated XLSX workbook directly into an HTTP response.
     *
     * Designed for controller actions (F40.3): produces a StreamedResponse
     * with appropriate Content-Disposition / Content-Type headers so the
     * browser triggers a file download without writing to disk.
     *
     * @param array<string, mixed> $options
     */
    public function streamToResponse(
        string $exportType,
        Tenant $tenant,
        array $options = [],
        string $filename = 'audit-workbook.xlsx',
    ): StreamedResponse {
        $spreadsheet = $this->generate($exportType, $tenant, $options);

        $response = new StreamedResponse(static function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', sprintf('attachment; filename="%s"', $filename));
        $response->headers->set('Cache-Control', 'max-age=0');
        $response->headers->set('Pragma', 'public');

        return $response;
    }
}
