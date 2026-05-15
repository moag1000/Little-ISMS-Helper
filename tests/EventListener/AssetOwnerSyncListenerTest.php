<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Asset;
use App\Entity\User;
use App\EventListener\AssetOwnerSyncListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-LB-6 — AssetOwnerSyncListener tests.
 */
#[AllowMockObjectsWithoutExpectations]
class AssetOwnerSyncListenerTest extends TestCase
{
    private MockObject $logger;
    private AssetOwnerSyncListener $listener;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AssetOwnerSyncListener($this->logger);
    }

    #[Test]
    public function noNameChangeNoSync(): void
    {
        $user = $this->user(7, 'Alice', 'Adams');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['email' => ['old', 'new']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects($this->never())->method('getRepository');

        $args = new PostUpdateEventArgs($user, $em);
        $this->listener->postUpdate($user, $args);
    }

    #[Test]
    public function nameChangeSyncsAssetOwnerString(): void
    {
        $user = $this->user(7, 'Bob', 'Builder');

        $asset1 = (new Asset())->setName('Database')->setOwnerUser($user)->setOwner('Bob B.');
        $asset2 = (new Asset())->setName('Backup')->setOwnerUser($user)->setOwner('Bob Builder');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn([
            'lastName' => ['Smith', 'Builder'],
        ]);

        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findBy')->willReturn([$asset1, $asset2]);

        $persisted = [];
        $flushed = false;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getRepository')->willReturn($repo);
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->method('flush')->willReturnCallback(static function () use (&$flushed): void { $flushed = true; });

        $args = new PostUpdateEventArgs($user, $em);
        $this->listener->postUpdate($user, $args);

        // Both assets re-persisted; flush called once.
        $this->assertSame('Bob Builder', $asset1->getOwner());
        $this->assertSame('Bob Builder', $asset2->getOwner());
        $this->assertTrue($flushed);
        // Only the asset1 changed (asset2 already had matching string).
        $this->assertCount(1, $persisted);
    }

    #[Test]
    public function emptyNameSkipsSync(): void
    {
        $user = $this->user(7, '', '');

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['firstName' => ['Old', '']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects($this->never())->method('getRepository');

        $args = new PostUpdateEventArgs($user, $em);
        $this->listener->postUpdate($user, $args);
    }

    private function user(int $id, string $first, string $last): User
    {
        $user = new User();
        $user->setFirstName($first);
        $user->setLastName($last);
        $idProp = (new \ReflectionClass($user))->getProperty('id');
        $idProp->setValue($user, $id);
        return $user;
    }
}
