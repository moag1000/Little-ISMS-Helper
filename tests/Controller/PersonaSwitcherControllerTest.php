<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

final class PersonaSwitcherControllerTest extends WebTestCase
{
    /**
     * Find a user with ROLE_SUPER_ADMIN or ROLE_COMPLIANCE_MANAGER for persona-switch testing.
     */
    private function findComplianceUser(EntityManagerInterface $em): ?User
    {
        // Try SUPER_ADMIN first (always has PERSONA_COMPLIANCE via voter)
        $users = $em->getRepository(User::class)->findAll();
        foreach ($users as $u) {
            if (in_array('ROLE_SUPER_ADMIN', $u->getRoles(), true) || in_array('ROLE_COMPLIANCE_MANAGER', $u->getRoles(), true)) {
                return $u;
            }
        }
        return null;
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

    public function testSwitchToCisoPersona(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findComplianceUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No ROLE_SUPER_ADMIN or ROLE_COMPLIANCE_MANAGER user in test DB');
        }
        $client->loginUser($user);

        $token = $this->getValidCsrfToken($client, 'persona_switch_' . $user->getId());

        $client->request('POST', '/en/preferences/persona-switch', [
            '_token' => $token,
            'persona' => 'PERSONA_CISO',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('PERSONA_CISO', $data['acting_as']);
    }

    public function testRevertPersona(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findComplianceUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No ROLE_SUPER_ADMIN or ROLE_COMPLIANCE_MANAGER user in test DB');
        }
        $client->loginUser($user);

        $token = $this->getValidCsrfToken($client, 'persona_switch_' . $user->getId());

        $client->request('POST', '/en/preferences/persona-switch', [
            '_token' => $token,
            'persona' => 'revert',
        ]);

        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNull($data['acting_as']);
    }

    public function testInvalidPersonaReturns400(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findComplianceUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No ROLE_SUPER_ADMIN or ROLE_COMPLIANCE_MANAGER user in test DB');
        }
        $client->loginUser($user);

        $token = $this->getValidCsrfToken($client, 'persona_switch_' . $user->getId());

        $client->request('POST', '/en/preferences/persona-switch', [
            '_token' => $token,
            'persona' => 'PERSONA_HACKER',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_BAD_REQUEST);
    }

    public function testInvalidCsrfReturns403(): void
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->findComplianceUser($em);
        if (!$user instanceof User) {
            self::markTestSkipped('No ROLE_SUPER_ADMIN or ROLE_COMPLIANCE_MANAGER user in test DB');
        }
        $client->loginUser($user);

        $client->request('POST', '/en/preferences/persona-switch', [
            '_token' => 'bad-token',
            'persona' => 'PERSONA_CISO',
        ]);

        self::assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }
}
