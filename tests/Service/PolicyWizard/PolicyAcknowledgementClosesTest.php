<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyAcknowledgement;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\PolicyAcknowledgementRepository;
use App\Repository\UserRepository;
use App\Service\AuditLogger;
use App\Service\PolicyWizard\PolicyAcknowledgementService;
use App\Service\PolicyWizard\PolicyAudienceResolver;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * An open acknowledgement request is the row the acknowledgement closes (W8, B2's finding).
 *
 * The campaign stored its pending rows under the document's free-text version ("1.0"), the
 * acknowledgement looked them up under the template version / file hash: a click created a
 * second row, the pending one stayed and My Day reminded for ever. Here the rows live in an
 * in-memory store keyed like the unique constraint, so request → acknowledge must end with one
 * acknowledged row and no pending one; people who join later get a request, a new file
 * withdraws the old version's request.
 */
final class PolicyAcknowledgementClosesTest extends TestCase
{
    /** @var array<string, PolicyAcknowledgement> key = user id + version */
    private array $rows = [];

    /** @var list<User> */
    private array $users = [];

    private Tenant $tenant;
    private Document $document;
    private PolicyAcknowledgementService $service;

    protected function setUp(): void
    {
        $this->tenant = $this->entity(new Tenant(), 7);
        $this->document = $this->entity(new Document(), 55);
        $this->document->setTenant($this->tenant);
        $this->document->setStatus('approved');
        $this->document->setRequiresAcknowledgement(true);
        $this->document->setVersion('1.0');
        $this->document->setSha256Hash(str_repeat('1', 64));
        $this->users = [$this->user(1), $this->user(2)];

        $repo = $this->createStub(PolicyAcknowledgementRepository::class);
        $repo->method('findOneFor')->willReturnCallback(
            fn (Tenant $t, Document $d, User $u, string $v): ?PolicyAcknowledgement => $this->rows[$u->getId() . '|' . $v] ?? null,
        );
        $repo->method('findPendingByDocument')->willReturnCallback(fn (): array => array_values(array_filter(
            $this->rows,
            static fn (PolicyAcknowledgement $r): bool => $r->getStatus() === PolicyAcknowledgement::STATUS_PENDING,
        )));

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(function (object $row): void {
            if ($row instanceof PolicyAcknowledgement) {
                $this->rows[$row->getUser()?->getId() . '|' . $row->getDocumentVersion()] = $row;
            }
        });
        $em->method('remove')->willReturnCallback(function (object $row): void {
            foreach ($this->rows as $key => $stored) {
                if ($stored === $row) {
                    unset($this->rows[$key]);
                }
            }
        });

        $userRepo = $this->createStub(UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturnCallback(fn (): array => $this->users);

        $this->service = new PolicyAcknowledgementService($em, $repo, new PolicyAudienceResolver($userRepo), $this->createStub(AuditLogger::class));
    }

    #[Test]
    public function theAcknowledgementClosesThePendingRequest(): void
    {
        self::assertSame(2, $this->service->requestAcknowledgements($this->document));
        self::assertCount(2, $this->pending());

        $this->service->acknowledge($this->document, $this->users[0], PolicyAcknowledgementService::METHOD_WEB_CLICK);

        self::assertCount(2, $this->rows, 'the click upgrades the pending row, it creates no second one');
        self::assertCount(1, $this->pending());
        self::assertSame(1, $this->service->requestAcknowledgements($this->document));
    }

    #[Test]
    public function peopleWhoJoinLaterAreAskedAndANewFileWithdrawsTheOldRequest(): void
    {
        $this->service->requestAcknowledgements($this->document);
        $this->service->acknowledge($this->document, $this->users[0], PolicyAcknowledgementService::METHOD_WEB_CLICK);

        $this->users[] = $this->user(3);
        $this->service->requestAcknowledgements($this->document);
        self::assertSame(['2', '3'], $this->pendingUserIds(), 'the newcomer gets a request');

        // A new file is a new version: the old request goes, everybody is asked again,
        // the old acknowledgement stays as evidence.
        $this->document->setSha256Hash(str_repeat('2', 64));
        self::assertSame(3, $this->service->requestAcknowledgements($this->document));
        self::assertSame(['1', '2', '3'], $this->pendingUserIds());
        foreach ($this->pending() as $row) {
            self::assertSame(PolicyAcknowledgementService::versionOf($this->document), $row->getDocumentVersion());
        }
        self::assertCount(4, $this->rows, '3 open requests + the first acknowledgement');
    }

    #[Test]
    public function aDeactivatedPersonAndAnUnpublishedDocumentKeepNoOpenRequest(): void
    {
        $this->service->requestAcknowledgements($this->document);
        $this->users[1]->setIsActive(false);
        $this->service->requestAcknowledgements($this->document);
        self::assertSame(['1'], $this->pendingUserIds());

        self::assertSame(1, $this->service->withdrawOpenRequests($this->document));
        self::assertSame([], $this->pending());
    }

    #[Test]
    public function aDocumentThatAsksNobodyGetsNoRequest(): void
    {
        $this->document->setRequiresAcknowledgement(false);
        self::assertSame(0, $this->service->requestAcknowledgements($this->document));
        self::assertSame([], $this->rows);
    }

    /** @return list<PolicyAcknowledgement> */
    private function pending(): array
    {
        return array_values(array_filter(
            $this->rows,
            static fn (PolicyAcknowledgement $r): bool => $r->getStatus() === PolicyAcknowledgement::STATUS_PENDING,
        ));
    }

    /** @return list<string> */
    private function pendingUserIds(): array
    {
        $ids = array_map(static fn (PolicyAcknowledgement $r): string => (string) $r->getUser()?->getId(), $this->pending());
        sort($ids);

        return $ids;
    }

    private function user(int $id): User
    {
        $user = $this->entity(new User(), $id);
        $user->setTenant($this->tenant);
        $user->setIsActive(true);
        $user->setEmail('u' . $id . '@example.test');

        return $user;
    }

    /**
     * @template T of object
     * @param T $entity
     * @return T
     */
    private function entity(object $entity, int $id): object
    {
        (new \ReflectionProperty($entity, 'id'))->setValue($entity, $id);

        return $entity;
    }
}
