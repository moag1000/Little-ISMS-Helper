<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\User;
use App\Service\Job\JobStatusService;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Who may poll an async job's status or download its result file.
 *
 * The job UUID used to be the only protection: anyone holding (or being
 * handed) a status / download link could fetch another user's — or another
 * organisation's — export. {@see JobStatusService::create()} now records the
 * dispatching user and tenant; this voter grants access to
 *
 *  - the owner, as long as they still belong to the tenant the job was
 *    created in, and
 *  - a SUPER_ADMIN (instance operator).
 *
 * Jobs without an owner (created outside an authenticated request, e.g. the
 * QuickFix emergency UI) are only visible to a SUPER_ADMIN here; QuickFix has
 * its own guarded status endpoint. Unknown or malformed ids are denied.
 *
 * Subject: the job id (string), e.g. `#[IsGranted(AsyncJobVoter::ACCESS, subject: 'id')]`.
 *
 * @extends Voter<string, string>
 */
final class AsyncJobVoter extends Voter
{
    public const string ACCESS = 'ASYNC_JOB_ACCESS';

    private const string UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly JobStatusService $jobStatusService,
        private readonly Security $security,
    ) {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ACCESS && is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User || preg_match(self::UUID_V4, $subject) !== 1) {
            return false;
        }
        if (!$this->jobStatusService->exists($subject)) {
            return false;
        }

        if ($this->security->isGranted('ROLE_SUPER_ADMIN')) {
            return true;
        }

        $record = $this->jobStatusService->read($subject);
        $ownerUserId = $record['owner_user_id'] ?? null;
        if (!is_int($ownerUserId) || $ownerUserId !== $user->getId()) {
            return false;
        }

        $ownerTenantId = $record['owner_tenant_id'] ?? null;

        return $ownerTenantId === null || $ownerTenantId === $user->getTenant()?->getId();
    }
}
