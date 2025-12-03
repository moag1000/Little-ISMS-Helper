<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class SupplementTisaxRequirementsCommandTest extends KernelTestCase
{
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:supplement-tisax-requirements'));
    }

    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:supplement-tisax-requirements');
        $this->assertSame('app:supplement-tisax-requirements', $command->getName());
    }

    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:supplement-tisax-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
