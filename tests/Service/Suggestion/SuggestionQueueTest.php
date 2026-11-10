<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion;

use App\Entity\DataBreach;
use App\Entity\Risk;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Enum\SuggestionStatus;
use App\Repository\SuggestionRepository;
use App\Service\AuditLogger;
use App\Service\ModuleConfigurationService;
use App\Service\Ai\AiAssistant;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\Kind\AbstractAiKind;
use App\Service\Suggestion\StaleSuggestionException;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionQueue;
use App\Service\Suggestion\SuggestionResponsibility;
use App\Service\Suggestion\TargetAccess;
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

    #[Test]
    public function aRecordHolderWithoutTheWriteRightCannotDecide(): void
    {
        // S30 F3: the holder of the record is responsible, but the write needs ROLE_MANAGER in the UI.
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $queue = $this->deciding(kind: $this->kind(access: 'ROLE_MANAGER'), granted: ['ROLE_USER'], responsible: true);

        self::assertFalse($queue->canDecide($s));
        self::assertFalse($queue->isVisible($s), 'a plain user sees only what they decide');
    }

    #[Test]
    public function aRecordHolderWithTheWriteRightDecides(): void
    {
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $queue = $this->deciding(kind: $this->kind(access: 'ROLE_USER'), granted: ['ROLE_USER'], responsible: true);

        self::assertTrue($queue->canDecide($s));
        self::assertTrue($queue->isVisible($s));
    }

    #[Test]
    public function aDecisionRoleStillNeedsTheWriteRight(): void
    {
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        // decision role ROLE_ISB held, the write right (ROLE_MANAGER) not
        $queue = $this->deciding(kind: $this->kind(access: 'ROLE_MANAGER', roles: ['ROLE_ISB']), granted: ['ROLE_ISB']);

        self::assertFalse($queue->canDecide($s));
    }

    #[Test]
    public function readersSeeTheQueueButRecordsOfPeopleOnlyWithTheDpoRole(): void
    {
        $plain = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k1');
        $breach = (new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Ai, 'T', 'R', 'k2'))
            ->setSubject(DataBreach::class, 7);
        $kind = $this->kind(access: 'ROLE_ADMIN');

        $reader = $this->deciding(kind: $kind, granted: ['ROLE_AUDITOR']);
        self::assertTrue($reader->isVisible($plain));
        self::assertFalse($reader->isVisible($breach), 'a manager without DPO does not read breach drafts');
        self::assertSame([$plain], $reader->visible([$plain, $breach]));

        $dpo = $this->deciding(kind: $kind, granted: ['ROLE_AUDITOR', 'ROLE_DPO']);
        self::assertTrue($dpo->isVisible($breach));

        $user = $this->deciding(kind: $kind, granted: ['ROLE_USER']);
        self::assertSame([], $user->visible([$plain, $breach]), 'ROLE_USER sees no foreign drafts');
    }

    #[Test]
    public function aSecondAcceptAppliesNothing(): void
    {
        $kind = $this->kind();
        $s = new Suggestion($this->tenant, 'test.kind', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $queue = $this->queue(kinds: [$kind]);
        $queue->accept($s, new User());

        try {
            $queue->accept($s, new User());
            self::fail('expected StaleSuggestionException');
        } catch (StaleSuggestionException) {
        }

        self::assertSame(1, $kind->applied, 'double click creates one record');
        self::assertSame(SuggestionStatus::Accepted, $s->getStatus());
    }

    #[Test]
    public function anAiDraftWhoseRecordIsClosedIsWithdrawn(): void
    {
        // S30 F5: e.g. breach notification text after the authority was notified.
        $s = (new Suggestion($this->tenant, 'test.ai', SuggestionOrigin::Ai, 'T', 'R', 'k'))->setSubject(Risk::class, 3);
        $kind = $this->aiKind(candidate: false);
        $responsibility = $this->createStub(SuggestionResponsibility::class);
        $responsibility->method('subject')->willReturn(new Risk());

        try {
            $this->queue(kinds: [$kind], responsibility: $responsibility)->accept($s, new User());
            self::fail('expected StaleSuggestionException');
        } catch (StaleSuggestionException) {
        }

        self::assertFalse($kind->applied, 'nothing is written into a closed record');
        self::assertSame(SuggestionStatus::Superseded, $s->getStatus());
    }

    /**
     * @param list<string> $granted roles the stub security grants (no hierarchy)
     */
    private function deciding(AbstractSuggestionKind $kind, array $granted, bool $responsible = false): SuggestionQueue
    {
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturnCallback(
            static fn (mixed $attribute): bool => is_string($attribute) && in_array($attribute, $granted, true),
        );
        $security->method('getUser')->willReturn(new User());
        $modules = $this->createStub(ModuleConfigurationService::class);
        $modules->method('isModuleActiveForTenant')->willReturn(true);
        $resolver = $this->createStub(ResponsibilityResolver::class);
        $resolver->method('isResponsible')->willReturn($responsible);
        $responsibility = $this->createStub(SuggestionResponsibility::class);
        $responsibility->method('of')->willReturn(Responsibility::roles('ROLE_MANAGER'));

        return new SuggestionQueue(
            [$kind],
            $this->createStub(SuggestionRepository::class),
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(AuditLogger::class),
            $security,
            $modules,
            $responsibility,
            $resolver,
        );
    }

    /**
     * @param list<AbstractSuggestionKind>|null $kinds
     */
    private function queue(
        ?SuggestionRepository $repo = null,
        ?EntityManagerInterface $em = null,
        ?AuditLogger $audit = null,
        ?array $kinds = null,
        ?SuggestionResponsibility $responsibility = null,
    ): SuggestionQueue {
        return new SuggestionQueue(
            $kinds ?? [$this->kind()],
            $repo ?? $this->createStub(SuggestionRepository::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
            $audit ?? $this->createStub(AuditLogger::class),
            $this->createStub(Security::class),
            $this->createStub(ModuleConfigurationService::class),
            $responsibility,
        );
    }

    /** @param list<string> $roles */
    private function kind(
        bool $stale = false,
        string $access = 'ROLE_USER',
        array $roles = ['ROLE_MANAGER'],
    ): AbstractSuggestionKind {
        return new class ($stale, $access, $roles) extends AbstractSuggestionKind {
            public ?string $appliedText = null;
            public bool $rejected = false;
            public int $applied = 0;

            /** @param list<string> $roles */
            public function __construct(
                private readonly bool $stale,
                private readonly string $access,
                private readonly array $roles,
            ) {
            }

            public function targetAccess(): TargetAccess
            {
                return TargetAccess::role($this->access, 'test kind');
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
                return $this->roles;
            }

            public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
            {
                if ($this->stale) {
                    throw new StaleSuggestionException('record_gone');
                }
                $this->applied++;
                $this->appliedText = $editedText;

                return new \stdClass();
            }

            public function onReject(Suggestion $suggestion, User $by, ?string $reason): void
            {
                $this->rejected = true;
            }
        };
    }

    private function aiKind(bool $candidate): AbstractAiKind
    {
        $ai = $this->createStub(AiAssistant::class);
        $repository = $this->createStub(SuggestionRepository::class);

        return new class ($candidate, $ai, $repository) extends AbstractAiKind {
            public bool $applied = false;

            public function __construct(private readonly bool $candidate, AiAssistant $ai, SuggestionRepository $suggestions)
            {
                parent::__construct($ai, $suggestions);
            }

            public function key(): string
            {
                return 'test.ai';
            }

            public function decisionRoles(): array
            {
                return ['ROLE_MANAGER'];
            }

            public function targetAccess(): TargetAccess
            {
                return TargetAccess::role('ROLE_USER', 'test kind');
            }

            public function subjectClasses(): array
            {
                return [Risk::class];
            }

            public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
            {
                $this->applied = true;

                return new \stdClass();
            }

            protected function candidates(Tenant $tenant): iterable
            {
                return [];
            }

            protected function isCandidate(object $record): bool
            {
                return $this->candidate;
            }

            protected function instructions(): string
            {
                return '';
            }

            protected function describe(object $record): string
            {
                return '';
            }

            protected function toDraft(object $record, array $data, string $model, Tenant $tenant): ?SuggestionDraft
            {
                return null;
            }
        };
    }
}
