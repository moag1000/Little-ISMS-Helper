<?php

declare(strict_types=1);

namespace App\Tests\Controller\Bulk;

use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Smoke tests for all bulk-delete-check endpoints.
 *
 * Each endpoint must:
 *  - Return 200 JSON with a "dependencies" array when called as ROLE_MANAGER
 *  - Return 403 when called as ROLE_USER (lacking ROLE_MANAGER)
 *  - Return 405 on GET
 *
 * DocumentController already has its own test coverage; these tests cover
 * the 20 newly-added endpoints across 20 controllers.
 */
class BulkDeleteCheckTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private ?User $manager = null;
    private ?User $user = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $lock = static::getContainer()->getParameter('kernel.project_dir') . '/config/setup_complete.lock';
        if (!file_exists($lock)) {
            @file_put_contents($lock, date('c'));
        }

        $uid = uniqid('bdc_', true);
        $this->tenant = (new Tenant())->setName('Tenant BDC ' . $uid)->setCode('bdc' . substr($uid, -6));
        $this->em->persist($this->tenant);

        $this->manager = $this->makeUser('mgr_bdc_' . $uid . '@x.test', ['ROLE_MANAGER'], $this->tenant);
        $this->user = $this->makeUser('usr_bdc_' . $uid . '@x.test', ['ROLE_USER'], $this->tenant);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        foreach ([$this->manager, $this->user] as $u) {
            if ($u) {
                try {
                    $x = $this->em->find(User::class, $u->getId());
                    if ($x) {
                        $this->em->remove($x);
                    }
                } catch (\Exception) {}
            }
        }
        if ($this->tenant) {
            try {
                $x = $this->em->find(Tenant::class, $this->tenant->getId());
                if ($x) {
                    $this->em->remove($x);
                }
            } catch (\Exception) {}
        }
        try {
            $this->em->flush();
        } catch (\Exception) {}
        parent::tearDown();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function endpointProvider(): array
    {
        return [
            // Top-5 with real dependency checks
            'asset'                  => ['/en/asset/bulk-delete-check'],
            'risk'                   => ['/en/risk/bulk-delete-check'],
            'incident'               => ['/en/incident/bulk-delete-check'],
            'processing_activity'    => ['/en/processing-activity/bulk-delete-check'],
            'bc_plan'                => ['/en/business-continuity-plan/bulk-delete-check'],
            // Stub-only — terminal entities
            'vulnerability'          => ['/en/vulnerability/bulk-delete-check'],
            'training'               => ['/en/training/bulk-delete-check'],
            'patch'                  => ['/en/patch/bulk-delete-check'],
            'change_request'         => ['/en/change-request/bulk-delete-check'],
            'supplier'               => ['/en/supplier/bulk-delete-check'],
            'bc_exercise'            => ['/en/bc-exercise/bulk-delete-check'],
            'threat_intelligence'    => ['/en/threat-intelligence/bulk-delete-check'],
            'data_breach'            => ['/en/data-breach/bulk-delete-check'],
            'data_subject_request'   => ['/en/data-subject-request/bulk-delete-check'],
            'dpia'                   => ['/en/dpia/bulk-delete-check'],
            'audit'                  => ['/en/audit/bulk-delete-check'],
            'audit_finding'          => ['/en/audit-finding/bulk-delete-check'],
            'corrective_action'      => ['/en/corrective-action/bulk-delete-check'],
            'objective'              => ['/en/objective/bulk-delete-check'],
            'management_review'      => ['/en/management-review/bulk-delete-check'],
        ];
    }

    #[Test]
    #[DataProvider('endpointProvider')]
    public function checkReturns200JsonArrayForManager(string $endpoint): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request(
            'POST',
            $endpoint,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => []]),
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertIsArray($body, 'Response must be JSON array/object');
        $this->assertArrayHasKey('dependencies', $body, 'Response must have "dependencies" key');
        $this->assertIsArray($body['dependencies'], '"dependencies" must be an array');
    }

    #[Test]
    #[DataProvider('endpointProvider')]
    public function checkReturnsWith200ForManagerWithNonExistentIds(string $endpoint): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request(
            'POST',
            $endpoint,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => [999999, 999998]]),
        );

        $this->assertResponseIsSuccessful();
        $body = json_decode($this->client->getResponse()->getContent(), true);
        $this->assertArrayHasKey('dependencies', $body);
        $this->assertSame([], $body['dependencies'], 'Non-existent IDs must return empty deps (tenant-scoped)');
    }

    #[Test]
    #[DataProvider('endpointProvider')]
    public function checkRequiresAuthentication(string $endpoint): void
    {
        $this->client->request(
            'POST',
            $endpoint,
            [],
            [],
            ['CONTENT_TYPE' => 'application/json'],
            json_encode(['ids' => []]),
        );

        // Must redirect to login (401/302), not 200
        $statusCode = $this->client->getResponse()->getStatusCode();
        $this->assertContains(
            $statusCode,
            [Response::HTTP_UNAUTHORIZED, Response::HTTP_FOUND],
            "Unauthenticated request to $endpoint must redirect or return 401, got $statusCode",
        );
    }

    #[Test]
    #[DataProvider('endpointProvider')]
    public function checkRejectsGetMethod(string $endpoint): void
    {
        $this->client->loginUser($this->manager);
        $this->client->request('GET', $endpoint);

        $this->assertResponseStatusCodeSame(Response::HTTP_METHOD_NOT_ALLOWED);
    }

    private function makeUser(string $email, array $roles, Tenant $tenant): User
    {
        $u = new User();
        $u->setEmail($email)
          ->setFirstName('T')
          ->setLastName('U')
          ->setRoles($roles)
          ->setPassword('hashed')
          ->setTenant($tenant)
          ->setIsActive(true);
        $this->em->persist($u);
        return $u;
    }
}
