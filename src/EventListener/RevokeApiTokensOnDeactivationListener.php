<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\ApiToken;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Deactivating a user revokes all of the user's API tokens, in the same flush,
 * on every path that deactivates (user list toggle, user form, import, SSO
 * sync). The {@see \App\Security\UserChecker} already refuses a deactivated
 * user's token; revoking makes it stay dead when the account is switched back
 * on — the person then creates a new token (S1, wave 5).
 *
 * A role change (stored roles or custom roles, on every path: user form, bulk
 * role assignment, SSO login) revokes them too (S14): a token created for a
 * reading purpose must not silently gain the new rights. The browser session
 * re-authenticates on its own (Symfony compares the roles on refresh).
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class RevokeApiTokensOnDeactivationListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();

        /** @var array<int, User> $affected */
        $affected = [];
        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof User || $entity->getId() === null) {
                continue;
            }
            $changeSet = $uow->getEntityChangeSet($entity);
            $deactivated = ($changeSet['isActive'][1] ?? null) === false;
            if ($deactivated || self::rolesChanged($changeSet['roles'] ?? null)) {
                $affected[$entity->getId()] = $entity;
            }
        }
        foreach ([...$uow->getScheduledCollectionUpdates(), ...$uow->getScheduledCollectionDeletions()] as $collection) {
            $owner = $collection->getOwner();
            if ($owner instanceof User && $owner->getId() !== null
                && $collection->getMapping()->fieldName === 'customRoles') {
                $affected[$owner->getId()] = $owner;
            }
        }

        $tokenMeta = null;
        foreach ($affected as $user) {
            $tokens = $em->getRepository(ApiToken::class)->findBy(['user' => $user, 'revoked' => false]);
            foreach ($tokens as $token) {
                $token->setRevoked(true);
                $tokenMeta ??= $em->getClassMetadata(ApiToken::class);
                $uow->recomputeSingleEntityChangeSet($tokenMeta, $token);
            }
        }
    }

    /**
     * @param array{0: mixed, 1: mixed}|null $change
     */
    private static function rolesChanged(?array $change): bool
    {
        if ($change === null) {
            return false;
        }
        $old = is_array($change[0]) ? $change[0] : [];
        $new = is_array($change[1]) ? $change[1] : [];
        sort($old);
        sort($new);

        return $old !== $new;
    }
}
