<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

class LoadGdprRequirementsCommandTest extends KernelTestCase
{
    #[Test]
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:load-gdpr-requirements'));
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-gdpr-requirements');
        $this->assertSame('app:load-gdpr-requirements', $command->getName());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-gdpr-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
