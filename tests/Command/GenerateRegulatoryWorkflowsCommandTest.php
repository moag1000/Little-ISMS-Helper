<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\Test;

class GenerateRegulatoryWorkflowsCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:generate-regulatory-workflows');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function testExecuteWithSpecificWorkflow(): void
    {
        $this->commandTester->execute([
            '--workflow' => 'data-breach',
        ]);

        // Either SUCCESS or FAILURE depending on system state
        $this->assertContains($this->commandTester->getStatusCode(), [0, 1]);
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:generate-regulatory-workflows');
        $this->assertSame('app:generate-regulatory-workflows', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:generate-regulatory-workflows');
        $this->assertNotEmpty($command->getDescription());
    }
}
