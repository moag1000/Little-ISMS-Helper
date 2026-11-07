<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion;

use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionQueue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class SuggestionQueueTest extends TestCase
{
    private Tenant $tenant;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
    }

    #[Test]
    public function offerCreatesAnEntryWithTheDraftData(): void
    {
        $repo = $this->createStub(SuggestionRepository::class);
        $repo->method('findOneByDedup')->willReturn(null);
        $repo->method('findPendingForSubject')->willReturn([]);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(Suggestion::class));

        $queue = $this->queue($repo, $em);
        $draft = new SuggestionDraft(null, 'Title', 'Because', ['x' => 1], 'Text', 0.75, 'k1');
        $s = $queue->offer($this->tenant, $this->kind(), $draft);

        self::assertInstanceOf(Suggestion::class, $s);
        self::assertSame('test.kind', $s->getKind());
        self::assertSame(SuggestionOrigin::Rule, $s->getOrigin());
        self::assertSame(['x' => 1], $s->getPayload());
        self::assertSame('Text', $s->getEditableText());
        self::assertSame(0.75, $s->getConfidence());
        self::assertSame($draft->resolveDedupKey('test.kind'), $s->getDedupKey());
        self::assertTrue($s->isPending());
    }

    #[Test]
    public function offerIgnoresAKnownProposal(): void
    {
        $known = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $known->decide(SuggestionStatus::Rejected, null, 'no');
        $repo = $this->createStub(SuggestionRepository::class);
        $repo->method('findOneByDedup')->willReturn($known);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');

        $result = $this->queue($repo, $em)->offer($this->tenant, $this->kind(), new SuggestionDraft(null, 'T', 'R', dedupKey: 'k'));

        self::assertNull($result, 'a rejected proposal does not come back');
        self::assertSame(SuggestionStatus::Rejected, $known->getStatus());
    }

    #[Test]
    public function offerReopensASupersededTwin(): void
    {
        $twin = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'Old', 'Old', 'k');
        $twin->decide(SuggestionStatus::Superseded, null, 'condition_gone');
        $repo = $this->createStub(SuggestionRepository::class);
        $repo->method('findOneByDedup')->willReturn($twin);
        $repo->method('findPendingForSubject')->willReturn([]);

        $result = $this->queue($repo)->offer($this->tenant, $this->kind(), new SuggestionDraft(null, 'New', 'New why', dedupKey: 'k'));

        self::assertSame($twin, $result);
        self::assertTrue($twin->isPending());
        self::assertSame('New', $twin->getTitle());
        self::assertNull($twin->getDecidedAt());
    }

    #[Test]
    public function offerSupersedesAnOlderProposalOfTheSameKindForTheSameRecord(): void
    {
        $risk = new Risk();
        $ref = new \ReflectionProperty(Risk::class, 'id');
        $ref->setValue($risk, 42);
        $older = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'Old', 'Old', 'old');
        $otherKind = new Suggestion($this->tenant, 'other.kind', SuggestionOrigin::Rule, 'Other', 'Other', 'other');
        $repo = $this->createStub(SuggestionRepository::class);
        $repo->method('findOneByDedup')->willReturn(null);
        $repo->method('findPendingForSubject')->willReturn([$older, $otherKind]);

        $new = $this->queue($repo)->offer($this->tenant, $this->kind(), new SuggestionDraft($risk, 'New', 'Why', dedupKey: 'new'));

        self::assertInstanceOf(Suggestion::class, $new);
        self::assertSame(Risk::class, $new->getSubjectClass());
        self::assertSame(42, $new->getSubjectId());
        self::assertSame(SuggestionStatus::Superseded, $older->getStatus());
        self::assertTrue($otherKind->isPending(), 'other kinds stay');
    }

    #[Test]
    public function acceptAppliesTheKindRecordsTheDecisionAndLogs(): void
    {
        $kind = $this->kind();
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $s->setEditableText('Proposed');
        $audit = $this->createMock(AuditLogger::class);
        $audit->expects(self::once())->method('logCustom')
            ->with('suggestion.accepted', 'Suggestion', null, null, self::callback(
                static fn (array $v): bool => $v['kind'] === 'test.kind' && $v['edited'] === true,
            ));
        $user = new User();

        $this->queue(audit: $audit, kinds: [$kind])->accept($s, $user, 'Edited by hand', 'ok');

        self::assertSame(SuggestionStatus::Accepted, $s->getStatus());
        self::assertSame($user, $s->getDecidedBy());
        self::assertSame('ok', $s->getDecisionNote());
        self::assertSame('Edited by hand', $kind->appliedText);
    }

    #[Test]
    public function aStaleProposalIsWithdrawnInsteadOfApplied(): void
    {
        $kind = $this->kind(stale: true);
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');

        try {
            $this->queue(kinds: [$kind])->accept($s, new User());
            self::fail('expected StaleSuggestionException');
        } catch (StaleSuggestionException) {
        }

        self::assertSame(SuggestionStatus::Superseded, $s->getStatus());
    }

    #[Test]
    public function rejectRecordsTheReasonAndCallsTheKind(): void
    {
        $kind = $this->kind();
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');

        $this->queue(kinds: [$kind])->reject($s, new User(), '  not relevant  ');

        self::assertSame(SuggestionStatus::Rejected, $s->getStatus());
        self::assertSame('not relevant', $s->getDecisionNote());
        self::assertTrue($kind->rejected);
    }

    #[Test]
    public function aDecidedProposalCannotBeDecidedAgain(): void
    {
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $s->decide(SuggestionStatus::Accepted, null, null);

        $this->expectException(StaleSuggestionException::class);
        $this->queue()->reject($s, new User(), null);
    }

    /**
     * @param list<AbstractSuggestionKind>|null $kinds
     */
    private function queue(
        ?SuggestionRepository $repo = null,
        ?EntityManagerInterface $em = null,
        ?AuditLogger $audit = null,
        ?array $kinds = null,
    ): SuggestionQueue {
        return new SuggestionQueue(
            $kinds ?? [$this->kind()],
            $repo ?? $this->createStub(SuggestionRepository::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            $audit ?? $this->createStub(AuditLogger::class),
            $this->createStub(Security::class),
            $this->createStub(ModuleConfigurationService::class),
        );
    }

    private function kind(bool $stale = false): AbstractSuggestionKind
    {
        return new class ($stale) extends AbstractSuggestionKind {
            public ?string $appliedText = null;
            public bool $rejected = false;

            public function __construct(private readonly bool $stale)
            {
            }

            public function key(): string
            {
                return 'test.kind';
            }

            public function origin(): SuggestionOrigin
            {
                return SuggestionOrigin::Rule;
            }

            public function decisionRoles(): array
            {
                return ['ROLE_MANAGER'];
            }

            public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
            {
                if ($this->stale) {
                    throw new StaleSuggestionException('record_gone');
                }
                $this->appliedText = $editedText;

                return new \stdClass();
            }

            public function onReject(Suggestion $suggestion, User $by, ?string $reason): void
            {
                $this->rejected = true;
            }
        };
    }
}
