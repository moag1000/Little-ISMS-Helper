<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class GenerateIsoProceduresCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:generate-iso-procedures');
        $this->commandTester = new CommandTester($command);
    }

    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:generate-iso-procedures'));
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:generate-iso-procedures');
        $this->assertSame('app:generate-iso-procedures', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:generate-iso-procedures');
        $this->assertNotEmpty($command->getDescription());
    }
}
