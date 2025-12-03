<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class AuditLogCleanupCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:audit-log:cleanup');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecuteWithDryRun(): void
    {
        $this->commandTester->execute(['--dry-run' => true]);

        $this->assertSame(0, $this->commandTester->getStatusCode());
        $output = $this->commandTester->getDisplay();
        $this->assertStringContainsString('DRY RUN', $output);
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:audit-log:cleanup');
        $this->assertSame('app:audit-log:cleanup', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:audit-log:cleanup');
        $this->assertNotEmpty($command->getDescription());
    }
}
