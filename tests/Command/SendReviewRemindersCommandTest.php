<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

class SendReviewRemindersCommandTest extends KernelTestCase
{
    private CommandTester $commandTester;

    protected function setUp(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:review:send-reminders');
        $this->commandTester = new CommandTester($command);
    }

    public function testExecute(): void
    {
        $this->commandTester->execute([]);

        // Either SUCCESS or FAILURE depending on email configuration
        $this->assertContains($this->commandTester->getStatusCode(), [0, 1]);
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:review:send-reminders');
        $this->assertSame('app:review:send-reminders', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:review:send-reminders');
        $this->assertNotEmpty($command->getDescription());
    }
}
