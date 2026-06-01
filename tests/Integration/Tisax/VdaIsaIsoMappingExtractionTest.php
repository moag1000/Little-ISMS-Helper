<?php

declare(strict_types=1);

namespace App\Tests\Integration\Tisax;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Integration tests for the VDA-ISA→ISO 27001 extraction script.
 *
 * These tests require the official ENX workbooks at:
 *   tests/Fixtures/vda_isa_6_en_official.xlsx
 *   tests/Fixtures/vda_isa_6_de_official.xlsx  (optional, for DE=EN consistency)
 *
 * If the workbook files are absent the tests are skipped (not failed).
 * Download from ENX portal; do NOT commit the xlsx files (see .gitignore).
 */
final class VdaIsaIsoMappingExtractionTest extends TestCase
{
    private const EN_FIXTURE   = __DIR__ . '/../../Fixtures/vda_isa_6_en_official.xlsx';
    private const DE_FIXTURE   = __DIR__ . '/../../Fixtures/vda_isa_6_de_official.xlsx';
    private const SCRIPT       = __DIR__ . '/../../../scripts/import/extract_vda_isa_iso_mapping.php';
    private const MAPPING_FILE = __DIR__ . '/../../../fixtures/library/mappings/tisax_to_iso27001-2022_v1.0.yaml';

    #[Test]
    public function extraction_script_has_valid_php_syntax(): void
    {
        self::assertFileExists(self::SCRIPT);
        $output    = shell_exec('php -l ' . escapeshellarg(self::SCRIPT) . ' 2>&1');
        $noSyntaxErrors = str_contains((string) $output, 'No syntax errors');
        self::assertTrue($noSyntaxErrors, "PHP syntax error in extraction script:\n$output");
    }

    #[Test]
    public function extraction_script_produces_valid_yaml_from_en_workbook(): void
    {
        if (!file_exists(self::EN_FIXTURE)) {
            self::markTestSkipped('EN workbook not available at ' . self::EN_FIXTURE);
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'vda_isa_test_') . '.yaml';
        $cmd    = sprintf(
            'php %s %s --output=%s 2>&1',
            escapeshellarg(self::SCRIPT),
            escapeshellarg(self::EN_FIXTURE),
            escapeshellarg($tmpOut)
        );

        exec($cmd, $outputLines, $exitCode);

        self::assertSame(0, $exitCode, "Script exited with error:\n" . implode("\n", $outputLines));
        self::assertFileExists($tmpOut);

        $data = Yaml::parseFile($tmpOut);
        self::assertArrayHasKey('mappings', $data);
        self::assertGreaterThan(50, count($data['mappings']));

        unlink($tmpOut);
    }

    #[Test]
    public function extracted_output_matches_committed_mapping(): void
    {
        if (!file_exists(self::EN_FIXTURE)) {
            self::markTestSkipped('EN workbook not available at ' . self::EN_FIXTURE);
        }

        $tmpOut = tempnam(sys_get_temp_dir(), 'vda_isa_test_') . '.yaml';
        $cmd    = sprintf(
            'php %s %s --output=%s 2>&1',
            escapeshellarg(self::SCRIPT),
            escapeshellarg(self::EN_FIXTURE),
            escapeshellarg($tmpOut)
        );

        exec($cmd, $outputLines, $exitCode);
        self::assertSame(0, $exitCode, implode("\n", $outputLines));

        $extracted = Yaml::parseFile($tmpOut);
        $committed = Yaml::parseFile(self::MAPPING_FILE);

        $extractedSources = array_column($extracted['mappings'], 'source');
        $committedSources = array_column($committed['mappings'], 'source');

        sort($extractedSources);
        sort($committedSources);

        self::assertSame($committedSources, $extractedSources,
            'Committed mapping sources must match what the extraction script produces from the workbook. ' .
            'Run the script with --output to regenerate the committed YAML.');

        unlink($tmpOut);
    }

    #[Test]
    public function de_workbook_matches_en_workbook(): void
    {
        if (!file_exists(self::EN_FIXTURE)) {
            self::markTestSkipped('EN workbook not available at ' . self::EN_FIXTURE);
        }
        if (!file_exists(self::DE_FIXTURE)) {
            self::markTestSkipped('DE workbook not available at ' . self::DE_FIXTURE);
        }

        $cmd = sprintf(
            'php %s %s --check-de --de=%s 2>&1',
            escapeshellarg(self::SCRIPT),
            escapeshellarg(self::EN_FIXTURE),
            escapeshellarg(self::DE_FIXTURE)
        );

        exec($cmd, $outputLines, $exitCode);
        $output = implode("\n", $outputLines);

        self::assertSame(0, $exitCode,
            "DE/EN consistency check failed:\n$output\n" .
            'This likely means U+00A0 (non-breaking space) handling broke. ' .
            'See parseIso22Anchors() in the extraction script.');
        self::assertStringContainsString('PASS', $output);
    }
}
