<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\EventListener\AutoReactionAcknowledgementCampaignListener;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\AutoReactionService;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\PolicyWizard\PolicyAudienceResolver;
use App\Doctrine\PostFlushWriter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\UnitOfWork;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * V3 W2-M5 / W2-C4 / W2-Bug2 — AutoReactionAcknowledgementCampaignListener tests.
 *
 * Listener pre-conditions:
 *   - Tenant-scoped active-user query (W2-C4, was cross-tenant).
 *   - Real persistence of PolicyAcknowledgement rows with STATUS_PENDING.
 *   - Document::getVersion() + getRequiresAcknowledgement() exist
 *     (W2-Bug2 — previously missing, listener short-circuited silently).
 */
#[AllowMockObjectsWithoutExpectations]
class AutoReactionAcknowledgementCampaignListenerTest extends TestCase
{
    private MockObject $reactions;
    private MockObject $logger;
    private PostFlushWriter $writer;
    private AutoReactionAcknowledgementCampaignListener $listener;

    protected function setUp(): void
    {
        $this->reactions = $this->createMock(AutoReactionService::class);
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->writer = new PostFlushWriter(new NullLogger());
        $this->ackRepo = $this->createStub(PolicyAcknowledgementRepository::class);
        $this->ackRepo->method('findOneFor')->willReturn(null);
        $this->userRepo = $this->createStub(UserRepository::class);
        $this->rebuildListener();
    }

    private PolicyAcknowledgementRepository $ackRepo;
    private UserRepository $userRepo;

    private function rebuildListener(): void
    {
        $service = new PolicyAcknowledgementService(
            $this->createStub(EntityManagerInterface::class),
            $this->ackRepo,
            new PolicyAudienceResolver($this->userRepo),
            $this->createStub(AuditLogger::class),
        );
        $this->listener = new AutoReactionAcknowledgementCampaignListener($this->reactions, $this->logger, $this->writer, $service);
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
    public function approvedDocumentWithoutAcknowledgementFlagIsNoOp(): void
    {
        // V3 W2-Bug2: requiresAcknowledgement defaults to false; listener
        // must short-circuit before any repository lookup.
        $this->reactions->method('isEnabled')->willReturn(true);

        $document = new Document();
        $document->setStatus('approved');
        $document->setRequiresAcknowledgement(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('getRepository');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);
    }

    #[Test]
    public function approvedDocumentWithoutTenantIsNoOp(): void
    {
        $this->reactions->method('isEnabled')->willReturn(true);

        $document = new Document();
        $document->setStatus('approved');
        $document->setRequiresAcknowledgement(true);
        // No tenant — listener should silently return.

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);
    }

    #[Test]
    public function approvedDocumentPersistsPendingRowsForActiveUsers(): void
    {
        // V3 W2-Bug2 strict-success path:
        // 2 active users in the tenant, no existing acknowledgement → 2 PENDING rows persisted.
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $document = new Document();
        $document->setTenant($tenant);
        $document->setStatus('approved');
        $document->setRequiresAcknowledgement(true);
        $document->setVersion('1.0');

        $document->setSha256Hash(str_repeat('ab', 32));

        $u1 = $this->makeUser(101);
        $u1->setTenant($tenant);
        $u1->setIsActive(true);
        $u2 = $this->makeUser(102);
        $u2->setTenant($tenant);
        $u2->setIsActive(true);

        $this->userRepo = $this->createStub(UserRepository::class);
        $this->userRepo->method('findActiveUsers')->willReturn([$u1, $u2]);
        $this->rebuildListener();

        $em = $this->createMock(EntityManagerInterface::class);

        $persisted = [];
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->expects($this->once())->method('flush');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);

        // The listener only hands the entity to the writer; nothing is persisted or flushed yet.

        $this->assertSame([], $persisted);

        $em->method('isOpen')->willReturn(true);
        $this->writer->postFlush(new PostFlushEventArgs($em));


        $ackRows = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof PolicyAcknowledgement,
        ));
        $this->assertCount(2, $ackRows, 'One pending acknowledgement per active user');
        foreach ($ackRows as $row) {
            $this->assertSame(PolicyAcknowledgement::STATUS_PENDING, $row->getStatus());
            $this->assertSame($document, $row->getDocument());
            // The version the acknowledgement looks up — not the free-text "1.0" (W8).
            $this->assertSame(PolicyAcknowledgementService::versionOf($document), $row->getDocumentVersion());
            $this->assertSame('abababababababab', $row->getDocumentVersion());
            $this->assertNull($row->getAcknowledgedAt());
            $this->assertNull($row->getAcknowledgementMethod());
        }
    }

    #[Test]
    public function audienceCollectionRestrictsCampaignFanOut(): void
    {
        // Junior-ISB-Audit C3-03 (S14, 2026-05-23) — ISO 27001 Cl. 7.3 + A.6.3:
        // when the document carries an explicit `acknowledgementAudience`
        // selection the campaign restricts to that list. The legacy
        // tenant-wide active-user query MUST NOT be executed (regression
        // guard against the previous "fan-out to everyone" behaviour).
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(7);
        $document = new Document();
        $document->setTenant($tenant);
        $document->setStatus('approved');
        $document->setRequiresAcknowledgement(true);
        $document->setVersion('1.0');

        $u1 = $this->makeUser(101);
        $u1->setTenant($tenant);
        $u1->setIsActive(true);
        $u2 = $this->makeUser(102);
        $u2->setTenant($tenant);
        $u2->setIsActive(true);
        // Add only u1 to the audience — u2 must be skipped.
        $document->addAcknowledgementAudience($u1);

        $userRepoSentinel = false;
        $this->userRepo = $this->createStub(UserRepository::class);
        $this->userRepo->method('findActiveUsers')->willReturnCallback(static function () use (&$userRepoSentinel, $u1, $u2): array {
            $userRepoSentinel = true;

            return [$u1, $u2];
        });
        $this->rebuildListener();

        $em = $this->createMock(EntityManagerInterface::class);

        $persisted = [];
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });
        $em->expects($this->once())->method('flush');

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);

        $this->assertFalse(
            $userRepoSentinel,
            'Listener must NOT query User repo when explicit audience is present',
        );

        // The listener only hands the entity to the writer; nothing is persisted or flushed yet.

        $this->assertSame([], $persisted);

        $em->method('isOpen')->willReturn(true);
        $this->writer->postFlush(new PostFlushEventArgs($em));


        $ackRows = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof PolicyAcknowledgement,
        ));
        $this->assertCount(1, $ackRows, 'One pending acknowledgement per audience user');
        $this->assertSame($u1, $ackRows[0]->getUser());
    }

    private function createTenant(int $id): Tenant
    {
        $tenant = new Tenant();
        $idProperty = (new \ReflectionClass($tenant))->getProperty('id');
        $idProperty->setValue($tenant, $id);
        return $tenant;
    }

    private function makeUser(int $id): User
    {
        $user = new User();
        $idProp = (new \ReflectionClass($user))->getProperty('id');
        $idProp->setValue($user, $id);
        return $user;
    }

    private function createPostUpdateArgs(object $entity, EntityManagerInterface $em): PostUpdateEventArgs
    {
        $uow = $this->createMock(UnitOfWork::class);
        $uow->method('getEntityChangeSet')->willReturn([]);
        $em->method('getUnitOfWork')->willReturn($uow);

        return new PostUpdateEventArgs($entity, $em);
    }
}
