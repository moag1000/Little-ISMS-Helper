<?php

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

class DocumentVoter extends Voter
{
    public const VIEW = 'view';
    public const EDIT = 'edit';
    public const DELETE = 'delete';
    public const DOWNLOAD = 'download';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return in_array($attribute, [self::VIEW, self::EDIT, self::DELETE, self::DOWNLOAD])
            && $subject instanceof Document;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        // Security: User must be authenticated
        if (!$user instanceof User) {
            return false;
        }

        /** @var Document $document */
        $document = $subject;

        // Security: Admins can do everything
        if (in_array('ROLE_ADMIN', $user->getRoles())) {
            return true;
        }

        return match ($attribute) {
            self::VIEW, self::DOWNLOAD => $this->canView($document, $user),
            self::EDIT => $this->canEdit($document, $user),
            self::DELETE => $this->canDelete($document, $user),
            default => false,
        };
    }

    private function canView(Document $document, User $user): bool
    {
        // Security: Users can view documents they uploaded
        if ($document->getUploadedBy() === $user) {
            return true;
        }

        // Security: Multi-tenancy - users can view documents from their tenant
        if ($document->getUploadedBy()?->getTenant() === $user->getTenant() && $user->getTenant() !== null) {
            return true;
        }

        return false;
    }

    private function canEdit(Document $document, User $user): bool
    {
        // Security: Only the uploader or admin can edit
        return $document->getUploadedBy() === $user;
    }

    private function canDelete(Document $document, User $user): bool
    {
        // Security: Only admins can delete (enforced by IsGranted in controller)
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
