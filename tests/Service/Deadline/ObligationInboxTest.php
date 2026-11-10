<?php

declare(strict_types=1);

namespace App\Tests\Service\Deadline;

use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Deadline\ObligationInbox;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Tests\Stub\FakeDeadlineSource;
use App\Tests\Stub\PersonaResolverFactory;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ObligationInboxTest extends TestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $other;
    private User $riskManager;
    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->owner = $this->user(1, ['ROLE_USER']);
        $this->other = $this->user(2, ['ROLE_USER']);
        $this->riskManager = $this->user(3, ['ROLE_RISK_MANAGER']);
        $this->now = new DateTimeImmutable('2026-11-07 10:00');
    }

    #[Test]
    public function aDutyAppearsOnlyAtThePersonResponsible(): void
    {
        $inbox = $this->inbox(new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue'), [
            FakeDeadlineSource::record(1, Responsibility::holders($this->owner)->orRoles('ROLE_RISK_MANAGER'), $this->now),
        ]));

        self::assertCount(1, $inbox->forUser($this->owner, $this->tenant, $this->now));
        self::assertSame([], $inbox->forUser($this->other, $this->tenant, $this->now));
        self::assertSame([], $inbox->forUser($this->riskManager, $this->tenant, $this->now), 'The role is only the fallback.');
    }

    #[Test]
    public function roleFallbackWhenTheRecordHasNobody(): void
    {
        $inbox = $this->inbox(new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue'), [
            FakeDeadlineSource::record(1, Responsibility::holders(null)->orRoles('ROLE_RISK_MANAGER'), $this->now),
        ]));

        self::assertCount(1, $inbox->forUser($this->riskManager, $this->tenant, $this->now));
        self::assertSame([3], array_keys($inbox->byRecipient($this->tenant, $this->now)));
    }

    #[Test]
    public function informedRolesGetTheDutyBesideTheOneWhoActs(): void
    {
        $inbox = $this->inbox(new FakeDeadlineSource('reporting_duty', new DeadlineProfile('reporting_duties'), [
            FakeDeadlineSource::record(1, Responsibility::holders($this->owner)->alsoInform('ROLE_RISK_MANAGER'), $this->now),
        ]));

        self::assertCount(1, $inbox->forUser($this->owner, $this->tenant, $this->now));
        self::assertCount(1, $inbox->forUser($this->riskManager, $this->tenant, $this->now));
        self::assertSame([], $inbox->forUser($this->other, $this->tenant, $this->now));
    }

    #[Test]
    public function informingNeverReplacesTheOneWhoActs(): void
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveByTenant')->willReturn([$this->owner, $this->other, $this->riskManager]);
        $resolver = new ResponsibilityResolver($users, PersonaResolverFactory::create(), new DeadlineSourceRegistry([]));
        $who = Responsibility::holders($this->owner)->alsoInform($this->other);

        self::assertSame([$this->owner], $resolver->resolve($who, $this->tenant));
        self::assertSame([$this->owner, $this->other], $resolver->recipients($who, $this->tenant));
    }

    #[Test]
    public function leadWindowCompletionAndOverdueFlag(): void
    {
        $who = Responsibility::holders($this->owner);
        $inbox = $this->inbox(new FakeDeadlineSource('x', new DeadlineProfile('x_due', leadDays: 14), [
            FakeDeadlineSource::record(1, $who, $this->now->modify('-2 days')),
            FakeDeadlineSource::record(2, $who, $this->now->modify('+10 days')),
            FakeDeadlineSource::record(3, $who, $this->now->modify('+40 days')),
            FakeDeadlineSource::record(4, $who, $this->now->modify('-5 days'), done: true),
            FakeDeadlineSource::record(5, $who),
        ]));

        $items = $inbox->forUser($this->owner, $this->tenant, $this->now);

        self::assertSame(['Record 1', 'Record 2', 'Record 5'], array_map(static fn ($o) => $o->title, $items));
        self::assertTrue($items[0]->overdue);
        self::assertFalse($items[1]->overdue);
        self::assertNull($items[2]->dueDate, 'Undated duties sort last.');
    }

    #[Test]
    public function hourDeadlinesAreUrgentWithinTheirWindow(): void
    {
        $inbox = $this->inbox(new FakeDeadlineSource('breach', new DeadlineProfile('data_breaches_72h_ticking', leadDays: null, urgentHours: 72), [
            FakeDeadlineSource::record(1, Responsibility::holders($this->owner), $this->now->modify('+20 hours')),
            FakeDeadlineSource::record(2, Responsibility::holders($this->owner), $this->now->modify('+5 days')),
        ]));

        $items = $inbox->forUser($this->owner, $this->tenant, $this->now);

        self::assertTrue($items[0]->urgent);
        self::assertFalse($items[0]->overdue);
        self::assertFalse($items[1]->urgent);
    }

    #[Test]
    public function aBrokenSourceDoesNotEmptyTheInbox(): void
    {
        $inbox = $this->inbox(
            new FakeDeadlineSource('broken', new DeadlineProfile('a'), broken: true),
            new FakeDeadlineSource('ok', new DeadlineProfile('b'), [
                FakeDeadlineSource::record(1, Responsibility::holders($this->owner)),
            ]),
        );

        self::assertCount(1, $inbox->forUser($this->owner, $this->tenant, $this->now));
    }

    #[Test]
    public function theTenantInboxIsBuiltOncePerRequestUntilReset(): void
    {
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 9);
        $source = new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue'), [
            FakeDeadlineSource::record(1, Responsibility::holders($this->owner), $this->now),
        ]);
        $inbox = $this->inbox($source);

        $inbox->forUser($this->owner, $this->tenant, $this->now);
        $inbox->forUser($this->other, $this->tenant, $this->now);
        $inbox->byRecipient($this->tenant, $this->now);
        self::assertSame(1, $source->reads, 'S31: My Day, bell and dashboard share one build.');

        $inbox->reset();
        $inbox->forUser($this->owner, $this->tenant, $this->now);
        self::assertSame(2, $source->reads, 'reset() (end of request / worker message) drops it.');
    }

    private function inbox(FakeDeadlineSource ...$sources): ObligationInbox
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveByTenant')->willReturn([$this->owner, $this->other, $this->riskManager]);
        $registry = new DeadlineSourceRegistry($sources);

        return new ObligationInbox($registry, new ResponsibilityResolver($users, PersonaResolverFactory::create(), $registry));
    }

    /** @param list<string> $roles */
    private function user(int $id, array $roles): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setEmail('u' . $id . '@example.test');
        $user->setRoles($roles);
        $user->setIsActive(true);
        $user->setTenant($this->tenant);

        return $user;
    }
}
