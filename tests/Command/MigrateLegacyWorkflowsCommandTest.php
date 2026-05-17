<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Tests for MigrateLegacyWorkflowsCommand (Sprint Y.4).
 *
 * Verifies the command correctly:
 * - Reports when YAML files are present (15 canonical regulatory workflows)
 * - Exits successfully when all YAML files exist
 * - Does not delete any data (report-only by default)
 * - Has a working --archive flag (opt-in, only deactivates)
 *
 * @see src/Command/MigrateLegacyWorkflowsCommand.php
 * @see docs/decisions/2026-05-17-workflow-yaml-unification.md
 */
class MigrateLegacyWorkflowsCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:migrate-legacy-workflows');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function testCommandIsRegistered(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:migrate-legacy-workflows');
        $this->assertSame('app:migrate-legacy-workflows', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:migrate-legacy-workflows');
        $this->assertNotEmpty($command->getDescription());
    }

    #[Test]
    public function testReportOnlyModeSucceedsWhenAllYamlsPresent(): void
    {
        // All 15 YAML files ship with the codebase in config/workflows/regulatory/.
        // In a clean checkout this must pass with exit code 0.
        $this->commandTester->execute([]);

        $output = $this->commandTester->getDisplay();

        // All 15 slugs should appear in the output
        $this->assertStringContainsString('gdpr_data_breach', $output);
        $this->assertStringContainsString('incident_high_severity', $output);
        $this->assertStringContainsString('risk_treatment', $output);
        $this->assertStringContainsString('dpia', $output);
        $this->assertStringContainsString('bc_plan_activation', $output);

        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function testOutputContainsYamlSection(): void
    {
        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('YAML files in config/workflows/regulatory/', $output);
    }

    #[Test]
    public function testOutputContainsDbAnalysisSection(): void
    {
        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('DB workflow rows vs YAML counterparts', $output);
    }

    #[Test]
    public function testOutputContainsSummary(): void
    {
        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        $this->assertStringContainsString('YAML files expected', $output);
        $this->assertStringContainsString('YAML files missing', $output);
        $this->assertStringContainsString('DB rows covered by YAML', $output);
    }

    #[Test]
    public function testReportOnlyModeDoesNotWriteToDatabase(): void
    {
        // Run in report-only mode (no --archive flag).
        // The command should report but make zero DB writes.
        $this->commandTester->execute([]);

        // We cannot easily assert "no DB writes" in a KernelTestCase without
        // a custom event listener, so we verify the output does NOT contain
        // the archive-mode messages.
        $output = $this->commandTester->getDisplay();
        $this->assertStringNotContainsString('Archived DB id=', $output);
        $this->assertStringNotContainsString('Flushed', $output);
    }

    #[Test]
    public function testArchiveFlagIsRecognized(): void
    {
        // Run with --archive flag. If there are no obsolete rows, the archive
        // section is simply skipped without error.
        $this->commandTester->execute(['--archive' => true]);

        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('Archive mode enabled', $output);
        // Exit code is 0 when all YAMLs are present (even if no rows archived)
        $this->assertSame(0, $this->commandTester->getStatusCode());
    }

    #[Test]
    public function testTenantOptionIsRecognized(): void
    {
        // --tenant option should be parsed without error (even if tenant doesn't exist in test DB)
        $this->commandTester->execute(['--tenant' => '999999']);

        // Command should still exit cleanly (no exception on non-existent tenant)
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('YAML files in config/workflows/regulatory/', $output);
    }

    #[Test]
    public function testAllFifteenYamlSlugsAreChecked(): void
    {
        $this->commandTester->execute([]);
        $output = $this->commandTester->getDisplay();

        $expectedSlugs = [
            'gdpr_data_breach',
            'incident_high_severity',
            'incident_low_severity',
            'risk_treatment',
            'dpia',
            'dsr',
            'capa',
            'change_request',
            'management_review',
            'control_verification',
            'supplier_assessment',
            'training_verification',
            'bc_plan_activation',
            'document_review',
            'incident_post_mortem',
        ];

        foreach ($expectedSlugs as $slug) {
            $this->assertStringContainsString($slug, $output, sprintf(
                'Expected YAML slug "%s" to appear in command output',
                $slug,
            ));
        }
    }
}
