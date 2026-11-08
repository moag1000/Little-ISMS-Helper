<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\ISMSObjective;
use App\Entity\Person;
use App\Entity\Tenant;
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
    public function theMeasuringPersonMayRecordAMeasurement(): void
    {
        $voter = new IsmsSteeringVoter(PersonaResolverFactory::create());
        $measurer = (new User())->setRoles(['ROLE_USER']);
        $objective = (new ISMSObjective())->setMeasurementPerson((new Person())->setLinkedUser($measurer));
        $token = new UsernamePasswordToken($measurer, 'main', $measurer->getRoles());

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($token, $objective, [IsmsSteeringVoter::MEASURE]));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($token, null, [IsmsSteeringVoter::EDIT]), 'measuring is not steering');

        $other = (new User())->setRoles(['ROLE_USER']);
        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote(new UsernamePasswordToken($other, 'main', $other->getRoles()), $objective, [IsmsSteeringVoter::MEASURE]),
        );
    }

    #[Test]
    public function theOwnerOfAnotherOrganisationMayNotMeasure(): void
    {
        $voter = new IsmsSteeringVoter(PersonaResolverFactory::create());
        $owner = (new User())->setRoles(['ROLE_USER']);
        $tenant = $this->createStub(Tenant::class);
        $tenant->method('getId')->willReturn(7);
        $objective = (new ISMSObjective())->setResponsiblePersonUser($owner)->setTenant($tenant);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote(new UsernamePasswordToken($owner, 'main', $owner->getRoles()), $objective, [IsmsSteeringVoter::MEASURE]),
        );
    }

    #[Test]
    public function abstainsOnOtherAttributes(): void
    {
        $voter = new IsmsSteeringVoter(PersonaResolverFactory::create());
        $user = new User();

        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote(new UsernamePasswordToken($user, 'main', []), null, ['ROLE_ADMIN']));
    }
}
