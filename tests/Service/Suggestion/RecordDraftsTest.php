<?php

declare(strict_types=1);

namespace App\Tests\Service\Suggestion;

use App\Entity\Control;
use App\Entity\Incident;
use App\Entity\Suggestion;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\SuggestionOrigin;
use App\Repository\SuggestionRepository;
use App\Service\Ai\AiAssistant;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Service\Suggestion\AbstractSuggestionKind;
use App\Service\Suggestion\RecordDraftKind;
use App\Service\Suggestion\RecordDrafts;
use App\Service\Suggestion\SuggestionDraft;
use App\Service\Suggestion\SuggestionQueue;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

final class RecordDraftsTest extends TestCase
{
    private Tenant $tenant;
    private Control $control;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->control = (new Control())->setTenant($this->tenant);
        (new \ReflectionProperty(Control::class, 'id'))->setValue($this->control, 5);
    }

    #[Test]
    public function offersTheKindsForTheRecordTheUserMayDecide(): void
    {
        $kinds = $this->drafts([$this->kind('rule.ok'), $this->kind('rule.other_class', Incident::class)])->kindsFor($this->control);

        self::assertSame(['rule.ok'], array_map(static fn (RecordDraftKind $k): string => $k->key(), $kinds));
    }

    #[Test]
    public function hidesAKindWithAPendingDraftOrWithoutAModelOrPermission(): void
    {
        $pending = new Suggestion($this->tenant, 'rule.pending', SuggestionOrigin::Rule, 'T', 'R', 'k');
        $drafts = $this->drafts(
            [$this->kind('rule.pending'), $this->kind('ai.no_model', model: true), $this->kind('rule.not_supported', supports: false)],
            pending: [$pending],
        );
        self::assertSame([], $drafts->kindsFor($this->control));

        self::assertSame([], $this->drafts([$this->kind('rule.ok')], granted: false)->kindsFor($this->control));
    }

    #[Test]
    public function askingAgainAfterADecisionGetsItsOwnEntry(): void
    {
        $kind = $this->kind('rule.ok');
        $created = new Suggestion($this->tenant, 'rule.ok', SuggestionOrigin::Rule, 'T', 'R', 'k2');
        $queue = $this->createMock(SuggestionQueue::class);
        $queue->method('isKindActive')->willReturn(true);
        $keys = [];
        $queue->expects(self::exactly(2))->method('offer')->willReturnCallback(
            static function (Tenant $t, $k, SuggestionDraft $d) use (&$keys, $created): ?Suggestion {
                $keys[] = $d->dedupKey;

                return count($keys) === 1 ? null : $created;
            },
        );

        $result = $this->drafts([$kind], queue: $queue)->draft($this->tenant, $kind, $this->control);

        self::assertSame($created, $result);
        self::assertSame('control:5', $keys[0]);
        self::assertStringStartsWith('control:5:asked:', (string) $keys[1]);
    }

    #[Test]
    public function resolvesOnlyWhitelistedClassesOfTheSameOrganisation(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturn($this->control);
        $drafts = $this->drafts([$this->kind('rule.ok')], em: $em);
        $kind = $this->kind('rule.ok');

        self::assertSame($this->control, $drafts->resolve($kind, Control::class, 5, $this->tenant));
        self::assertNull($drafts->resolve($kind, User::class, 5, $this->tenant), 'class outside the kind');
        self::assertNull($drafts->resolve($kind, Control::class, 5, new Tenant()), 'other organisation');
    }

    /**
     * @param list<RecordDraftKind> $kinds
     * @param list<Suggestion> $pending
     */
    private function drafts(
        array $kinds,
        array $pending = [],
        bool $granted = true,
        ?SuggestionQueue $queue = null,
        ?EntityManagerInterface $em = null,
    ): RecordDrafts {
        if ($queue === null) {
            $queue = $this->createStub(SuggestionQueue::class);
            $queue->method('kinds')->willReturn(array_combine(array_map(static fn ($k) => $k->key(), $kinds), $kinds));
            $queue->method('isKindActive')->willReturn(true);
        }
        $repo = $this->createStub(SuggestionRepository::class);
        $repo->method('findPendingForSubject')->willReturn($pending);
        $ai = $this->createStub(AiAssistant::class);
        $ai->method('isAvailable')->willReturn(false);
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);
        $security->method('getUser')->willReturn(null);

        return new RecordDrafts(
            $queue,
            $repo,
            $ai,
            $security,
            $this->createStub(ResponsibilityResolver::class),
            $em ?? $this->createStub(EntityManagerInterface::class),
        );
    }

    private function kind(string $key, string $class = Control::class, bool $model = false, bool $supports = true): RecordDraftKind
    {
        return new class ($key, $class, $model, $supports) extends AbstractSuggestionKind implements RecordDraftKind {
            public function __construct(
                private readonly string $key,
                private readonly string $class,
                private readonly bool $model,
                private readonly bool $supports,
            ) {
            }

            public function key(): string
            {
                return $this->key;
            }

            public function origin(): SuggestionOrigin
            {
                return $this->model ? SuggestionOrigin::Ai : SuggestionOrigin::Rule;
            }

            public function decisionRoles(): array
            {
                return ['ROLE_ISB'];
            }

            public function subjectClasses(): array
            {
                return [$this->class];
            }

            public function supportsRecord(object $record): bool
            {
                return $this->supports;
            }

            public function needsModel(): bool
            {
                return $this->model;
            }

            public function draftFor(Tenant $tenant, object $record): ?SuggestionDraft
            {
                return new SuggestionDraft($record, 'Titel', 'Grund', editableText: 'Text', dedupKey: 'control:5');
            }

            public function apply(Suggestion $suggestion, User $by, ?string $editedText): object
            {
                return $suggestion;
            }
        };
    }
}
