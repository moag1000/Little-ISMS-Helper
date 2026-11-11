<?php

declare(strict_types=1);

namespace App\Service\Job;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\ActingUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * S28 (F-07): a job writes for the person who started it. At dispatch their
 * account id goes into the job arguments as `actingUserId` (a job may name its
 * own); both runners execute the job under {@see ActingUser::runAs()}, so the
 * flush guards check that person's rights also in a Messenger worker, where no
 * token exists. Same pattern as {@see JobLocale}.
 */
class JobActor
{
    public const string ARG = 'actingUserId';

    public function __construct(
        private readonly Security $security,
        private readonly UserRepository $users,
        private readonly ActingUser $actingUser,
    ) {
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function stamp(array $args): array
    {
        if (array_key_exists(self::ARG, $args)) {
            return $args;
        }
        $user = $this->security->getUser();
        if ($user instanceof User && $user->getId() !== null) {
            $args[self::ARG] = $user->getId();
        }

        return $args;
    }

    /**
     * @template T
     * @param array<string, mixed> $args
     * @param callable(): T $job
     * @return T
     */
    public function run(array $args, callable $job): mixed
    {
        $id = $args[self::ARG] ?? null;
        $user = is_int($id) || (is_string($id) && ctype_digit($id)) ? $this->users->find((int) $id) : null;

        return $this->actingUser->runAs($user instanceof User ? $user : null, $job);
    }
}
