<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\DocumentVersion;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * F4 Evidence-Versioning — DocumentVersion voter.
 *
 * Immutability contract:
 *  - VIEW: any authenticated user of the same tenant may view versions.
 *  - DOWNLOAD: same as VIEW.
 *  - DELETE: ALWAYS denied (versions are immutable evidence records).
 *
 * SUPER_ADMIN force-delete (exceptional data-correction) must bypass the
 * voter via a CLI command, never through the HTTP layer.
 */
final class DocumentVersionVoter extends Voter
{
    public const string VIEW = 'view';
    public const string DOWNLOAD = 'download';
    public const string DELETE = 'delete';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::DOWNLOAD, self::DELETE])
            && $subject instanceof DocumentVersion;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        /** @var DocumentVersion $version */
        $version = $subject;

        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        // DELETE is categorically denied — DocumentVersion is immutable evidence.
        if ($attribute === self::DELETE) {
            return false;
        }

        // VIEW / DOWNLOAD: same-tenant check
        $versionTenant = $version->getTenant();
        $userTenant = $user->getTenant();

        if ($versionTenant === null || $userTenant === null) {
            return false;
        }

        return $versionTenant->getId() === $userTenant->getId();
    }
}
