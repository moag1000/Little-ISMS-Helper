<?php

declare(strict_types=1);

namespace App\Tests\Security;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * S18: an organisation's Reifegrad of a shared catalogue requirement lives on its fulfilment row.
 * Code reads and writes it only through App\Service\Compliance\RequirementMaturity — a direct
 * getMaturity*() / setMaturity*() on a requirement reads or writes the value every organisation
 * shares.
 *
 * Exempt: the entities, and the TISAX/VDA-ISA code that works on the organisation's own
 * uploaded workbook rows (uploadTenant) or loads the catalogue skeleton default.
 */
final class RequirementMaturityAccessTest extends TestCase
{
    private const EXEMPT = [
        'src/Entity/ComplianceRequirement.php',
        'src/Entity/ComplianceRequirementFulfillment.php',
        'src/Service/Compliance/RequirementMaturity.php',
        'src/Service/Tisax/',
        'src/Service/Import/Mapper/TisaxRequirementMapper.php',
        'src/Command/TisaxConsolidateCommand.php',
    ];

    #[Test]
    public function maturityIsReadAndWrittenOnlyThroughRequirementMaturity(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];
        foreach (['src', 'templates'] as $dir) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'twig'], true)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                foreach (self::EXEMPT as $exempt) {
                    if (str_starts_with($relative, $exempt)) {
                        continue 2;
                    }
                }
                $source = (string) file_get_contents($file->getPathname());
                $pattern = $file->getExtension() === 'twig'
                    ? '/\.maturity(?:Current|Target|ReviewedAt)\b/'          // requirement.maturityCurrent
                    : '/[sg]etMaturity(?:Current|Target|ReviewedAt)\s*\(/';
                if (preg_match($pattern, $source) === 1 && !str_starts_with($relative, 'templates/tisax/')) {
                    $offenders[] = $relative;
                }
            }
        }

        self::assertSame([], $offenders, 'Read and write a Reifegrad via App\Service\Compliance\RequirementMaturity (S18).');
    }
}
