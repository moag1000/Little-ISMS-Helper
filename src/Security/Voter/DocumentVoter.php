<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\Document;
use App\Entity\User;
use App\Security\TenantOwnership;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

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
 * - ROLE_SUPER_ADMIN bypasses all checks; ROLE_ADMIN only within its own tenant
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
final class DocumentVoter extends Voter
{
    use HoldingTreeAccessTrait;

    public function __construct(
        private readonly RoleHierarchyInterface $roleHierarchy,
    ) {
    }

    protected function getRoleHierarchy(): RoleHierarchyInterface
    {
        return $this->roleHierarchy;
    }

    public const string VIEW = 'view';
    public const string EDIT = 'edit';
    public const string DELETE = 'delete';
    public const string DOWNLOAD = 'download';

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

        $docTenant = $document->getTenant() ?? $document->getUploadedBy()?->getTenant();
        $isRead = in_array($attribute, [self::VIEW, self::DOWNLOAD], true);

        // Inheritable holding policy: a subsidiary user may
        // read a document marked inheritable=true on any ancestor tenant.
        if ($isRead && $document->isInheritable() && TenantOwnership::isDescendant($docTenant, $user->getTenant())) {
            return true;
        }

        // Tenant scope first: SUPER_ADMIN everywhere, ROLE_ADMIN only inside
        // its own tenant, holding roles read-only down the tree.
        $scope = $this->decideTenantScope($user, $docTenant, $isRead);
        if ($scope !== null) {
            return $scope;
        }

        // In scope below ROLE_ADMIN: read; edit only by the uploader; delete
        // stays admin-only.
        return match ($attribute) {
            self::VIEW, self::DOWNLOAD => true,
            self::EDIT => $document->getUploadedBy() === $user,
            default => false,
        };
    }
}
