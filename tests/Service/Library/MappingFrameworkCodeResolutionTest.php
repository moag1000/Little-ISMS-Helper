<?php

declare(strict_types=1);

namespace App\Tests\Service\Library;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Guards that every cross-framework mapping fixture's source_framework/target_framework
 * code matches an actually-REGISTERED runtime framework loader code (FrameworkLoaderInterface
 * ::getFrameworkCode()). MappingLibraryLoader resolves frameworks by EXACT code match
 * (findOneBy(['code' => ...])) with no case/format normalisation — so a mismatched code
 * means the whole mapping silently resolves to nothing at runtime.
 *
 * Codes in PENDING_FRAMEWORKS are mappings whose TARGET framework is not yet loaded
 * (documented orphans needing a framework-load decision) — they stay inert at runtime
 * but are tracked here so the list is explicit and any NEW mismatch fails CI.
 */
final class MappingFrameworkCodeResolutionTest extends TestCase
{
    /** Registered runtime framework codes (FrameworkLoaderInterface implementations). */
    private const REGISTERED = [
        'BDSG', 'BSI-C5', 'BSI-C5-2026', 'BSI_GRUNDSCHUTZ', 'CIS-CONTROLS', 'DIGAV', 'DORA',
        'EU-AI-ACT', 'EU-CRA', 'EUCS', 'GDPR', 'GXP', 'IKT-MINSTD-CH', 'ISO-22301', 'ISO27001', 'ISO27005',
        'ISO27017', 'ISO27018', 'ISO27701', 'ISO27701_2025', 'ISO42001', 'KRITIS', 'KRITIS-HEALTH',
        'MRIS-v1.5', 'NIS2', 'NIS2UMSUCG', 'NISG-AT', 'NIST-CSF-2.0', 'PCI-DSS-4.0.1', 'REVDSG-CH',
        'SOC2', 'TISAX', 'TKG-2024',
    ];

    /** Documented orphans — target framework not yet loaded (mapping inert until then). */
    private const PENDING_FRAMEWORKS = [
        'BAIT',              // superseded by DORA (kept for legacy reference)
        'ISO27002',          // ISO 27002 not registered as a standalone framework loader yet
        'iec-isa-62443',     // IEC/ISA 62443 not loaded
        'nist-sp800-53r5',   // NIST SP 800-53r5 not loaded
    ];

    /** @return iterable<string, array{string}> */
    public static function mappingFiles(): iterable
    {
        foreach (glob(__DIR__ . '/../../../fixtures/library/mappings/*.yaml') ?: [] as $path) {
            yield basename($path) => [$path];
        }
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('mappingFiles')]
    public function framework_codes_resolve_or_are_documented_pending(string $path): void
    {
        $data = Yaml::parseFile($path);
        if (!is_array($data) || !isset($data['library'])) {
            self::assertTrue(true); // legacy/non-library fixture — out of scope

            return;
        }
        $allowed = array_merge(self::REGISTERED, self::PENDING_FRAMEWORKS);
        foreach (['source_framework', 'target_framework'] as $key) {
            $code = $data['library'][$key] ?? null;
            if ($code === null) {
                continue;
            }
            self::assertContains(
                $code,
                $allowed,
                sprintf('%s: %s "%s" is neither a registered framework code nor a documented pending one — the mapping would silently fail to resolve at runtime.', basename($path), $key, $code),
            );
        }
    }
}
