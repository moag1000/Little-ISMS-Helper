<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Contracts\Service\ResetInterface;

/**
 * S28 (F-07): the person a write is done for — the logged-in user, or, inside an
 * async admin job, the person who dispatched it ({@see \App\Service\Job\JobActor}).
 *
 * The flush guards (S20 self-assignment, S22 write rules, S28 correction reason) ask
 * this instead of the token alone: a Messenger worker has no token, so a bulk import
 * run there used to write without the rules its dispatcher is bound to. Console
 * commands and the scheduler act for nobody (null) and stay unchecked.
 */
final class ActingUser implements ResetInterface
{
    private ?User $jobUser = null;

    public function __construct(
        private readonly Security $security,
    ) {
    }

    public function current(): ?User
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user : $this->jobUser;
    }

    /**
     * Runs $fn with $user as acting person when nobody is logged in, and puts the
     * previous state back afterwards (the worker runs the next message in the same
     * container).
     *
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function runAs(?User $user, callable $fn): mixed
    {
        $previous = $this->jobUser;
        $this->jobUser = $user;
        try {
            return $fn();
        } finally {
            $this->jobUser = $previous;
        }
    }

    public function reset(): void
    {
        $this->jobUser = null;
    }
}
