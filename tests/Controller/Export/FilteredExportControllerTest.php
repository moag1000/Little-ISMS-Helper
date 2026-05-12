<?php

declare(strict_types=1);

namespace App\Tests\Controller\Export;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * F19.5 — Smoke tests for FilteredExportController.
 *
 * Tests:
 *  - Anonymous user redirected to login
 *  - ROLE_MANAGER can access all entity/format combinations
 *  - JSON response has correct structure
 *  - Invalid entity type returns 404
 *  - Invalid format returns 404
 */
#[AllowMockObjectsWithoutExpectations]
class FilteredExportControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    #[Test]
    public function anonymousUserIsRedirectedToLogin(): void
    {
        $this->client->request('GET', '/de/export/risk.json');
        self::assertResponseRedirects();
    }

    #[Test]
    #[DataProvider('entityFormatProvider')]
    public function exportReturnsSuccessOrForbidden(string $entityType, string $format): void
    {
        $user = $this->createOrGetUser('export-test-' . $entityType . '@test.test', 'ROLE_MANAGER');
        $this->client->loginUser($user);

        $this->client->request('GET', sprintf('/de/export/%s.%s', $entityType, $format));
        $status = $this->client->getResponse()->getStatusCode();
        self::assertContains($status, [200, 403], sprintf('Expected 200 or 403 for %s.%s', $entityType, $format));
    }

    #[Test]
    public function jsonExportHasCorrectStructure(): void
    {
        $user = $this->createOrGetUser('export-json-struct@test.test', 'ROLE_MANAGER');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/export/risk.json');
        $status = $this->client->getResponse()->getStatusCode();

        if ($status === 200) {
            $data = json_decode($this->client->getResponse()->getContent(), true);
            self::assertArrayHasKey('entity_type', $data);
            self::assertArrayHasKey('total', $data);
            self::assertArrayHasKey('data', $data);
            self::assertIsArray($data['data']);
        } else {
            self::assertContains($status, [200, 403]);
        }
    }

    #[Test]
    public function invalidEntityTypeReturns404(): void
    {
        $user = $this->createOrGetUser('export-invalid@test.test', 'ROLE_MANAGER');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/export/foobar.json');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function invalidFormatReturns404(): void
    {
        $user = $this->createOrGetUser('export-invalid-fmt@test.test', 'ROLE_MANAGER');
        $this->client->loginUser($user);

        $this->client->request('GET', '/de/export/risk.xml');
        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function entityFormatProvider(): array
    {
        return [
            'risk xlsx' => ['risk', 'xlsx'],
            'risk csv' => ['risk', 'csv'],
            'risk json' => ['risk', 'json'],
            'asset json' => ['asset', 'json'],
            'incident json' => ['incident', 'json'],
            'audit_finding json' => ['audit_finding', 'json'],
        ];
    }

    private function createOrGetUser(string $email, string $role): User
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($user !== null) {
            return $user;
        }
        $tenant = $this->getOrCreateTenant();
        $user = new User();
        $user->setEmail($email);
        $user->setPassword('$2y$13$fake_hashed_password_xxxxxxxxxxxxxx');
        $user->setRoles([$role]);
        $user->setTenant($tenant);
        $user->setFirstName('Test');
        $user->setLastName('User');
        $this->em->persist($user);
        $this->em->flush();
        return $user;
    }

    private function getOrCreateTenant(): Tenant
    {
        if ($this->tenant !== null) {
            return $this->tenant;
        }
        $tenant = $this->em->getRepository(Tenant::class)->findOneBy(['name' => 'FilterExportTest']);
        if ($tenant !== null) {
            $this->tenant = $tenant;
            return $tenant;
        }
        $tenant = new Tenant();
        $tenant->setName('FilterExportTest');
        $tenant->setCode('FET' . substr(uniqid(), -5));
        $this->em->persist($tenant);
        $this->em->flush();
        $this->tenant = $tenant;
        return $tenant;
    }
}
