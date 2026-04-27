<?php

declare(strict_types=1);

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use PHPUnit\Framework\Attributes\Test;

class LoadC52025RequirementsCommandTest extends KernelTestCase
{
    #[Test]
    public function testCommandExists(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $this->assertTrue($application->has('app:load-c5-2025-requirements'));
    }

    #[Test]
    public function testCommandHasCorrectName(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        // 2025 remains as alias on the 2026 command (catalogue update).
        $command = $application->find('app:load-c5-2025-requirements');
        $this->assertContains('app:load-c5-2025-requirements', $command->getAliases());
    }

    #[Test]
    public function testCommandHasDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find('app:load-c5-2025-requirements');
        $this->assertNotEmpty($command->getDescription());
    }
}
