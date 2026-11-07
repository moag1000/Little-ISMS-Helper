<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Voter\IsmsSteeringVoter;
use App\Tests\Stub\PersonaResolverFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Context (ISO 27001 4.1–4.3) and objectives (6.2) are maintained by the ISB
 * and the CISO — not only by the admin.
 */
final class IsmsSteeringVoterTest extends TestCase
{
    /** @return iterable<string, array{list<string>, int}> */
    public static function roles(): iterable
    {
        yield 'ISB' => [['ROLE_ISB'], VoterInterface::ACCESS_GRANTED];
        yield 'CISO' => [['ROLE_CISO'], VoterInterface::ACCESS_GRANTED];
        yield 'admin via hierarchy' => [['ROLE_ADMIN'], VoterInterface::ACCESS_GRANTED];
        yield 'manager' => [['ROLE_MANAGER'], VoterInterface::ACCESS_DENIED];
        yield 'auditor' => [['ROLE_AUDITOR'], VoterInterface::ACCESS_DENIED];
        yield 'top management reads only' => [['ROLE_TOP_MGMT'], VoterInterface::ACCESS_DENIED];
        yield 'user' => [['ROLE_USER'], VoterInterface::ACCESS_DENIED];
    }

    /** @param list<string> $roles */
    #[Test]
    #[DataProvider('roles')]
    public function grantsTheSteeringPersonas(array $roles, int $expected): void
    {
        $user = new User();
        $user->setRoles($roles);
        $voter = new IsmsSteeringVoter(PersonaResolverFactory::create());

        self::assertSame($expected, $voter->vote(new UsernamePasswordToken($user, 'main', $user->getRoles()), null, [IsmsSteeringVoter::EDIT]));
    }

    #[Test]
    public function abstainsOnOtherAttributes(): void
    {
        $voter = new IsmsSteeringVoter(PersonaResolverFactory::create());
        $user = new User();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($user, 'main', []), null, ['ROLE_ADMIN']));
    }
}
