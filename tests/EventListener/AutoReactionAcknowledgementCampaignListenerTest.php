<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Document;
use App\Entity\Tenant;
use App\EventListener\AutoReactionAcknowledgementCampaignListener;
use App\Service\AutoReactionService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-M5 / W2-C4 — AutoReactionAcknowledgementCampaignListener tests.
 *
 * Listener has been hardened by W2-C4:
 *   - Tenant-scoped active-user query (was cross-tenant).
 *   - Real persistence of PolicyAcknowledgement rows with STATUS_PENDING.
 *
 * Tests pin gating paths. Document::getVersion() does not exist on the entity,
 * so the listener short-circuits with a warning log; cover that path. Once
 * Document gains a getVersion() accessor, persistence-path tests can be added.
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionAcknowledgementCampaignListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private AutoReactionAcknowledgementCampaignListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->listener = new AutoReactionAcknowledgementCampaignListener($this->reactions, $this->logger);
    }

    #[Test]
    public function toggleDisabledIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(false);

        $document = new Document();
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);
    }

    #[Test]
    public function nonApprovedStatusIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $document = new Document();
        $document->setStatus('draft');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);
    }

    #[Test]
    public function approvedDocumentWithoutVersionLogsWarningAndPersistsNothing(): void
    {
        // Document::getVersion() doesn't exist on the entity → listener
        // hits the early-return "skip: no version" warning branch.
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $document = new Document();
        $document->setTenant($tenant);
        $document->setStatus('approved');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $loggedWarning = false;
        $this->logger->method('warning')->willReturnCallback(static function () use (&$loggedWarning) {
            $loggedWarning = true;
        });

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);

        $this->assertTrue($loggedWarning, 'Listener should warn when document has no version field');
    }

    #[Test]
    public function approvedDocumentWithoutTenantIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $document = new Document();
        $document->setStatus('approved');
        // No tenant — listener should silently return.

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function createPostUpdateArgs(object $entity, EntityManagerInterface $em): PostUpdateEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn([]);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new PostUpdateEventArgs($entity, $em);
    }
}
