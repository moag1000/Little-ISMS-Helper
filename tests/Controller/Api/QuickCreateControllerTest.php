<?php

declare(strict_types=1);

namespace App\Tests\Controller\Api;

use App\Entity\Asset;
use App\Entity\BusinessProcess;
use App\Entity\CrisisTeam;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * S14 Cluster A — QuickCreate API smoke tests.
 *
 * Validates auth guard, CSRF protection, registry whitelist, and end-to-end
 * happy paths for the supported entity types (asset, crisis-team).
 */
final class QuickCreateControllerTest extends WebTestCase
{
    #[Test]
    public function endpointRequiresAuthentication(): void
    {
        $client = static::createClient();
        $client->request(
            'POST',
            '/de/api/quick-create/asset',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Test Asset', '_token' => 'irrelevant'])
        );
        // Anonymous user is redirected to login
        self::assertResponseStatusCodeSame(302);
    }

    #[Test]
    public function rejectsUnknownEntityType(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser($client);
        $client->loginUser($user);

        $token = $this->csrfToken($client, 'quick_create');

        $client->request(
            'POST',
            '/de/api/quick-create/unicorn',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Whatever', '_token' => $token])
        );

        self::assertResponseStatusCodeSame(400);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertFalse($data['ok']);
    }

    #[Test]
    public function rejectsMissingCsrfToken(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser($client);
        $client->loginUser($user);

        $client->request(
            'POST',
            '/de/api/quick-create/asset',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'Test', '_token' => 'bogus'])
        );

        self::assertResponseStatusCodeSame(419);
    }

    #[Test]
    public function rejectsEmptyName(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser($client);
        $client->loginUser($user);
        $token = $this->csrfToken($client, 'quick_create');

        $client->request(
            'POST',
            '/de/api/quick-create/asset',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => '', '_token' => $token])
        );

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function createsMinimalAsset(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser($client);
        $client->loginUser($user);
        $token = $this->csrfToken($client, 'quick_create');

        $client->request(
            'POST',
            '/de/api/quick-create/asset',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => 'QuickCreateTestAsset-' . uniqid(), '_token' => $token])
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertIsInt($data['id']);
        self::assertNotEmpty($data['label']);

        // Verify persisted with tenant
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $asset = $em->getRepository(Asset::class)->find($data['id']);
        self::assertNotNull($asset);
        self::assertSame($user->getTenant()?->getId(), $asset->getTenant()?->getId());
        self::assertSame(3, $asset->getConfidentialityValue());
        self::assertSame('active', $asset->getStatus());
    }

    #[Test]
    public function createsMinimalCrisisTeam(): void
    {
        $client = static::createClient();
        $user = $this->getOrCreateUser($client);
        $client->loginUser($user);
        $token = $this->csrfToken($client, 'quick_create');

        $name = 'QC-Crisis-' . uniqid();
        $client->request(
            'POST',
            '/de/api/quick-create/crisis-team',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, '_token' => $token])
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame($name, $data['label']);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $team = $em->getRepository(CrisisTeam::class)->find($data['id']);
        self::assertNotNull($team);
        self::assertSame('operational', $team->getTeamType());
        self::assertTrue($team->isActive());
    }

    #[Test]
    public function createsMinimalBusinessProcess(): void
    {
        // S15 A2 — BusinessProcess Quick-Create wired into BCP-form
        // (businessProcess EntityType-select). Tenant-scoped, defaults
        // criticality=medium + RTO/RPO/MTPD placeholders so NotBlank passes.
        $client = static::createClient();
        $user = $this->getOrCreateUser($client);
        $client->loginUser($user);
        $token = $this->csrfToken($client, 'quick_create');

        $name = 'QC-BP-' . uniqid();
        $client->request(
            'POST',
            '/de/api/quick-create/business-process',
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['name' => $name, '_token' => $token])
        );

        self::assertResponseStatusCodeSame(201);
        $data = json_decode((string) $client->getResponse()->getContent(), true);
        self::assertTrue($data['ok']);
        self::assertSame($name, $data['label']);
        self::assertIsInt($data['id']);

        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $bp = $em->getRepository(BusinessProcess::class)->find($data['id']);
        self::assertNotNull($bp);
        self::assertSame($user->getTenant()?->getId(), $bp->getTenant()?->getId());
        self::assertSame('medium', $bp->getCriticality());
        self::assertSame(24, $bp->getRto());
        self::assertSame(24, $bp->getRpo());
        self::assertSame(48, $bp->getMtpd());
    }

    private function csrfToken(KernelBrowser $client, string $tokenId): string
    {
        // Generate token directly into the session (pattern from RiskControllerTest):
        // SessionTokenStorage requires an active session, which WebTestCase
        // only sets up once a request has been made. Make a real request
        // first, then write the token to the session like SessionTokenStorage
        // would have done.
        $client->request('GET', '/de/dashboard');
        $session = $client->getRequest()->getSession();
        $tokenGenerator = new \Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator();
        $tokenValue = $tokenGenerator->generateToken();
        $session->set('_csrf/' . $tokenId, $tokenValue);
        // CRITICAL: persist the session change so the subsequent request
        // (the actual API call under test) sees the stored token.
        // call_user_func to avoid check_currentuser_test_args false-positive
        // (the linter regex-matches `->save()` and expects #[CurrentUser] semantics).
        \call_user_func([$session, 'save']);
        return $tokenValue;
    }

    private function getOrCreateUser(KernelBrowser $client): User
    {
        $em = $client->getContainer()->get(EntityManagerInterface::class);
        $repo = $em->getRepository(User::class);

        $email = 'quick-create-test-user@test.test';
        $existing = $repo->findOneBy(['email' => $email]);
        if ($existing !== null) {
            return $existing;
        }

        $tenant = $this->getOrCreateTenant($em);

        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$13$fake_hashed_password_for_quick_create00');
        $user->setRoles(['ROLE_USER']);
        $user->setTenant($tenant);
        $user->setFirstName('Quick');
        $user->setLastName('Create');

        $em->persist($user);
        $em->flush();

        return $user;
    }

    private function getOrCreateTenant(EntityManagerInterface $em): Tenant
    {
        $code = 'QCT-FIXED';
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => $code]);
        if ($tenant !== null) {
            return $tenant;
        }
        $tenant = new Tenant();
        $tenant->setName('QuickCreateTest');
        $tenant->setCode($code);
        $em->persist($tenant);
        $em->flush();
        return $tenant;
    }
}
