<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\Asset;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\UnitOfWork;
use Psr\Log\LoggerInterface;

/**
 * V3 W2-LB-6 — Asset-Owner Auto-Sync.
 *
 * When a User entity changes its display name (firstName / lastName)
 * the legacy `Asset.owner` (free-text string) on every Asset that is
 * structurally tied to this User via `Asset.ownerUser` becomes stale.
 *
 * The Asset ownership chain is dual-state (Pattern A):
 *   1. ownerUser (preferred, ManyToOne User)
 *   2. ownerPerson (Person, also ManyToOne)
 *   3. owner (legacy free-text string)
 *
 * Without this sync the structured-then-string fallback in
 * AssetNormalizer / OwnerResolver still resolves correctly, but the
 * raw column value drifts — exports / CSV reports / list views reading
 * `owner` directly would surface the stale name.
 *
 * Triggers only on User postUpdate. Persist of a fresh User cannot
 * yet have linked assets; user removal would orphan via SET NULL on
 * the FK so no string-sync is needed.
 *
 * Operates strictly within the User's tenant. Cross-tenant assets
 * cannot reference this user (FK guard).
 */
#[AsEntityListener(event: Events::postUpdate, entity: User::class)]
final class AssetOwnerSyncListener
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    public function postUpdate(User $user, PostUpdateEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        if (!$uow instanceof UnitOfWork) {
            return;
        }
        $changeSet = $uow->getEntityChangeSet($user);
        $nameChanged = isset($changeSet['firstName']) || isset($changeSet['lastName']);
        if (!$nameChanged) {
            return;
        }

        $newDisplayName = trim((string) $user->getFullName());
        if ($newDisplayName === '') {
            return;
        }

        try {
            // Find all Assets where ownerUser = $user, scoped to user's tenant.
            $assets = $em->getRepository(Asset::class)->findBy([
                'ownerUser' => $user,
            ]);
            if (empty($assets)) {
                return;
            }

            $updated = 0;
            foreach ($assets as $asset) {
                /** @var Asset $asset */
                $current = $asset->getOwner();
                if ($current === $newDisplayName) {
                    continue;
                }
                $asset->setOwner($newDisplayName);
                $em->persist($asset);
                $updated++;
            }
            if ($updated > 0) {
                $em->flush();
                $this->logger->info('Asset owner-string synced after User name-change', [
                    'user_id' => $user->getId(),
                    'asset_count' => $updated,
                    'new_display_name' => $newDisplayName,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Asset owner-string sync failed', [
                'user_id' => $user->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
