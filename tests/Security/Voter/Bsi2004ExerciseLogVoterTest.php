<?php

declare(strict_types=1);

namespace App\Tests\Security\Voter;

use App\Entity\Bsi2004ExerciseLog;
use App\Entity\Tenant;
use App\Entity\User;
use App\Security\Voter\Bsi2004ExerciseLogVoter;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

#[AllowMockObjectsWithoutExpectations]
class Bsi2004ExerciseLogVoterTest extends TestCase
{
    private Bsi2004ExerciseLogVoter $voter;

    protected function setUp(): void
    {
        $this->voter = new Bsi2004ExerciseLogVoter();
    }

    private function createToken(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    private function createUser(Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->setTenant($tenant);
        $user->setEmail('test@test.com');
        $user->setPassword('x');
        $user->setRoles($roles);
        return $user;
    }

    private function createLog(Tenant $tenant, bool $submitted = false): Bsi2004ExerciseLog
    {
        $log = new Bsi2004ExerciseLog();
        $log->setTenant($tenant);
        if ($submitted) {
            $log->setSubmittedAt(new DateTimeImmutable());
        }
        return $log;
    }

    #[Test]
    public function managerCanViewDraftLog(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_MANAGER']);
        $log    = $this->createLog($tenant);

        $result = $this->voter->vote($this->createToken($user), $log, [Bsi2004ExerciseLogVoter::VIEW]);
        self::assertSame(1, $result);
    }

    #[Test]
    public function managerCanEditDraftLog(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_MANAGER']);
        $log    = $this->createLog($tenant, false);

        $result = $this->voter->vote($this->createToken($user), $log, [Bsi2004ExerciseLogVoter::EDIT]);
        self::assertSame(1, $result);
    }

    #[Test]
    public function managerCannotEditSubmittedLog(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_MANAGER']);
        $log    = $this->createLog($tenant, true);

        $result = $this->voter->vote($this->createToken($user), $log, [Bsi2004ExerciseLogVoter::EDIT]);
        self::assertSame(-1, $result);
    }

    #[Test]
    public function auditorCanConfirm(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_AUDITOR']);
        $log    = $this->createLog($tenant, true);

        $result = $this->voter->vote($this->createToken($user), $log, [Bsi2004ExerciseLogVoter::CONFIRM]);
        self::assertSame(1, $result);
    }

    #[Test]
    public function managerCannotConfirm(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_MANAGER']);
        $log    = $this->createLog($tenant, true);

        $result = $this->voter->vote($this->createToken($user), $log, [Bsi2004ExerciseLogVoter::CONFIRM]);
        self::assertSame(-1, $result);
    }

    #[Test]
    public function adminCanDoEverything(): void
    {
        $tenant = new Tenant();
        $user   = $this->createUser($tenant, ['ROLE_ADMIN']);
        $log    = $this->createLog($tenant, true);

        foreach ([Bsi2004ExerciseLogVoter::VIEW, Bsi2004ExerciseLogVoter::EDIT, Bsi2004ExerciseLogVoter::CONFIRM, Bsi2004ExerciseLogVoter::DELETE] as $attribute) {
            $result = $this->voter->vote($this->createToken($user), $log, [$attribute]);
            self::assertSame(1, $result, "Admin should be granted: $attribute");
        }
    }

    #[Test]
    public function crossTenantAccessDenied(): void
    {
        $tenant1 = new Tenant();
        $tenant2 = new Tenant();
        $user    = $this->createUser($tenant1, ['ROLE_MANAGER']);
        $log     = $this->createLog($tenant2);

        $result = $this->voter->vote($this->createToken($user), $log, [Bsi2004ExerciseLogVoter::VIEW]);
        self::assertSame(-1, $result);
    }

    #[Test]
    public function abstainForUnsupportedSubjects(): void
    {
        $result = $this->voter->vote(
            $this->createToken($this->createUser(new Tenant(), ['ROLE_MANAGER'])),
            new \stdClass(),
            [Bsi2004ExerciseLogVoter::VIEW]
        );
        self::assertSame(0, $result); // ABSTAIN
    }
}
