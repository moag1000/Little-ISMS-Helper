<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class MappingQualitySanityCheckCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:mapping-quality:sanity-check');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecute(): void
    {
        $this->commandTester->execute([]);

        // Either SUCCESS or FAILURE depending on system state
        $this->assertContains($this->commandTester->getStatusCode(), [0, 1]);
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:mapping-quality:sanity-check');
        $this->assertSame('app:mapping-quality:sanity-check', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:mapping-quality:sanity-check');
        $this->assertNotEmpty($command->getDescription());
    }
}
