<?php

declare(strict_types=1);

namespace App\Security\Voter;

use App\Entity\BulkImportBatch;
use App\Entity\Tenant;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * BulkImportVoter
 *
 * Fine-grained authorization for BulkImportBatch entity operations and the
 * list-view trigger CTA (not bound to a specific batch).
 *
 * Supported attributes on BulkImportBatch subject:
 *   VIEW   — batch owner OR any ROLE_MANAGER+ within same tenant
 *   EDIT   — ROLE_MANAGER+ same tenant, only when status ∈ {uploaded, mapped, preview}
 *   COMMIT — ROLE_MANAGER+ same tenant, only when status = preview
 *   CANCEL — ROLE_MANAGER+ same tenant, only when status ∈ {uploaded, mapped, preview}
 *   DELETE — ROLE_ADMIN same tenant, any status
 *
 * Null-subject attribute (list-view CTA):
 *   BULK_IMPORT_TRIGGER — ROLE_MANAGER+ for the current tenant (subject = null)
 *
 * Multi-tenancy: strict tenant equality check for all subject-bound attributes.
 */
class BulkImportVoter extends Voter
{
    public const string VIEW                = 'BULK_IMPORT_VIEW';
    public const string EDIT                = 'BULK_IMPORT_EDIT';
    public const string COMMIT              = 'BULK_IMPORT_COMMIT';
    public const string CANCEL              = 'BULK_IMPORT_CANCEL';
    public const string DELETE              = 'BULK_IMPORT_DELETE';
    public const string BULK_IMPORT_TRIGGER = 'BULK_IMPORT_TRIGGER';

    /** Statuses that are still in the "preparation" phase (pre-commit). */
    private const PREP_STATUSES = [
        BulkImportBatch::STATUS_UPLOADED,
        BulkImportBatch::STATUS_MAPPED,
        BulkImportBatch::STATUS_PREVIEW,
    ];

    protected function supports(string $attribute, mixed $subject): bool
    {
        // Trigger CTA: no subject required
        if ($attribute === self::BULK_IMPORT_TRIGGER && $subject === null) {
            return true;
        }

        return in_array($attribute, [
            self::VIEW,
            self::EDIT,
            self::COMMIT,
            self::CANCEL,
            self::DELETE,
        ], true) && $subject instanceof BulkImportBatch;
    }

    protected function voteOnAttribute(
        string $attribute,
        mixed $subject,
        TokenInterface $token,
        ?Vote $vote = null,
    ): bool {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        // Null-subject trigger CTA: just needs ROLE_MANAGER
        if ($attribute === self::BULK_IMPORT_TRIGGER) {
            return $this->hasRole($user, 'ROLE_MANAGER');
        }

        /** @var BulkImportBatch $batch */
        $batch = $subject;

        // ROLE_SUPER_ADMIN bypass (implicit via hierarchy, but guard explicitly)
        if ($this->hasRole($user, 'ROLE_SUPER_ADMIN')) {
            return true;
        }

        // Strict tenant check for all remaining attributes
        if (!$this->isSameTenant($batch, $user)) {
            return false;
        }

        return match ($attribute) {
            self::VIEW   => $this->canView($batch, $user),
            self::EDIT   => $this->canEdit($batch, $user),
            self::COMMIT => $this->canCommit($batch, $user),
            self::CANCEL => $this->canCancel($batch, $user),
            self::DELETE => $this->canDelete($user),
            default      => false,
        };
    }

    // ------------------------------------------------------------------
    // Per-attribute decisions
    // ------------------------------------------------------------------

    private function canView(BulkImportBatch $batch, User $user): bool
    {
        // Batch owner always sees their own batch
        if ($batch->getExecutedBy() !== null && $batch->getExecutedBy() === $user) {
            return true;
        }

        // Any ROLE_MANAGER+ within the same tenant
        return $this->hasRole($user, 'ROLE_MANAGER');
    }

    private function canEdit(BulkImportBatch $batch, User $user): bool
    {
        return $this->hasRole($user, 'ROLE_MANAGER')
            && in_array($batch->getStatus(), self::PREP_STATUSES, true);
    }

    private function canCommit(BulkImportBatch $batch, User $user): bool
    {
        return $this->hasRole($user, 'ROLE_MANAGER')
            && $batch->getStatus() === BulkImportBatch::STATUS_PREVIEW;
    }

    private function canCancel(BulkImportBatch $batch, User $user): bool
    {
        return $this->hasRole($user, 'ROLE_MANAGER')
            && in_array($batch->getStatus(), self::PREP_STATUSES, true);
    }

    private function canDelete(User $user): bool
    {
        return $this->hasRole($user, 'ROLE_ADMIN');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function isSameTenant(BulkImportBatch $batch, User $user): bool
    {
        $batchTenant = $batch->getTenant();
        $userTenant  = $user->getTenant();

        return $batchTenant instanceof Tenant
            && $userTenant instanceof Tenant
            && $batchTenant === $userTenant;
    }

    private function hasRole(User $user, string $role): bool
    {
        return in_array($role, $user->getRoles(), true);
    }
}
