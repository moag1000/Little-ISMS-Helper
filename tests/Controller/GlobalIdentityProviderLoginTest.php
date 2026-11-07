<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\IdentityProvider;
use App\Entity\Tenant;
use App\Entity\User;
use App\Service\ModuleConfigurationService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression for #1086 on the real failure path: a user of an organisation who
 * signed in through a global identity provider (tenant = NULL). With the tenant
 * filter armed, base.html.twig lazy-loads `app.user.ssoProvider`; before the fix
 * the filter hid the provider and every page answered 500 (EntityNotFoundException).
 */
final class GlobalIdentityProviderLoginTest extends WebTestCase
{
    #[Test]
    public function aTenantUserBoundToAGlobalProviderCanOpenPages(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $modules = static::getContainer()->get(ModuleConfigurationService::class);
        $uid = substr(uniqid('', true), -8);

        $tenant = (new Tenant())->setName('Sso ' . $uid)->setCode('sso_' . $uid);
        $tenant->setActiveModules($modules->getInstanceDefaultModules());
        $em->persist($tenant);

        $provider = (new IdentityProvider())
            ->setSlug('global-' . $uid)
            ->setName('Global ' . $uid)
            ->setClientId('id-' . $uid)
            ->setEnabled(true)
            ->setTenant(null);
        $em->persist($provider);

        $user = new User();
        $user->setEmail('sso_' . $uid . '@example.com');
        $user->setFirstName('Sso');
        $user->setLastName('User');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('hashed_password');
        $user->setTenant($tenant);
        $user->setIsActive(true);
        $user->setSsoProvider($provider);
        $em->persist($user);
        $em->flush();
        $ids = [$user->getId(), $provider->getId(), $tenant->getId()];

        // Drop the managed instances so the request loads the provider as a lazy proxy.
        $em->clear();
        $client->loginUser($em->find(User::class, $ids[0]));
        $em->clear();

        try {
            $client->request('GET', '/de/dashboard');
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('global-' . $uid, (string) $client->getResponse()->getContent());
        } finally {
            $connection = $em->getConnection();
            foreach ([
                'DELETE FROM users WHERE id = ?' => $ids[0],
                'DELETE FROM identity_provider WHERE id = ?' => $ids[1],
                'DELETE FROM tenant WHERE id = ?' => $ids[2],
            ] as $sql => $id) {
                try {
                    $connection->executeStatement($sql, [$id]);
                } catch (\Throwable) {
                    // best effort: audit rows written during the request may block
                }
            }
        }
    }
}
