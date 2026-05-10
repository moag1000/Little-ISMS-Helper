<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Asset;
use App\Entity\DataProtectionImpactAssessment;
use App\Entity\ProcessingActivity;
use App\Entity\Tenant;
use App\EventListener\AssetSchutzbedarfDpiaListener;
use App\Service\AutoReactionService;
use Doctrine\Common\Collections\ArrayCollection;
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
 * V3 W2-WS-10 — AssetSchutzbedarfDpiaListener tests.
 */
#[AllowMockObjectsWithoutExpectations]
class AssetSchutzbedarfDpiaListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AssetSchutzbedarfDpiaListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AssetSchutzbedarfDpiaListener($this->reactions, $this->logger);
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $asset = $this->asset(1, 4, 4, 4);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getUnitOfWork');

        $args = new PostUpdateEventArgs($asset, $em);
        $this->listener->postUpdate($asset, $args);
    }

    #[Test]
    public function noCiaChangeIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $asset = $this->asset(1, 4, 4, 4);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn(['name' => ['old', 'new']]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->expects($this->never())->method('getRepository');

        $args = new PostUpdateEventArgs($asset, $em);
        $this->listener->postUpdate($asset, $args);
    }

    #[Test]
    public function ciaRiseToHighFiresWhenLinkedPaHasNoDpia(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->tenant(1);
        $asset = $this->asset(1, 4, 2, 2);
        $asset->setTenant($tenant);
        $pa = new ProcessingActivity();
        $asset->addProcessingActivity($pa);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn([
            'confidentialityValue' => [3, 4],
        ]);

        $repo = $this->createMock(EntityRepository::class);
        // No DPIA linked.
        $repo->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getRepository')->with(DataProtectionImpactAssessment::class)->willReturn($repo);

        $logged = [];
        $this->logger->method('info')->willReturnCallback(static function ($msg) use (&$logged): void {
            $logged[] = $msg;
        });

        $args = new PostUpdateEventArgs($asset, $em);
        $this->listener->postUpdate($asset, $args);

        $this->assertContains('Asset Schutzbedarf raised — DPIA-Bedarf-Check fired', $logged);
    }

    #[Test]
    public function existingDpiaSuppressesNotification(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->tenant(1);
        $asset = $this->asset(1, 4, 2, 2);
        $asset->setTenant($tenant);
        $pa = new ProcessingActivity();
        $asset->addProcessingActivity($pa);

        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn([
            'integrityValue' => [3, 5],
        ]);

        $existing = new DataProtectionImpactAssessment();
        $repo = $this->createMock(EntityRepository::class);
        $repo->method('findOneBy')->willReturn($existing);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getUnitOfWork')->willReturn($uow);
        $em->method('getRepository')->willReturn($repo);

        $loggedInfo = [];
        $this->logger->method('info')->willReturnCallback(static function ($msg) use (&$loggedInfo): void {
            $loggedInfo[] = $msg;
        });

        $args = new PostUpdateEventArgs($asset, $em);
        $this->listener->postUpdate($asset, $args);

        $this->assertNotContains('Asset Schutzbedarf raised — DPIA-Bedarf-Check fired', $loggedInfo);
    }

    private function asset(int $id, int $c, int $i, int $a): Asset
    {
        $asset = new Asset();
        $asset->setName('Test asset');
        $asset->setAssetType('software');
        $asset->setConfidentialityValue($c);
        $asset->setIntegrityValue($i);
        $asset->setAvailabilityValue($a);
        $asset->setStatus('active');
        $idProp = (new \ReflectionClass($asset))->getProperty('id');
        $idProp->setValue($asset, $id);
        return $asset;
    }

    private function tenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProp = (new \ReflectionClass($tenant))->getProperty('id');
        $idProp->setValue($tenant, $id);
        return $tenant;
    }
}
