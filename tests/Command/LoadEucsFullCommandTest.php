<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\LoadEucsFullCommand;
use App\Repository\ComplianceFrameworkRepository;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * F35 — EUCS loader catalogue integrity (DB-free). The EM-bound load path is
 * exercised by the framework-loader integration coverage; here we lock the
 * curated category catalogue shape + framework code.
 *
 * The full catalogue ships 120 controls across 20 categories. There are no
 * per-category assurance-level fields — assurance levels (Basic/Substantial/High)
 * apply per control based on the EUCS scheme; this loader ships IDs + titles only.
 */
#[AllowMockObjectsWithoutExpectations]
final class LoadEucsFullCommandTest extends TestCase
{
    private function command(): LoadEucsFullCommand
    {
        return new LoadEucsFullCommand(
            $this->createMock(ComplianceFrameworkRepository::class),
            $this->createMock(EntityManagerInterface::class),
        );
    }

    #[Test]
    public function exposesEucsFrameworkCode(): void
    {
        self::assertSame('EUCS', $this->command()->getFrameworkCode());
    }

    #[Test]
    public function catalogueHasTwentyDistinctCategoriesAndOneHundredTwentyControls(): void
    {
        $ref = new ReflectionClass(LoadEucsFullCommand::class);

        /** @var array<string,string> $categoryNames */
        $categoryNames = $ref->getConstant('CATEGORY_NAMES');

        /** @var array<string,string> $controls */
        $controls = $ref->getConstant('CONTROLS');

        // 20 named category prefixes
        self::assertCount(20, $categoryNames, 'EUCS ships 20 control categories');

        // Every category prefix is a non-empty string with a non-empty full name
        foreach ($categoryNames as $prefix => $fullName) {
            self::assertNotSame('', $prefix, 'Category prefix must not be empty');
            self::assertNotSame('', $fullName, "Category {$prefix} must have a non-empty full name");
        }

        // Spot-check canonical category prefixes
        self::assertArrayHasKey('OIS', $categoryNames, 'OIS (Organisation of Information Security) must be present');
        self::assertArrayHasKey('IAM', $categoryNames, 'IAM (Identity, Authentication and Access Control) must be present');
        self::assertArrayHasKey('INQ', $categoryNames, 'INQ (Government Investigation Requests) must be present');

        // Full catalogue: 120 controls
        self::assertCount(120, $controls, 'EUCS full catalogue must contain 120 controls');

        // Every control ID must match the scheme pattern and have a non-empty title
        foreach ($controls as $controlId => $title) {
            self::assertMatchesRegularExpression(
                '/^[A-Z]{2,4}-[0-9]{2}$/',
                $controlId,
                "Control ID \"{$controlId}\" does not match the expected pattern ^[A-Z]{2,4}-[0-9]{2}$"
            );
            self::assertNotSame('', $title, "Control {$controlId} must have a non-empty title");
        }

        // 20 distinct category prefixes across all control IDs
        $distinctPrefixes = [];
        foreach (array_keys($controls) as $controlId) {
            $distinctPrefixes[explode('-', $controlId)[0]] = true;
        }
        self::assertCount(20, $distinctPrefixes, 'EUCS controls must span exactly 20 distinct category prefixes');

        // Every prefix used in CONTROLS must be declared in CATEGORY_NAMES
        foreach (array_keys($distinctPrefixes) as $prefix) {
            self::assertArrayHasKey(
                $prefix,
                $categoryNames,
                "Control prefix \"{$prefix}\" used in CONTROLS is not declared in CATEGORY_NAMES"
            );
        }

        // Spot-check a few canonical controls
        self::assertArrayHasKey('OIS-01', $controls, 'OIS-01 (Information Security Management System) must be present');
        self::assertArrayHasKey('BC-01', $controls, 'BC-01 (Business Continuity Policies) must be present');
        self::assertArrayHasKey('IAM-01', $controls, 'IAM-01 (Policies for Access Control) must be present');
    }
}
