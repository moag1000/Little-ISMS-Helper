<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Smoke tests for the lifecycle history endpoint (X.3).
 */
final class LifecycleControllerHistoryTest extends WebTestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function historyRouteIsRegistered(): void
    {
        $client = static::createClient();
        $router = static::getContainer()->get('router');

        $this->assertNotNull(
            $router->getRouteCollection()->get('app_lifecycle_history'),
            'Route app_lifecycle_history must be registered.'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function historyRouteReturns403ForUnauthenticated(): void
    {
        $client = static::createClient();
        $client->request('GET', '/lifecycle/document/1/history');
        // Firewall redirects to login (302) or returns 403 — both indicate auth required.
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 403],
            'Unauthenticated access to history endpoint must be refused (302 or 403).'
        );
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function historyRouteFor404UnknownType(): void
    {
        $client = static::createClient();
        // Use unknown entity type — expects 302 (login redirect), 403, or 404.
        $client->request('GET', '/lifecycle/nonexistent-type/1/history');
        $this->assertContains(
            $client->getResponse()->getStatusCode(),
            [302, 403, 404],
        );
    }

    /**
     * The tenant filter exempts the Tenant entity, so the history endpoint
     * must check the owning organisation itself: another organisation's
     * history answers like a missing record.
     */
    #[\PHPUnit\Framework\Attributes\Test]
    public function historyOfAnotherOrganisationIsNotFound(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = bin2hex(random_bytes(4));

        $own = (new Tenant())->setName('Own ' . $uid)->setCode('own_' . $uid);
        $other = (new Tenant())->setName('Other ' . $uid)->setCode('other_' . $uid);
        $user = new User();
        $user->setEmail('history_' . $uid . '@example.com');
        $user->setFirstName('His');
        $user->setLastName('Tory');
        $user->setRoles(['ROLE_USER']);
        $user->setPassword('x');
        $user->setIsActive(true);
        $user->setTenant($own);
        foreach ([$own, $other, $user] as $row) {
            $em->persist($row);
        }
        $em->flush();

        try {
            $client->loginUser($user);

            $client->request('GET', '/lifecycle/tenant/' . $other->getId() . '/history');
            self::assertResponseStatusCodeSame(404);

            $client->request('GET', '/lifecycle/tenant/' . $own->getId() . '/history');
            self::assertResponseIsSuccessful();

            // W3: the allowed-transitions probe (status + lock version) has the same scope.
            $client->request('GET', '/lifecycle/tenant/' . $other->getId() . '/allowed-transitions');
            self::assertResponseStatusCodeSame(404);
        } finally {
            foreach ([$user, $other, $own] as $row) {
                $managed = $em->find($row::class, $row->getId());
                if ($managed !== null) {
                    $em->remove($managed);
                }
            }
            $em->flush();
        }
    }
}
