<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\AuditLogger;
use App\Service\BackupService;
use App\Service\RestoreService;
use Doctrine\Common\EventManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * S7: a restore writes only what a backup contains. A crafted file used to restore any
 * App\Entity name — an organisation admin could plant an ApiToken with a known hash and
 * write rights for itself. Entities outside BackupService::restorableEntityNames() are
 * dropped before anything is cleared or written, and the restore says so.
 */
final class RestoreForeignEntityTest extends TestCase
{
    #[Test]
    public function excludedEntitiesAreNeverRestorable(): void
    {
        $excluded = array_keys((new \ReflectionClassConstant(BackupService::class, 'EXCLUDED_FROM_BACKUP'))->getValue());

        self::assertContains('ApiToken', $excluded);
        self::assertSame([], array_values(array_intersect($excluded, BackupService::restorableEntityNames())));
    }

    #[Test]
    public function restoreDropsAnApiTokenFromTheFile(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $connection = $this->createStub(Connection::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getEventManager')->willReturn($this->createStub(EventManager::class));
        $em->method('isOpen')->willReturn(true);
        $em->expects(self::never())->method('persist');

        $service = new RestoreService(
            $em,
            $this->createStub(AuditLogger::class),
            new NullLogger(),
            $this->createStub(UserPasswordHasherInterface::class),
        );

        $result = $service->restoreFromBackup([
            'metadata' => ['version' => '2.0'],
            'data' => [
                'ApiToken' => [['id' => 1, 'tokenHash' => hash('sha256', 'known'), 'canWrite' => true]],
                'NotAnEntityAtAll' => [['id' => 1]],
            ],
        ], ['dry_run' => true, 'clear_before_restore' => true]);

        self::assertArrayNotHasKey('ApiToken', $result['statistics']);
        $warnings = implode("\n", $service->getWarnings());
        self::assertStringContainsString('ApiToken', $warnings);
        self::assertStringContainsString('NotAnEntityAtAll', $warnings);
    }

    #[Test]
    public function previewDoesNotListForeignEntities(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $repository = $this->createStub(EntityRepository::class);
        $repository->method('count')->willReturn(0);
        $em->method('getRepository')->willReturn($repository);
        $service = new RestoreService(
            $em,
            $this->createStub(AuditLogger::class),
            new NullLogger(),
            $this->createStub(UserPasswordHasherInterface::class),
        );

        $preview = $service->getRestorePreview([
            'metadata' => ['version' => '2.0'],
            'data' => ['ApiToken' => [['id' => 1]], 'Asset' => [['id' => 1]]],
        ]);

        self::assertArrayNotHasKey('ApiToken', $preview['entities']);
        self::assertArrayHasKey('Asset', $preview['entities']);
    }
}
