<?php

declare(strict_types=1);

namespace App\Tests\AlvaHint;

use App\AlvaHint\AlvaHint;
use App\AlvaHint\AlvaHintRuleInterface;
use App\AlvaHint\AlvaHintService;
use App\Entity\Tenant;
use App\Entity\User;
use App\Repository\AlvaHintDismissalRepository;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

#[AllowMockObjectsWithoutExpectations]
class AlvaHintServiceTest extends TestCase
{
    #[Test]
    public function returnsNullWithoutAuthenticatedUser(): void
    {
        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(null);

        $service = new AlvaHintService(
            $security,
            $this->createMock(AlvaHintDismissalRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(ModuleConfigurationService::class),
            [
                $this->buildRule('asset.foo', 3, true, [], $this->dummyHint('asset.foo')),
            ],
        );

        $this->assertNull($service->pickHintFor(new \stdClass()));
    }

    #[Test]
    public function picksLowestTierHintAmongCandidates(): void
    {
        $tier1 = $this->dummyHint('regulatory', 1, dismissible: false);
        $tier3 = $this->dummyHint('efficiency', 3);
        $service = $this->buildService(rules: [
            $this->buildRule('efficiency', 3, true, [], $tier3),
            $this->buildRule('regulatory', 1, true, [], $tier1),
        ]);

        $this->assertSame('regulatory', $service->pickHintFor(new \stdClass())?->key);
    }

    #[Test]
    public function skipsRulesWhoseRequiredModulesAreInactive(): void
    {
        $service = $this->buildService(
            activeModules: ['assets'],
            rules: [
                $this->buildRule('risks.coverage', 2, true, ['risks'], $this->dummyHint('risks.coverage', 2)),
                $this->buildRule('assets.classification', 3, true, ['assets'], $this->dummyHint('assets.classification', 3)),
            ],
        );

        $this->assertSame('assets.classification', $service->pickHintFor(new \stdClass())?->key);
    }

    #[Test]
    public function skipsDismissedHintAndFallsBackToNextCandidate(): void
    {
        $high = $this->dummyHint('asset.high', 2, entityType: 'Asset', entityId: 7);
        $low = $this->dummyHint('asset.low', 3, entityType: 'Asset', entityId: 7);

        $service = $this->buildService(
            dismissedTokens: ['asset.high@1|Asset|7'],
            rules: [
                $this->buildRule('asset.high', 2, true, [], $high),
                $this->buildRule('asset.low', 3, true, [], $low),
            ],
        );

        $this->assertSame('asset.low', $service->pickHintFor(new \stdClass())?->key);
    }

    #[Test]
    public function nonDismissibleHintsBypassDismissalCheck(): void
    {
        $hint = $this->dummyHint('incident.gdpr_72h', 1, entityType: 'Incident', entityId: 9, dismissible: false);

        $service = $this->buildService(
            dismissedTokens: ['incident.gdpr_72h@1|Incident|9'],
            rules: [
                $this->buildRule('incident.gdpr_72h', 1, true, [], $hint),
            ],
        );

        $this->assertSame('incident.gdpr_72h', $service->pickHintFor(new \stdClass())?->key);
    }

    #[Test]
    public function tier1HintMustNotBeDismissible(): void
    {
        $this->expectException(\App\Exception\InvalidArgument\InvalidArgumentException::class);
        new AlvaHint(
            key: 'incident.gdpr_72h',
            titleTranslationKey: 'tests.title',
            bodyTranslationKey: 'tests.body',
            priorityTier: 1,
            dismissible: true,
        );
    }

    #[Test]
    public function ruleIsSkippedWhenUserLacksRequiredRole(): void
    {
        $hint = $this->dummyHint(
            'mapping.import',
            3,
            requiredRoles: ['ROLE_MANAGER'],
        );

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn(new User());
        $security->method('isGranted')->willReturnMap([
            ['ROLE_MANAGER', null, false],
        ]);

        $repo = $this->createMock(AlvaHintDismissalRepository::class);
        $repo->method('findActiveDismissedTokensForUser')->willReturn([]);
        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('getActiveModules')->willReturn([]);

        $service = new AlvaHintService(
            $security,
            $repo,
            $this->createMock(EntityManagerInterface::class),
            $modules,
            [$this->buildRule('mapping.import', 3, true, [], $hint)],
        );

        $this->assertNull($service->pickHintFor(new \stdClass()));
    }

    #[Test]
    public function sameHintKeyIsEmittedOnlyOncePerRequest(): void
    {
        $hint = $this->dummyHint('asset.review', 3, entityType: 'Asset', entityId: 1);
        $service = $this->buildService(rules: [
            $this->buildRule('asset.review', 3, true, [], $hint),
        ]);

        $this->assertNotNull($service->pickHintFor(new \stdClass()));
        $this->assertNull($service->pickHintFor(new \stdClass()));
    }

    /**
     * @param list<AlvaHintRuleInterface> $rules
     * @param list<string>                $activeModules
     * @param list<string>                $dismissedTokens
     */
    private function buildService(
        array $rules = [],
        array $activeModules = ['assets', 'risks', 'controls', 'incidents'],
        array $dismissedTokens = [],
    ): AlvaHintService {
        $user = new User();
        $user->setTenant(new Tenant());

        $security = $this->createMock(Security::class);
        $security->method('getUser')->willReturn($user);
        $security->method('isGranted')->willReturn(true);

        $repo = $this->createMock(AlvaHintDismissalRepository::class);
        $repo->method('findActiveDismissedTokensForUser')->willReturn($dismissedTokens);

        $em = $this->createMock(EntityManagerInterface::class);

        $modules = $this->createMock(ModuleConfigurationService::class);
        $modules->method('getActiveModules')->willReturn($activeModules);

        return new AlvaHintService($security, $repo, $em, $modules, $rules);
    }

    /**
     * @param list<string> $requiredModules
     */
    private function buildRule(string $key, int $tier, bool $applies, array $requiredModules, AlvaHint $hint): AlvaHintRuleInterface
    {
        return new class ($key, $tier, $applies, $requiredModules, $hint) implements AlvaHintRuleInterface {
            public function __construct(
                private readonly string $ruleKey,
                private readonly int $tier,
                private readonly bool $applies,
                private readonly array $modules,
                private readonly AlvaHint $hint,
            ) {
            }

            public function key(): string { return $this->ruleKey; }
            public function priorityTier(): int { return $this->tier; }
            public function requiredModules(): array { return $this->modules; }
            public function appliesTo(object $entity, User $user): bool { return $this->applies; }
            public function build(object $entity, User $user): AlvaHint { return $this->hint; }
        };
    }

    /**
     * @param list<string> $requiredRoles
     */
    private function dummyHint(
        string $key,
        int $tier = 3,
        string $entityType = '',
        int $entityId = 0,
        bool $dismissible = true,
        array $requiredRoles = [],
    ): AlvaHint {
        return new AlvaHint(
            key: $key,
            titleTranslationKey: 'tests.title',
            bodyTranslationKey: 'tests.body',
            priorityTier: $tier,
            dismissible: $dismissible,
            entityType: $entityType,
            entityId: $entityId,
            requiredRoles: $requiredRoles,
        );
    }
}
