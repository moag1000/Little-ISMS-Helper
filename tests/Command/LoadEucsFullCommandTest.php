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
    public function catalogueHasTwentyDistinctCategoriesWithValidAssuranceLevels(): void
    {
        $ref = new ReflectionClass(LoadEucsFullCommand::class);
        /** @var array<string, array{string, string}> $categories */
        $categories = $ref->getConstant('CATEGORIES');

        self::assertCount(20, $categories, 'EUCS ships 20 control categories');

        foreach ($categories as $code => [$title, $assurance]) {
            self::assertNotSame('', $code);
            self::assertNotSame('', $title);
            self::assertContains($assurance, ['basic', 'substantial', 'high'], "Category {$code} has an invalid assurance level");
        }

        // Spot-check a couple of canonical EUCS categories.
        self::assertArrayHasKey('OIS', $categories);
        self::assertArrayHasKey('IAM', $categories);
        self::assertSame('high', $categories['INQ'][1]);
    }
}
