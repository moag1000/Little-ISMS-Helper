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
    public function approvedDocumentWithEmptyVersionLogsWarning(): void
    {
        // V3 W2-Bug2: setVersion('') normalises to '1.0' on the entity;
        // however a row migrated from pre-Bug2 data could in theory still
        // hold a NULL/empty value via reflection. Pin the warning branch.
        $this->reactions->method('isEnabled')->willReturn(true);

        $tenant = $this->createTenant(11);
        $document = new Document();
        $document->setTenant($tenant);
        $document->setStatus('approved');
        $document->setRequiresAcknowledgement(true);
        // Bypass the setter normalisation to simulate a legacy row.
        $versionProp = (new \ReflectionClass($document))->getProperty('version');
        $versionProp->setValue($document, '   ');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $em->expects($this->never())->method('flush');

        $loggedWarning = false;
        $this->logger->method('warning')->willReturnCallback(static function () use (&$loggedWarning) {
            $loggedWarning = true;
        });

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);

        $this->assertTrue($loggedWarning, 'Listener should warn when document version is blank');
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

        $u1 = $this->makeUser(101);
        $u2 = $this->makeUser(102);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findBy')->willReturn([$u1, $u2]);

        $ackRepo = $this->createMock(PolicyAcknowledgementRepository::class);
        $ackRepo->method('findOneFor')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($userRepo, $ackRepo) {
            return match ($class) {
                User::class => $userRepo,
                PolicyAcknowledgement::class => $ackRepo,
                default => null,
            };
        });

        $persisted = [];
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);

        $ackRows = array_values(array_filter(
            $persisted,
            static fn($e) => $e instanceof PolicyAcknowledgement,
        ));
        $this->assertCount(2, $ackRows, 'One pending acknowledgement per active user');
        foreach ($ackRows as $row) {
            $this->assertSame(PolicyAcknowledgement::STATUS_PENDING, $row->getStatus());
            $this->assertSame($document, $row->getDocument());
            $this->assertSame('1.0', $row->getDocumentVersion());
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

        $ackRepo = $this->createMock(PolicyAcknowledgementRepository::class);
        $ackRepo->method('findOneFor')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $userRepoSentinel = false;
        $em->method('getRepository')->willReturnCallback(static function (string $class) use ($ackRepo, &$userRepoSentinel) {
            if ($class === User::class) {
                $userRepoSentinel = true;
                throw new \RuntimeException(
                    'Listener fell back to tenant-wide user query despite explicit audience'
                );
            }
            return match ($class) {
                PolicyAcknowledgement::class => $ackRepo,
                default => null,
            };
        });

        $persisted = [];
        $em->method('persist')->willReturnCallback(static function ($e) use (&$persisted) { $persisted[] = $e; });

        $args = $this->createPostUpdateArgs($document, $em);
        $this->listener->postUpdate($document, $args);

        $this->assertFalse(
            $userRepoSentinel,
            'Listener must NOT query User repo when explicit audience is present',
        );

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
