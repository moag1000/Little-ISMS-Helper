<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use App\Enum\MenuDensity;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

final class DensityToggleControllerTest extends WebTestCase
{
    /**
     * Returns a stable test user that always exists (created by fixture/seeder).
     * Falls back to any user if the preferred fixture email is absent.
     */
    private function findAnyUser(EntityManagerInterface $em): ?User
    {
        $user = $em->getRepository(User::class)->findOneBy(['email' => 'notif-channel-manager@test.test']);
        if ($user instanceof User) {
            return $user;
        }
        // Fallback: grab whichever user exists
        return $em->getRepository(User::class)->findOneBy([]);
    }

    private function getValidCsrfToken(mixed $client, string $tokenId): string
    {
        $client->request('GET', '/en/profile');
        $session = $client->getRequest()->getSession();
        $tokenValue = bin2hex(random_bytes(16));
        $session->set('_csrf/' . $tokenId, $tokenValue);
        $session->save();
        return $tokenValue;
    }

    #[Test]
    public function testSetDensityToBasic(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findAnyUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No user found in test DB');
        }
        $client->loginUser($user);

        $userId = $user->getId();
        $token = $this->getValidCsrfToken($client, 'density_toggle_' . $userId);

        $client->request('POST', '/en/preferences/density', [
            '_token' => $token,
            'density' => 'basic',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);

        $em->clear();
        $refreshed = $em->getRepository(User::class)->find($userId);
        self::assertSame(MenuDensity::BASIC, $refreshed->getMenuDensity());
    }

    #[Test]
    public function testSetDensityToExpert(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findAnyUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No user found in test DB');
        }
        $client->loginUser($user);

        $userId = $user->getId();
        $token = $this->getValidCsrfToken($client, 'density_toggle_' . $userId);

        $client->request('POST', '/en/preferences/density', [
            '_token' => $token,
            'density' => 'expert',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_NO_CONTENT);
    }

    #[Test]
    public function testInvalidDensityReturns400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findAnyUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No user found in test DB');
        }
        $client->loginUser($user);

        $userId = $user->getId();
        $token = $this->getValidCsrfToken($client, 'density_toggle_' . $userId);

        $client->request('POST', '/en/preferences/density', [
            '_token' => $token,
            'density' => 'mega-ultra',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    #[Test]
    public function testInvalidCsrfReturns403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findAnyUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No user found in test DB');
        }
        $client->loginUser($user);

        $client->request('POST', '/en/preferences/density', [
            '_token' => 'bad-token',
            'density' => 'standard',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
