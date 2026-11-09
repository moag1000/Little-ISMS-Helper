<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Security\SetupAccessChecker;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;

/**
 * S19: the setup wizard hung only on var/setup_complete.lock. A restored logo path
 * '../var/setup_complete.lock' let the next logo change delete it, and an anonymous
 * visitor reached /setup and created an admin. An instance with accounts now counts as
 * set up even without the file; only the browser session that runs the wizard (it set the
 * wizard's own session flags while setup was open) keeps going.
 */
final class SetupLockWithoutFileTest extends TestCase
{
    private string $projectDir = '';

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/s19-setup-' . bin2hex(random_bytes(4));
        mkdir($this->projectDir . '/var', 0777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->projectDir));
    }

    #[Test]
    public function withoutAccountsSetupIsOpen(): void
    {
        self::assertFalse($this->checker(false, [])->isSetupComplete());
        self::assertTrue($this->checker(false, [])->canAccessSetup(false));
    }

    #[Test]
    public function aDeletedLockFileDoesNotReopenSetupOnAnInstanceWithAccounts(): void
    {
        $checker = $this->checker(true, []);

        self::assertTrue($checker->isSetupComplete());
        self::assertFalse($checker->canAccessSetup(false), 'anonymous visitor');
        self::assertFalse($checker->canAccessSetup(true, false), 'organisation admin');
        self::assertTrue($checker->canAccessSetup(true, true), 'super admin');
        self::assertFileExists($this->projectDir . '/var/setup_complete.lock', 'lock written again');
    }

    #[Test]
    public function theSessionRunningTheWizardKeepsGoingAfterTheFirstAccount(): void
    {
        foreach (['setup_database_configured', 'setup_admin_created', 'setup_backup_restored'] as $flag) {
            self::assertFalse($this->checker(true, [$flag => true])->isSetupComplete(), $flag);
        }
        self::assertFileDoesNotExist($this->projectDir . '/var/setup_complete.lock');
    }

    #[Test]
    public function noDatabaseMeansNotSetUp(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willThrowException(new \RuntimeException('no database'));

        self::assertFalse((new SetupAccessChecker($this->projectDir, 'prod', $connection, new RequestStack()))->isSetupComplete());
    }

    /** @param array<string, bool> $sessionFlags */
    private function checker(bool $accountExists, array $sessionFlags): SetupAccessChecker
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('fetchOne')->willReturn($accountExists ? 1 : false);
        $session = new Session(new MockArraySessionStorage());
        foreach ($sessionFlags as $key => $value) {
            $session->set($key, $value);
        }
        $request = new Request();
        $request->setSession($session);
        $stack = new RequestStack();
        $stack->push($request);

        return new SetupAccessChecker($this->projectDir, 'prod', $connection, $stack);
    }
}
