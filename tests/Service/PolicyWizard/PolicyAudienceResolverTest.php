<?php

declare(strict_types=1);

namespace App\Tests\Service\PolicyWizard;

use App\Entity\Document;
use App\Entity\PolicyTemplate;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\PolicyWizard\PolicyAudienceResolver;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for {@see PolicyAudienceResolver}.
 *
 * Audience-resolution priority order (per W3-L spec):
 *  1. explicit `_audience` override on Document.substitutionVariables
 *  2. PolicyTemplate.topic heuristic (TOPIC_AUDIENCE_MAP)
 *  3. fallback: every active user in the document's tenant
 */
#[AllowMockObjectsWithoutExpectations]
final class PolicyAudienceResolverTest extends TestCase
{
    private function makeTenant(int $id = 7): Tenant
    {
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn($id);
        return $tenant;
    }

    /**
     * @param list<string> $roles
     */
    private function makeUser(int $id, Tenant $tenant, array $roles = ['ROLE_USER'], bool $active = true): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getTenant')->willReturn($tenant);
        $user->method('getRoles')->willReturn($roles);
        $user->method('isActive')->willReturn($active);
        return $user;
    }

    private function makeTemplate(?string $topic): PolicyTemplate
    {
        $template = $this->createStub(PolicyTemplate::class);
        $template->method('getTopic')->willReturn($topic);
        return $template;
    }

    private function makeDocument(
        Tenant $tenant,
        ?PolicyTemplate $template = null,
        ?array $substitutionVariables = null,
    ): Document {
        $doc = $this->createStub(Document::class);
        $doc->method('getTenant')->willReturn($tenant);
        $doc->method('getGeneratedFromTemplate')->willReturn($template);
        $doc->method('getSubstitutionVariables')->willReturn($substitutionVariables);
        return $doc;
    }

    #[Test]
    public function defaultFallbackIsAllActiveUsersInTenant(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser(1, $tenant);
        $userB = $this->makeUser(2, $tenant);
        $userOtherTenant = $this->makeUser(3, $this->makeTenant(99));

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturn([$userA, $userB, $userOtherTenant]);

        $resolver = new PolicyAudienceResolver($userRepo);
        $audience = $resolver->resolveAudience($this->makeDocument($tenant));

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $audience);
        self::assertSame([1, 2], $ids, 'Other-tenant user must be filtered out.');
    }

    #[Test]
    public function topicHeuristicNarrowsCryptographyToItOps(): void
    {
        $tenant = $this->makeTenant();
        $userIt = $this->makeUser(1, $tenant, ['ROLE_USER', 'ROLE_IT_OPERATIONS']);
        $userPlain = $this->makeUser(2, $tenant, ['ROLE_USER']);
        $userAdmin = $this->makeUser(3, $tenant, ['ROLE_USER', 'ROLE_ADMIN']);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturn([$userIt, $userPlain, $userAdmin]);

        $resolver = new PolicyAudienceResolver($userRepo);
        $audience = $resolver->resolveAudience(
            $this->makeDocument($tenant, $this->makeTemplate('cryptography')),
        );

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $audience);
        sort($ids);
        self::assertSame([1, 3], $ids, 'Cryptography is scoped to IT-Operations + Admins.');
    }

    #[Test]
    public function topicHeuristicNarrowsPrivacyToDpoOnly(): void
    {
        $tenant = $this->makeTenant();
        $userDpo = $this->makeUser(1, $tenant, ['ROLE_USER', 'ROLE_DPO']);
        $userPlain = $this->makeUser(2, $tenant, ['ROLE_USER']);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturn([$userDpo, $userPlain]);

        $resolver = new PolicyAudienceResolver($userRepo);
        $audience = $resolver->resolveAudience(
            $this->makeDocument($tenant, $this->makeTemplate('privacy_pii')),
        );

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $audience);
        self::assertSame([1], $ids, 'Privacy/PII policies target ROLE_DPO only.');
    }

    #[Test]
    public function explicitAudienceOverrideTakesPriority(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser(1, $tenant);
        $userB = $this->makeUser(2, $tenant);
        $userC = $this->makeUser(3, $tenant);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturn([$userA, $userB, $userC]);
        $userRepo->method('find')->willReturnCallback(static function (int $id) use ($userA, $userB, $userC): ?User {
            return match ($id) {
                1 => $userA,
                2 => $userB,
                3 => $userC,
                default => null,
            };
        });

        // Override carries explicit user IDs — even though the topic
        // would default to "everyone", the override wins.
        $document = $this->makeDocument(
            $tenant,
            $this->makeTemplate('hr_security'),
            ['_audience' => [1, 3]],
        );

        $resolver = new PolicyAudienceResolver($userRepo);
        $audience = $resolver->resolveAudience($document);

        $ids = array_map(static fn (User $u): ?int => $u->getId(), $audience);
        sort($ids);
        self::assertSame([1, 3], $ids, 'Explicit user-ID override wins over topic heuristic.');
    }

    #[Test]
    public function unknownTopicFallsBackToAllActiveUsers(): void
    {
        $tenant = $this->makeTenant();
        $userA = $this->makeUser(1, $tenant);
        $userB = $this->makeUser(2, $tenant, ['ROLE_USER', 'ROLE_DPO']);

        $userRepo = $this->createMock(UserRepository::class);
        $userRepo->method('findActiveUsers')->willReturn([$userA, $userB]);

        $resolver = new PolicyAudienceResolver($userRepo);
        // `acceptable_use` is intentionally NOT in TOPIC_AUDIENCE_MAP —
        // everyone gets it.
        $audience = $resolver->resolveAudience(
            $this->makeDocument($tenant, $this->makeTemplate('acceptable_use')),
        );

        self::assertCount(2, $audience);
    }
}
