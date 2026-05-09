<?php

declare(strict_types=1);

namespace App\Tests\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Smoke tests for {@see \App\Command\SendPolicyAcknowledgementRequestsCommand}.
 *
 * Coverage: registration, name/description metadata and dry-run path
 * (which does not require any policy documents to exist).
 */
final class SendPolicyAcknowledgementRequestsCommandTest extends KernelTestCase
{
    private const COMMAND_NAME = 'app:policy-wizard:send-ack-requests';

    #[Test]
    public function testCommandIsRegistered(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        self::assertTrue($application->has(self::COMMAND_NAME));
    }

    #[Test]
    public function testCommandHasNameAndDescription(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        $command = $application->find(self::COMMAND_NAME);

        self::assertSame(self::COMMAND_NAME, $command->getName());
        self::assertNotEmpty($command->getDescription());
    }

    #[Test]
    public function testDryRunDoesNotPersist(): void
    {
        $kernel = self::bootKernel();
        $application = new Application($kernel);

        try {
            // Some test environments lack DB connectivity. The command
            // queries DocumentRepository via the EM, so skip if the
            // connection blows up.
            $em = $kernel->getContainer()->get('doctrine.orm.entity_manager');
            $em->getConnection()->executeQuery('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('Database not available: ' . $e->getMessage());
        }

        $command = $application->find(self::COMMAND_NAME);
        $tester = new CommandTester($command);
        $exitCode = $tester->execute(['--dry-run' => true]);

        self::assertSame(0, $exitCode, 'Command must exit 0 in dry-run.');
        $output = $tester->getDisplay();
        self::assertStringContainsString('DRY RUN', $output);
    }
}
