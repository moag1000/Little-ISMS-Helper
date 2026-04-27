<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

class LoadTkgRequirementsCommandTest extends KernelTestCase
{
    #[Test]
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:load-tkg-requirements'));
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-tkg-requirements');
        $this->assertSame('app:load-tkg-requirements', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-tkg-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
