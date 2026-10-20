<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\EntityVoter;
use App\Security\Voter\RiskVoter;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\Strategy\AffirmativeStrategy;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * EntityVoter is a permission-based fallback that answers the same
 * attribute strings (view/edit/delete/…) as the dedicated voters. Under
 * the default "affirmative" strategy its grant used to override a dedicated
 * voter's tenant-isolation deny.
 */
#[AllowMockObjectsWithoutExpectations]
final class EntityVoterDedicatedMapTest extends TestCase
{
    private const array ATTRIBUTES = [
        EntityVoter::VIEW,
        EntityVoter::CREATE,
        EntityVoter::EDIT,
        EntityVoter::DELETE,
        EntityVoter::APPROVE,
        EntityVoter::EXPORT,
    ];

    #[Test]
    public function entityVoterDoesNotOverrideDedicatedVoterDeny(): void
    {
        $hierarchy = VoterTestHelper::createRoleHierarchy();
        $decisionManager = new AccessDecisionManager(
            [new RiskVoter($hierarchy), new EntityVoter($hierarchy)],
            new AffirmativeStrategy(),
        );

        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('isActive')->willReturn(true);
        $user->method('getTenant')->willReturn(new Tenant());
        $user->method('hasPermission')->willReturn(true); // holds risk.view / risk.edit
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);

        $foreignRisk = $this->createMock(Risk::class);
        $foreignRisk->method('getTenant')->willReturn(new Tenant());

        self::assertFalse($decisionManager->decide($token, [RiskVoter::VIEW], $foreignRisk));
        self::assertFalse($decisionManager->decide($token, [RiskVoter::EDIT], $foreignRisk));
    }

    #[Test]
    public function permissionGrantStopsAtTheTenantBoundary(): void
    {
        $voter = new EntityVoter(VoterTestHelper::createRoleHierarchy());
        $user = $this->createMock(User::class);
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('isActive')->willReturn(true);
        $user->method('getTenant')->willReturn(new Tenant());
        $user->method('hasPermission')->willReturn(true);
        $token = new UsernamePasswordToken($user, 'main', ['ROLE_USER']);

        $foreignSupplier = $this->createMock(Supplier::class);
        $foreignSupplier->method('getTenant')->willReturn(new Tenant());
        $ownSupplier = $this->createMock(Supplier::class);
        $ownSupplier->method('getTenant')->willReturn($user->getTenant());

        self::assertSame(Voter::ACCESS_DENIED, $voter->vote($token, $foreignSupplier, [EntityVoter::EDIT]));
        self::assertSame(Voter::ACCESS_GRANTED, $voter->vote($token, $ownSupplier, [EntityVoter::EDIT]));
    }

    /**
     * EntityVoter::DEDICATED must list exactly the (entity, attribute) pairs
     * another voter supports: a missing pair lets the fallback override the
     * dedicated voter again, a surplus pair leaves nobody voting (= deny).
     */
    #[Test]
    public function dedicatedMapMatchesTheOtherVotersSupports(): void
    {
        $voters = [];
        foreach ($this->classesIn('Security/Voter') as $class) {
            $reflection = new ReflectionClass($class);
            if ($class === EntityVoter::class || $reflection->isAbstract() || !$reflection->isSubclassOf(Voter::class)) {
                continue;
            }
            $voters[$class] = $reflection->newInstanceWithoutConstructor();
        }

        $expected = [];
        foreach ($this->classesIn('Entity') as $entityClass) {
            $reflection = new ReflectionClass($entityClass);
            if (!$reflection->isInstantiable()) {
                continue;
            }
            $entity = $reflection->newInstanceWithoutConstructor();
            foreach (self::ATTRIBUTES as $attribute) {
                foreach ($voters as $voter) {
                    if (new ReflectionMethod($voter, 'supports')->invoke($voter, $attribute, $entity)) {
                        $expected[$entityClass][] = $attribute;
                        break;
                    }
                }
            }
        }

        $actual = EntityVoter::DEDICATED;
        ksort($expected);
        ksort($actual);
        self::assertEquals($expected, $actual);
    }

    /**
     * @return list<class-string>
     */
    private function classesIn(string $relativeDir): array
    {
        $base = dirname(__DIR__, 3) . '/src/';
        $classes = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base . $relativeDir));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $class = 'App\\' . str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen($base)));
            if (class_exists($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }
}
