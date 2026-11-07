<?php

declare(strict_types=1);

namespace App\EventListener;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostLoadEventArgs;
use Doctrine\ORM\Events;

/**
 * Loads the SSO provider an account is bound to without the tenant filter.
 *
 * A provider of a parent organisation is hidden by the TenantFilter of the
 * account's own (child) organisation; global providers are shared rows
 * ({@see \App\Doctrine\SharedTenantRowsInterface}) and stay visible. Hydration
 * leaves `User::$ssoProvider` as a lazy proxy; initialising it later in the
 * request (reauth modal in base.html.twig, user detail page, reauth
 * redirect) ran the filtered SELECT, found no row and ended in an
 * EntityNotFoundException — a 500 for every account that signs in through a
 * provider of its parent organisation.
 *
 * The binding is part of the account itself, so it is resolved right after
 * the user is loaded, unfiltered. One query per distinct provider: the
 * identity map shares the instance across all users bound to it.
 */
#[AsEntityListener(event: Events::postLoad, entity: User::class)]
final class UserSsoProviderLoader
{
    public function postLoad(User $user, PostLoadEventArgs $event): void
    {
        $provider = $user->getSsoProvider();
        if ($provider === null) {
            return;
        }
        $em = $event->getObjectManager();
        if (!$em->isUninitializedObject($provider)) {
            return;
        }

        $filters = $em->getFilters();
        $suspended = $filters->isEnabled('tenant_filter');
        if ($suspended) {
            $filters->suspend('tenant_filter');
        }
        try {
            $em->getUnitOfWork()->initializeObject($provider);
        } finally {
            if ($suspended) {
                $filters->restore('tenant_filter');
            }
        }
    }
}
