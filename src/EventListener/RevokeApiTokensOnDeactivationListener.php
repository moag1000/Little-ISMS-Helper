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
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class RevokeApiTokensOnDeactivationListener
{
    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $tokenMeta = null;

        foreach ($uow->getScheduledEntityUpdates() as $entity) {
            if (!$entity instanceof User || $entity->getId() === null) {
                continue;
            }
            $change = $uow->getEntityChangeSet($entity)['isActive'] ?? null;
            if ($change === null || $change[1] !== false) {
                continue;
            }

            $tokens = $em->getRepository(ApiToken::class)->findBy(['user' => $entity, 'revoked' => false]);
            foreach ($tokens as $token) {
                $token->setRevoked(true);
                $tokenMeta ??= $em->getClassMetadata(ApiToken::class);
                $uow->recomputeSingleEntityChangeSet($tokenMeta, $token);
            }
        }
    }
}
