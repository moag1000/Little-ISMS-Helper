<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\Training;
use App\Entity\User;
use App\Security\Voter\TrainingVoter;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

final class TrainingVoterTest extends TestCase
{
    private TrainingVoter $voter;
    private Tenant $tenant;
    private Tenant $otherTenant;

    protected function setUp(): void
    {
        $this->voter = new TrainingVoter(VoterTestHelper::createRoleHierarchy());
        $this->tenant = new Tenant();
        $this->otherTenant = new Tenant();
    }

    #[Test]
    public function managerMayEditOwnTenantTraining(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(['ROLE_MANAGER'], $this->tenant, TrainingVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(['ROLE_USER'], $this->tenant, TrainingVoter::VIEW));
    }

    #[Test]
    public function foreignTenantTrainingIsDenied(): void
    {
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(['ROLE_MANAGER'], $this->otherTenant, TrainingVoter::EDIT));
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->vote(['ROLE_MANAGER'], null, TrainingVoter::EDIT));
    }

    #[Test]
    public function adminBypassesTenantCheck(): void
    {
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->vote(['ROLE_ADMIN'], $this->otherTenant, TrainingVoter::EDIT));
    }

    #[Test]
    public function abstainsOnOtherSubjectsAndAttributes(): void
    {
        $token = $this->token(['ROLE_MANAGER']);
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, new Risk(), [TrainingVoter::EDIT]));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $this->voter->vote($token, $this->training($this->tenant), ['delete']));
    }

    /** @param list<string> $roles */
    private function vote(array $roles, ?Tenant $trainingTenant, string $attribute): int
    {
        return $this->voter->vote($this->token($roles), $this->training($trainingTenant), [$attribute]);
    }

    private function training(?Tenant $tenant): Training
    {
        $training = new Training();
        $training->setTenant($tenant);

        return $training;
    }

    /** @param list<string> $roles */
    private function token(array $roles): UsernamePasswordToken
    {
        $user = (new User())->setEmail('t@x.test')->setRoles($roles)->setTenant($this->tenant);

        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }
}
