<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

class LoadNis2RequirementsCommandTest extends KernelTestCase
{
    #[Test]
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:load-nis2-requirements'));
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-nis2-requirements');
        $this->assertSame('app:load-nis2-requirements', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-nis2-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
