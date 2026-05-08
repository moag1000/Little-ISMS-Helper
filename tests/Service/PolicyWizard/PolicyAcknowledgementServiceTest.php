<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PolicyAcknowledgementRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\PolicyWizard\PolicyAudienceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for {@see PolicyAcknowledgementService}.
 *
 * W3-L: closes auditor's predicted ISO 27001 A.6.3 NC. Tests the
 * "request → record → coverage" lifecycle including idempotency and
 * the empty-audience pass-through.
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyAcknowledgementServiceTest extends TestCase
{
    private function makeTenant(int $id = 7): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    private function makeUser(int $id, Tenant $tenant, bool $active = true): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('isActive')->willReturn($active);
        $user->method('getEmail')->willReturn('user' . $id . '@example.test');
        return $user;
    }

    private function makeDocument(int $id, Tenant $tenant, ?string $hash = 'abcdef0123456789'): Document
    {
        $doc = $this->createStub(Document::class);
        $doc->method('getId')->willReturn($id);
        $doc->method('getTenant')->willReturn($tenant);
        $doc->method('getSha256Hash')->willReturn($hash);
        $doc->method('getSubstitutionVariables')->willReturn(['_template_version' => 3]);
        return $doc;
    }

    /**
     * @param array<int, PolicyAcknowledgement|null> $existingAcksByUserId
     */
    private function makeRepo(array $existingAcksByUserId = []): PolicyAcknowledgementRepository
    {
        $repo = $this->createMock(PolicyAcknowledgementRepository::class);
        $repo->method('findOneFor')->willReturnCallback(
            function (Tenant $t, Document $d, User $u, string $v) use ($existingAcksByUserId): ?PolicyAcknowledgement {
                return $existingAcksByUserId[$u->getId()] ?? null;
            },
        );
        return $repo;
    }

    private function makeService(
        EntityManagerInterface $em,
        PolicyAcknowledgementRepository $repo,
        PolicyAudienceResolver $resolver,
        ?AuditLogger $audit = null,
    ): PolicyAcknowledgementService {
        $audit ??= $this->createStub(AuditLogger::class);
        return new PolicyAcknowledgementService($em, $repo, $resolver, $audit);
    }

    /**
     * Build a real PolicyAudienceResolver wired against a UserRepository
     * stub that returns the desired audience.
     *
     * The resolver is `final`, so we wire the production class with a
     * mocked dependency rather than mocking the resolver itself.
     *
     * @param list<User> $audience
     */
    private function makeAudienceResolver(array $audience): PolicyAudienceResolver
    {
        $userRepo = $this->createMock(\App\Repository\UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturn($audience);
        $userRepo->method('find')->willReturnCallback(static function (int $id) use ($audience): ?User {
            foreach ($audience as $user) {
                if ($user->getId() === $id) {
                    return $user;
                }
            }
            return null;
        });
        return new PolicyAudienceResolver($userRepo);
    }

    #[Test]
    public function testRequestAcknowledgementsCreatesPendingState(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser(1, $tenant);
        $userB = $this->makeUser(2, $tenant);
        $document = $this->makeDocument(101, $tenant);

        $repo = $this->makeRepo([]); // nobody has acked yet
        $resolver = $this->makeAudienceResolver([$userA, $userB]);
        $em = $this->createMock(EntityManagerInterface::class);

        $service = $this->makeService($em, $repo, $resolver);
        $pending = $service->requestAcknowledgements($document, [$userA, $userB]);

        self::assertSame(2, $pending, 'Both users still owe an acknowledgement.');
    }

    #[Test]
    public function testAcknowledgeCreatesEntity(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser(1, $tenant);
        $document = $this->makeDocument(101, $tenant);

        $repo = $this->makeRepo([]); // no existing ack
        $resolver = $this->makeAudienceResolver([$user]);

        $persisted = null;
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(
            function (object $entity) use (&$persisted): void {
                $persisted = $entity;
            },
        );
        $em->expects(self::once())->method('flush');

        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logCustom')
            ->with(
                self::equalTo('policy-acknowledgement'),
                self::equalTo('PolicyAcknowledgement'),
            );

        $service = $this->makeService($em, $repo, $resolver, $audit);
        $ack = $service->acknowledge(
            $document,
            $user,
            PolicyAcknowledgementService::METHOD_WEB_CLICK,
            '203.0.113.7',
        );

        self::assertInstanceOf(PolicyAcknowledgement::class, $ack);
        self::assertSame($persisted, $ack, 'persist() received the same entity that was returned.');
        self::assertSame('203.0.113.7', $ack->getIpAddress());
        self::assertSame(PolicyAcknowledgementService::METHOD_WEB_CLICK, $ack->getAcknowledgementMethod());
        self::assertSame('3', $ack->getDocumentVersion(), 'Version comes from substitutionVariables._template_version.');
    }

    #[Test]
    public function testIdempotentReAcknowledgement(): void
    {
        $tenant = $this->makeTenant();
        $user = $this->makeUser(1, $tenant);
        $document = $this->makeDocument(101, $tenant);

        $existing = new PolicyAcknowledgement();
        $existing->setTenant($tenant);
        $existing->setDocument($document);
        $existing->setUser($user);
        $existing->setAcknowledgementMethod(PolicyAcknowledgementService::METHOD_WEB_CLICK);
        $existing->setDocumentVersion('3');

        $repo = $this->makeRepo([1 => $existing]);
        $resolver = $this->makeAudienceResolver([$user]);
        $em = $this->createMock(EntityManagerInterface::class);
        // No persist / flush expected on re-ack attempt.
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $service = $this->makeService($em, $repo, $resolver);

        // requestAcknowledgements skips already-acked → 0 pending.
        $pending = $service->requestAcknowledgements($document, [$user]);
        self::assertSame(0, $pending);

        // acknowledge() throws because the row already exists.
        $this->expectException(RuntimeException::class);
        $service->acknowledge($document, $user, PolicyAcknowledgementService::METHOD_WEB_CLICK);
    }

    #[Test]
    public function testCoverageCalculation(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser(1, $tenant);
        $userB = $this->makeUser(2, $tenant);
        $userC = $this->makeUser(3, $tenant);
        $document = $this->makeDocument(101, $tenant);

        $existingAck = new PolicyAcknowledgement();
        $existingAck->setTenant($tenant);
        $existingAck->setDocument($document);
        $existingAck->setUser($userA);
        $existingAck->setDocumentVersion('3');
        $existingAck->setAcknowledgementMethod(PolicyAcknowledgementService::METHOD_WEB_CLICK);

        // Only userA has acked.
        $repo = $this->makeRepo([1 => $existingAck]);
        $resolver = $this->makeAudienceResolver([$userA, $userB, $userC]);
        $em = $this->createMock(EntityManagerInterface::class);

        $service = $this->makeService($em, $repo, $resolver);
        $coverage = $service->coverageFor($document);

        self::assertSame(1, $coverage['acknowledged']);
        self::assertSame(2, $coverage['pending']);
        self::assertEqualsWithDelta(33.3, $coverage['percent'], 0.1);
        self::assertSame([1, 2, 3], $coverage['audience_user_ids']);
    }

    #[Test]
    public function testCoverageEmptyAudienceReturnsZero(): void
    {
        $tenant = $this->makeTenant();
        $document = $this->makeDocument(101, $tenant);

        $repo = $this->makeRepo([]);
        $resolver = $this->makeAudienceResolver([]); // nobody in scope
        $em = $this->createMock(EntityManagerInterface::class);

        $service = $this->makeService($em, $repo, $resolver);
        $coverage = $service->coverageFor($document);

        self::assertSame(0, $coverage['acknowledged']);
        self::assertSame(0, $coverage['pending']);
        // Empty audience treats as 100% pass — audit-defang for empty/sandbox tenants.
        self::assertSame(100.0, $coverage['percent']);
        self::assertSame([], $coverage['audience_user_ids']);
    }
}
