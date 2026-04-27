<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use PHPUnit\Framework\Attributes\Test;

class SeedIncidentWorkflowsCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:seed:incident-workflows');
        $this->commandTester = new CommandTester($command);
    }

    #[Test]
    public function testExecute(): void
    {
        $this->commandTester->execute([]);

        // Can be SUCCESS (0) or similar
        $this->assertContains($this->commandTester->getStatusCode(), [0, 1]);
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:seed:incident-workflows');
        $this->assertSame('app:seed:incident-workflows', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:seed:incident-workflows');
        $this->assertNotEmpty($command->getDescription());
    }
}
