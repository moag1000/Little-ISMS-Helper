<?php

declare(strict_types=1);

namespace App\Tests\Service\Responsibility;

use App\Entity\Person;
use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Deadline\DeadlineProfile;
use App\Service\Deadline\DeadlineSource;
use App\Service\Deadline\DeadlineSourceRegistry;
use App\Service\Deadline\RecordLink;
use App\Service\Responsibility\Responsibility;
use App\Service\Responsibility\ResponsibilityResolver;
use App\Tests\Stub\PersonaResolverFactory;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ResponsibilityResolverTest extends TestCase
{
    private Tenant $tenant;
    private User $owner;
    private User $isb;
    private User $ciso;
    private User $admin;
    private User $inactive;

    protected function setUp(): void
    {
        $this->tenant = new Tenant();
        $this->owner = $this->user('owner', ['ROLE_USER']);
        $this->isb = $this->user('isb', ['ROLE_ISB']);
        $this->ciso = $this->user('ciso', ['ROLE_CISO']);
        $this->admin = $this->user('admin', ['ROLE_ADMIN']);
        $this->inactive = $this->user('gone', ['ROLE_ISB'], active: false);
    }

    #[Test]
    public function holderOnTheRecordWinsOverRoles(): void
    {
        $users = $this->resolver()->resolve(Responsibility::holders($this->owner)->orRoles('ROLE_ISB'), $this->tenant);

        self::assertSame([$this->owner], $users);
    }

    #[Test]
    public function personCountsThroughItsLinkedAccount(): void
    {
        $person = (new Person())->setLinkedUser($this->owner);

        self::assertSame([$this->owner], $this->resolver()->resolve(Responsibility::holders(null, $person), $this->tenant));
    }

    #[Test]
    public function deputyOnlyWhenNoHolderHasAnActiveAccount(): void
    {
        $deputy = (new Person())->setLinkedUser($this->isb);
        $resolver = $this->resolver();

        self::assertSame([$this->owner], $resolver->resolve(
            Responsibility::holders($this->owner)->withDeputies($deputy),
            $this->tenant,
        ));
        self::assertSame([$this->isb], $resolver->resolve(
            Responsibility::holders($this->inactive, new Person())->withDeputies($deputy),
            $this->tenant,
        ));
    }

    #[Test]
    public function roleFallbackUsesHierarchyButNotMereAdminInheritance(): void
    {
        $users = $this->resolver()->resolve(Responsibility::roles('ROLE_ISB', 'ROLE_CISO'), $this->tenant);

        self::assertSame([$this->isb, $this->ciso], $users, 'Admins inherit ISB but are not the ISB.');
    }

    #[Test]
    public function managerRoleIsReachedThroughHierarchy(): void
    {
        $users = $this->resolver()->resolve(Responsibility::roles('ROLE_MANAGER'), $this->tenant);

        self::assertSame([$this->isb, $this->ciso], $users);
    }

    #[Test]
    public function adminsAreTheLastResort(): void
    {
        $resolver = $this->resolver();

        self::assertSame([$this->admin], $resolver->resolve(Responsibility::roles('ROLE_DPO'), $this->tenant));
        self::assertSame([$this->admin], $resolver->resolve(Responsibility::nobody(), $this->tenant));
    }

    #[Test]
    public function recordResponsibilityGoesThroughItsSource(): void
    {
        $risk = new Risk();
        $resolver = $this->resolver($this->sourceFor($risk, Responsibility::holders($this->owner)));

        self::assertSame([$this->owner], $resolver->resolve(Responsibility::ofRecord($risk)->orRoles('ROLE_ISB'), $this->tenant));
        self::assertTrue($resolver->isResponsible($this->owner, Responsibility::ofRecord($risk), $this->tenant));
        self::assertFalse($resolver->isResponsible($this->isb, Responsibility::ofRecord($risk), $this->tenant));
    }

    #[Test]
    public function recordWithoutResponsibleFallsBackToOwnRoles(): void
    {
        $risk = new Risk();
        $resolver = $this->resolver($this->sourceFor($risk, Responsibility::nobody()));

        self::assertSame([$this->isb], $resolver->resolve(Responsibility::ofRecord($risk)->orRoles('ROLE_ISB'), $this->tenant));
    }

    #[Test]
    public function accountOfAnotherTenantIsIgnored(): void
    {
        $other = new Tenant();
        $reflection = new \ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($other, 2);
        $reflection->setValue($this->tenant, 1);
        $foreign = $this->user('foreign', ['ROLE_USER'])->setTenant($other);

        self::assertSame([$this->admin], $this->resolver()->resolve(Responsibility::holders($foreign), $this->tenant));
    }

    #[Test]
    public function accountWithoutTenantIsIgnored(): void
    {
        $reflection = new \ReflectionProperty(Tenant::class, 'id');
        $reflection->setValue($this->tenant, 1);
        $tenantless = $this->user('tenantless', ['ROLE_USER'])->setTenant(null);

        self::assertSame([$this->admin], $this->resolver()->resolve(Responsibility::holders($tenantless), $this->tenant));
    }

    private function resolver(?DeadlineSource $source = null): ResponsibilityResolver
    {
        $repository = $this->createStub(UserRepository::class);
        $repository->method('findActiveByTenant')->willReturn([$this->owner, $this->isb, $this->ciso, $this->admin]);

        return new ResponsibilityResolver(
            $repository,
            PersonaResolverFactory::create(),
            new DeadlineSourceRegistry($source !== null ? [$source] : []),
        );
    }

    /** @param list<string> $roles */
    private function user(string $name, array $roles, bool $active = true): User
    {
        $user = new User();
        $user->setEmail($name . '@example.test');
        $user->setRoles($roles);
        $user->setIsActive($active);
        $user->setTenant($this->tenant);

        return $user;
    }

    private function sourceFor(object $record, Responsibility $responsibility): DeadlineSource
    {
        return new class($record, $responsibility) implements DeadlineSource {
            public function __construct(private object $record, private Responsibility $responsibility)
            {
            }
            public function slug(): string { return 'test'; }
            public function label(): string { return 'Test'; }
            public function requiredModule(): ?string { return null; }
            public function findOpen(Tenant $tenant): iterable { return [$this->record]; }
            public function dueDateOf(object $item): ?\DateTimeInterface { return null; }
            public function titleOf(object $item): string { return 'Test'; }
            public function isCompleted(object $item): bool { return false; }
            public function refId(object $item): int { return 1; }
            public function supports(object $item): bool { return $item === $this->record; }
            public function responsibleOf(object $item): Responsibility { return $this->responsibility; }
            public function linkOf(object $item): ?RecordLink { return null; }
            public function profile(): DeadlineProfile { return new DeadlineProfile('test'); }
        };
    }
}
