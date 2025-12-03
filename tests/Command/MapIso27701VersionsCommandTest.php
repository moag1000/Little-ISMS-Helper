<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class MapIso27701VersionsCommandTest extends KernelTestCase
{
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:map-iso27701-versions'));
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:map-iso27701-versions');
        $this->assertSame('app:map-iso27701-versions', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:map-iso27701-versions');
        $this->assertNotEmpty($command->getDescription());
    }
}
