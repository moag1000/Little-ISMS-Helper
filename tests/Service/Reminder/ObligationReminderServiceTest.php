<?php

declare(strict_types=1);

namespace App\Tests\Service\Reminder;

use App\Entity\ObligationNotice;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\ObligationNoticeRepository;
use App\Repository\TenantRepository;
use App\Repository\UserRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Deadline\ObligationInbox;
use App\Service\EmailNotificationService;
use App\Service\Notification\Event\DomainEventNotifier;
use App\Service\Reminder\ObligationReminderService;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Tests\Stub\FakeDeadlineSource;
use App\Tests\Stub\PersonaResolverFactory;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AllowMockObjectsWithoutExpectations]
final class ObligationReminderServiceTest extends TestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $optedOut;
    /** @var list<string> */
    private array $mails = [];
    /** @var list<ObligationNotice> */
    private array $persisted = [];
    /** @var array<string, true> */
    private array $sent = [];
    /** @var list<string> */
    private array $events = [];
    private int $clears = 0;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        (new \ReflectionProperty(Tenant::class, 'id'))->setValue($this->tenant, 1);
        $this->tenant->setTimezone('Europe/Berlin');
        $this->owner = $this->user(1, true);
        $this->optedOut = $this->user(2, false);
    }

    #[Test]
    public function digestGoesOutAtTheOrganisationHourOncePerDayAndRespectsTheOptOut(): void
    {
        $service = $this->service(new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue'), [
            FakeDeadlineSource::record(1, Responsibility::holders($this->owner, $this->optedOut), new DateTimeImmutable('2026-11-01')),
        ]));
        $seven = new DateTimeImmutable('2026-11-09 07:05', new \DateTimeZone('Europe/Berlin'));

        self::assertSame(0, $service->sendDigests(new DateTimeImmutable('2026-11-09 09:05', new \DateTimeZone('Europe/Berlin'))));
        self::assertSame(1, $service->sendDigests($seven));
        self::assertSame(['u1@example.test'], $this->mails, 'Only the person who did not opt out.');
        self::assertSame('digest:2026-11-09', $this->persisted[0]->getNoticeKey());

        $this->sent = ['digest:2026-11-09' => true];
        self::assertSame(0, $service->sendDigests($seven->modify('+15 minutes')), 'Once per day.');
    }

    #[Test]
    public function hourDeadlinesGoOutImmediatelyOnceAndEvenWithTheDigestSwitchedOff(): void
    {
        $now = new DateTimeImmutable('2026-11-09 13:00');
        $service = $this->service(new FakeDeadlineSource('data_breach', new DeadlineProfile('data_breaches_72h_ticking', leadDays: null, urgentHours: 72), [
            FakeDeadlineSource::record(7, Responsibility::holders($this->optedOut), $now->modify('+30 hours')),
        ]), new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue'), [
            FakeDeadlineSource::record(8, Responsibility::holders($this->optedOut), $now->modify('-1 day')),
        ]));

        self::assertSame(1, $service->sendUrgent($now));
        self::assertSame(['u2@example.test'], $this->mails);
        self::assertCount(1, $this->persisted, 'Only the hour deadline, not the day deadline.');
        self::assertStringStartsWith('urgent:data_breach:7:', $this->persisted[0]->getNoticeKey());
        self::assertSame(['obligation.urgent'], $this->events);

        $this->sent = [$this->persisted[0]->getNoticeKey() => true];
        self::assertSame(0, $service->sendUrgent($now->modify('+15 minutes')));
    }

    #[Test]
    public function theUrgentRunReadsOnlyHourSourcesAndEachRunClearsAfterTheOrganisation(): void
    {
        $now = new DateTimeImmutable('2026-11-09 13:00');
        $daySource = new FakeDeadlineSource('risk_review', new DeadlineProfile('risk_review_overdue'), [
            FakeDeadlineSource::record(8, Responsibility::holders($this->owner), $now->modify('-1 day')),
        ]);
        $service = $this->service($daySource);

        self::assertSame(0, $service->sendUrgent($now));
        self::assertSame(0, $daySource->reads, 'S31: the urgent run every 15 minutes does not build the full inbox.');
        self::assertSame(1, $this->clears, 'S31: the entity manager is cleared after each organisation.');
    }

    private function service(FakeDeadlineSource ...$sources): ObligationReminderService
    {
        $tenants = $this->createStub(TenantRepository::class);
        $tenants->method('findActive')->willReturn([$this->tenant]);
        $tenants->method('find')->willReturn($this->tenant);
        $users = $this->createStub(UserRepository::class);
        $users->method('findActiveByTenant')->willReturn([$this->owner, $this->optedOut]);
        $registry = new DeadlineSourceRegistry($sources);
        $inbox = new ObligationInbox($registry, new ResponsibilityResolver($users, PersonaResolverFactory::create(), $registry));

        $notices = $this->createStub(ObligationNoticeRepository::class);
        $notices->method('sentKeys')->willReturnCallback(
            fn (User $u, array $keys): array => array_intersect_key($this->sent, array_flip($keys)),
        );
        $mail = $this->createStub(EmailNotificationService::class);
        $mail->method('sendToUser')->willReturnCallback(function (User $u): void {
            $this->mails[] = (string) $u->getEmail();
        });
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('clear')->willReturnCallback(function (): void {
            ++$this->clears;
        });
        $entityManager->method('persist')->willReturnCallback(function (object $o): void {
            if ($o instanceof ObligationNotice) {
                $this->persisted[] = $o;
            }
        });
        $urls = $this->createStub(UrlGeneratorInterface::class);
        $urls->method('generate')->willReturn('/x');
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);
        $events = $this->createStub(DomainEventNotifier::class);
        $events->method('fire')->willReturnCallback(function (string $type): void {
            $this->events[] = $type;
        });

        return new ObligationReminderService($tenants, $inbox, $notices, $mail, $entityManager, $urls, $translator, $events, new NullLogger());
    }

    private function user(int $id, bool $digest): User
    {
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);
        $user->setEmail('u' . $id . '@example.test');
        $user->setRoles(['ROLE_USER']);
        $user->setIsActive(true);
        $user->setTenant($this->tenant);
        $user->setReminderDigestEnabled($digest);

        return $user;
    }
}
