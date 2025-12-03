<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LoadDoraRequirementsCommandTest extends KernelTestCase
{
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:load-dora-requirements'));
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-dora-requirements');
        $this->assertSame('app:load-dora-requirements', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-dora-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
