<?php

namespace App\Security\Voter;

use App\Entity\Tenant;
use App\Entity\Document;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Document Voter
 *
 * Implements fine-grained authorization for Document entity operations.
 * Enforces ownership-based access control with multi-tenancy support.
 *
 * Supported Operations:
 * - VIEW: View document metadata and content
 * - DOWNLOAD: Download document files
 * - EDIT: Modify document metadata (owner only)
 * - DELETE: Remove documents (admin only)
 *
 * Security Rules:
 * - ROLE_ADMIN bypasses all checks
 * - Users can view/download their own documents
 * - Users can view/download documents from their tenant
 * - Only the uploader can edit document metadata
 * - Only admins can delete documents
 *
 * Multi-tenancy:
 * - Implements OWASP A1: Broken Access Control prevention
 * - Dual-layer isolation: ownership + tenant validation
 * - Prevents horizontal privilege escalation within tenant
 * - Prevents cross-tenant document access
 *
 * Document Security:
 * - Ownership tracking through uploadedBy relationship
 * - Tenant-based sharing within organization
 * - Future: Can be extended with document classification and access levels
 */
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

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
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
            self::DELETE => $this->canDelete($user),
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
        return $document->getUploadedBy()?->getTenant() === $user->getTenant() && $user->getTenant() instanceof Tenant;
    }

    private function canEdit(Document $document, User $user): bool
    {
        // Security: Only the uploader or admin can edit
        return $document->getUploadedBy() === $user;
    }

    private function canDelete(User $user): bool
    {
        // Security: Only admins can delete (enforced by IsGranted in controller)
        return in_array('ROLE_ADMIN', $user->getRoles());
    }
}
