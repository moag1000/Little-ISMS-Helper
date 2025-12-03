<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class LoadIso27701v2025RequirementsCommandTest extends KernelTestCase
{
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:load-iso27701v2025-requirements'));
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-iso27701v2025-requirements');
        $this->assertSame('app:load-iso27701v2025-requirements', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-iso27701v2025-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
