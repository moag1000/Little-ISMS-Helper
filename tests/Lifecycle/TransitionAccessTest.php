<?php

declare(strict_types=1);

namespace App\Tests\Lifecycle;

use App\Entity\User;
use App\Lifecycle\Config\LifecycleConfigResolverInterface;
use App\Lifecycle\Exception\TransitionAccessDeniedException;
use App\Lifecycle\TransitionAccess;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * S21: who TransitionAccess checks, and when it does not check at all.
 * The full matrix over every workflow is LifecycleServiceRoleContractTest.
 */
final class TransitionAccessTest extends TestCase
{
    #[Test]
    public function theFourEyesApproverIsTheActingPerson(): void
    {
        $initiator = $this->user('initiator');
        $approver = $this->user('approver');
        $access = $this->access(['ROLE_CISO'], allowed: [$approver]);

        $access->assertAllowed(new \stdClass(), 'wf', 'approve', $initiator, $approver, false, false);
        $this->expectException(TransitionAccessDeniedException::class);
        $this->access(['ROLE_CISO'], allowed: [$initiator])
            ->assertAllowed(new \stdClass(), 'wf', 'approve', $initiator, $approver, false, false);
    }

    #[Test]
    public function withoutAPassedUserTheLoggedInUserIsChecked(): void
    {
        $loggedIn = $this->user('logged-in');
        $this->expectException(TransitionAccessDeniedException::class);
        $this->access(['ROLE_DPO'], allowed: [], loggedIn: $loggedIn)
            ->assertAllowed(new \stdClass(), 'wf', 'complete', null, null, false, false);
    }

    #[Test]
    public function automaticVerifiedAndServiceOnlyStepsAreNotChecked(): void
    {
        $user = $this->user('nobody');
        $this->access(['ROLE_DPO'], allowed: [])->assertAllowed(new \stdClass(), 'wf', 't', $user, null, false, true);
        $this->access(['ROLE_DPO'], allowed: [])->assertAllowed(new \stdClass(), 'wf', 't', $user, null, true, false);
        $this->access([], allowed: [])->assertAllowed(new \stdClass(), 'wf', 't', $user, null, false, false);
        $this->access(['ROLE_DPO'], allowed: [])->assertAllowed(new \stdClass(), 'wf', 't', null, null, false, false);
        $this->addToAssertionCount(4);
    }

    #[Test]
    public function aResponsibleOnlyStepIsChecked(): void
    {
        $this->expectException(TransitionAccessDeniedException::class);
        $this->access([], allowed: [], responsible: true)
            ->assertAllowed(new \stdClass(), 'wf', 't', $this->user('x'), null, false, false);
    }

    /**
     * @param list<string> $roles
     * @param list<User>   $allowed users the voter grants
     */
    private function access(array $roles, array $allowed, ?User $loggedIn = null, bool $responsible = false): TransitionAccess
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($loggedIn);
        $security->method('isGrantedForUser')->willReturnCallback(
            static fn (User $user, string $attribute) => str_starts_with($attribute, 'lifecycle.wf.')
                && in_array($user, $allowed, true),
        );
        $resolver = $this->createStub(LifecycleConfigResolverInterface::class);
        $resolver->method('resolve')->willReturn(['roles' => $roles] + ($responsible ? ['responsible' => true] : []));

        return new TransitionAccess($security, $resolver);
    }

    private function user(string $name): User
    {
        return (new User())->setEmail($name . '@example.test');
    }
}
