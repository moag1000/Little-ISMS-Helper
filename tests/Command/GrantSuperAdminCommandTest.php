<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\SetupPermissionsCommand;
use App\Entity\AuditLog;
use App\Entity\User;
use App\Repository\PermissionRepository;
use App\Repository\RoleRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * 3.15.3 security release, operator bootstrap (I1):
 *  (c) app:user:grant-super-admin recovers the instance operator role, with
 *      confirmation and an audit-log entry;
 *  (d) setup creates the initial admin as ROLE_SUPER_ADMIN when none exists.
 */
final class GrantSuperAdminCommandTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private string $email;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->email = 'grant_' . substr(md5(uniqid('', true)), 0, 10) . '@example.com';

        $user = (new User())
            ->setEmail($this->email)
            ->setFirstName('Grant')
            ->setLastName('Probe')
            ->setRoles(['ROLE_ADMIN'])
            ->setPassword('hashed_password')
            ->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        if ($user !== null) {
            $this->em->createQuery('DELETE FROM ' . AuditLog::class . ' a WHERE a.entityType = :t AND a.entityId = :id')
                ->setParameter('t', 'User')
                ->setParameter('id', $user->getId())
                ->execute();
            $this->em->remove($user);
            $this->em->flush();
        }
        parent::tearDown();
    }

    #[Test]
    public function grantsSuperAdminAfterConfirmationAndWritesAuditLog(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['yes']);

        self::assertSame(Command::SUCCESS, $tester->execute(['email' => $this->email]));

        $user = $this->reload();
        self::assertContains('ROLE_SUPER_ADMIN', $user->getStoredRoles());
        self::assertContains('ROLE_ADMIN', $user->getStoredRoles());
        $log = $this->em->getRepository(AuditLog::class)->findOneBy([
            'action' => 'user.super_admin.granted',
            'entityType' => 'User',
            'entityId' => $user->getId(),
        ]);
        self::assertInstanceOf(AuditLog::class, $log);
    }

    #[Test]
    public function declinedConfirmationChangesNothing(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['no']);

        self::assertSame(Command::FAILURE, $tester->execute(['email' => $this->email]));
        self::assertNotContains('ROLE_SUPER_ADMIN', $this->reload()->getStoredRoles());
    }

    #[Test]
    public function unknownEmailFails(): void
    {
        self::assertSame(Command::FAILURE, $this->tester()->execute(['email' => 'nobody_' . $this->email]));
    }

    #[Test]
    public function setupCreatesInitialAdminAsSuperAdminOnlyWhenNoneExists(): void
    {
        self::assertSame(['ROLE_ADMIN', 'ROLE_SUPER_ADMIN'], $this->setupAdminRoles(hasSuperAdmin: false));
        self::assertSame(['ROLE_ADMIN'], $this->setupAdminRoles(hasSuperAdmin: true));
    }

    /**
     * @return list<string>
     */
    private function setupAdminRoles(bool $hasSuperAdmin): array
    {
        $userRepository = $this->createStub(UserRepository::class);
        $userRepository->method('findOneBy')->willReturn(null);
        $userRepository->method('hasSuperAdmin')->willReturn($hasSuperAdmin);
        $persisted = null;
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted = $entity;
        });
        $hasher = $this->createStub(UserPasswordHasherInterface::class);
        $hasher->method('hashPassword')->willReturn('hashed');

        $command = new SetupPermissionsCommand(
            $em,
            $this->createStub(PermissionRepository::class),
            $this->createStub(RoleRepository::class),
            $userRepository,
            $hasher,
            $this->createStub(AuditLogger::class),
        );
        $method = new \ReflectionMethod(SetupPermissionsCommand::class, 'createAdminUser');
        $method->invoke(
            $command,
            'setup@example.com',
            'secret-password',
            'Setup',
            'Admin',
            new SymfonyStyle(new ArrayInput([]), new BufferedOutput()),
        );

        self::assertInstanceOf(User::class, $persisted);

        return $persisted->getStoredRoles();
    }

    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel);

        return new CommandTester($application->find('app:user:grant-super-admin'));
    }

    private function reload(): User
    {
        $this->em->clear();
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $this->email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }
}
