<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\FulfillmentInheritanceLog;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * RBAC tests for ComplianceInheritanceController.
 *
 * The reuse payoff (inheritance suggestions) must be VISIBLE to a junior
 * (ROLE_USER) — read-only — while every WRITE path (activate / bulk-confirm /
 * confirm / reject / override) stays gated to ROLE_MANAGER to preserve the
 * 4-eyes principle (ISO 27001 A.5.3).
 */
class ComplianceInheritanceControllerRbacTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $userRole = null;
    private ?User $managerRole = null;
    private ?ComplianceFramework $framework = null;
    private string $frameworkCode = 'ISO27001';
    private ?int $logId = null;
    /** @var list<object> */
    private array $extraEntities = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $uniqueId = uniqid('inh_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Inh Tenant ' . $uniqueId);
        $this->testTenant->setCode('inh_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->frameworkCode = 'RBAC_' . strtoupper(substr(md5($uniqueId), 0, 8));
        $this->framework = new ComplianceFramework();
        $this->framework->setCode($this->frameworkCode);
        $this->framework->setName('RBAC Test Framework');
        $this->framework->setVersion('1.0');
        $this->framework->setApplicableIndustry('all');
        $this->framework->setRegulatoryBody('test');
        $this->entityManager->persist($this->framework);

        $this->userRole = $this->makeUser('user_' . $uniqueId, ['ROLE_USER']);
        $this->managerRole = $this->makeUser('manager_' . $uniqueId, ['ROLE_MANAGER']);

        $this->entityManager->flush();

        // Build a REAL pending FulfillmentInheritanceLog so the write-route role
        // gate is exercised after the entity resolver succeeds (proving 403, not
        // a 404 masking the gate).
        $this->logId = $this->makeInheritanceLog();
        $this->entityManager->flush();
    }

    private function makeInheritanceLog(): int
    {
        $sourceReq = new ComplianceRequirement();
        $sourceReq->setFramework($this->framework);
        $sourceReq->setRequirementId('SRC-1');
        $sourceReq->setTitle('Source requirement');
        $sourceReq->setCategory('test');
        $sourceReq->setDescription('test');
        $sourceReq->setPriority('medium');
        $sourceReq->setRequirementType('control');
        $this->entityManager->persist($sourceReq);
        $this->extraEntities[] = $sourceReq;

        $targetReq = new ComplianceRequirement();
        $targetReq->setFramework($this->framework);
        $targetReq->setRequirementId('TGT-1');
        $targetReq->setTitle('Target requirement');
        $targetReq->setCategory('test');
        $targetReq->setDescription('test');
        $targetReq->setPriority('medium');
        $targetReq->setRequirementType('control');
        $this->entityManager->persist($targetReq);
        $this->extraEntities[] = $targetReq;

        $mapping = new ComplianceMapping();
        $mapping->setSourceRequirement($sourceReq);
        $mapping->setTargetRequirement($targetReq);
        $mapping->setMappingType('full');
        $mapping->setMappingPercentage(100);
        $mapping->setConfidence('high');
        $this->entityManager->persist($mapping);
        $this->extraEntities[] = $mapping;

        $fulfillment = new ComplianceRequirementFulfillment();
        $fulfillment->setTenant($this->testTenant);
        $fulfillment->setRequirement($targetReq);
        $fulfillment->setFulfillmentPercentage(0);
        $this->entityManager->persist($fulfillment);
        $this->extraEntities[] = $fulfillment;

        $log = new FulfillmentInheritanceLog();
        $log->setTenant($this->testTenant);
        $log->setFulfillment($fulfillment);
        $log->setDerivedFromMapping($mapping);
        $log->setSuggestedPercentage(100);
        $this->entityManager->persist($log);
        $this->extraEntities[] = $log;

        $this->entityManager->flush();

        return (int) $log->getId();
    }

    private function makeUser(string $id, array $roles): User
    {
        $user = new User();
        $user->setEmail($id . '@example.com');
        $user->setFirstName('T');
        $user->setLastName('U');
        $user->setRoles($roles);
        $user->setPassword('hashed_password');
        $user->setTenant($this->testTenant);
        $user->setIsActive(true);
        $this->entityManager->persist($user);

        return $user;
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->extraEntities) as $e) {
            try {
                $found = $this->entityManager->find($e::class, $e->getId());
                if ($found) {
                    $this->entityManager->remove($found);
                }
            } catch (\Exception) {
            }
        }
        try {
            $this->entityManager->flush();
        } catch (\Exception) {
        }

        foreach ([$this->userRole, $this->managerRole] as $u) {
            if ($u) {
                try {
                    $found = $this->entityManager->find(User::class, $u->getId());
                    if ($found) {
                        $this->entityManager->remove($found);
                    }
                } catch (\Exception) {
                }
            }
        }
        if ($this->framework) {
            try {
                $found = $this->entityManager->find(ComplianceFramework::class, $this->framework->getId());
                if ($found) {
                    $this->entityManager->remove($found);
                }
            } catch (\Exception) {
            }
        }
        if ($this->testTenant) {
            try {
                $found = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($found) {
                    $this->entityManager->remove($found);
                }
            } catch (\Exception) {
            }
        }
        try {
            $this->entityManager->flush();
        } catch (\Exception) {
        }

        parent::tearDown();
    }

    // ----- READ access: ROLE_USER sees the queue (read-only) -----

    #[Test]
    public function testRoleUserCanReadQueueWithoutWriteControls(): void
    {
        $this->client->loginUser($this->userRole);
        $crawler = $this->client->request('GET', '/en/compliance/inheritance/queue/' . $this->frameworkCode . '');

        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        // No write controls for a junior.
        $this->assertSame(
            0,
            $crawler->filter('form[action*="/confirm"], form[action*="/reject"], form[action*="/override"]')->count(),
            'ROLE_USER must not see inheritance write forms.',
        );
        // The manager-hint (4-eyes) must be shown instead.
        $this->assertStringContainsString(
            'four-eyes principle',
            $this->client->getResponse()->getContent() ?: '',
            'Manager-hint (four-eyes) text must appear for ROLE_USER.',
        );
    }

    #[Test]
    public function testRoleUserCanReadPendingCount(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('GET', '/en/compliance/inheritance/pending-count');
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
        $this->assertResponseHeaderSame('content-type', 'application/json');
    }

    // ----- WRITE actions: ROLE_USER forbidden (403) -----

    #[Test]
    public function testRoleUserPostActivateForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/inheritance/activate/' . $this->frameworkCode . '');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testRoleUserPostBulkConfirmForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/inheritance/bulk-confirm/' . $this->frameworkCode . '');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testRoleUserPostConfirmForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/inheritance/' . $this->logId . '/confirm');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testRoleUserPostRejectForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/inheritance/' . $this->logId . '/reject');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    #[Test]
    public function testRoleUserPostOverrideForbidden(): void
    {
        $this->client->loginUser($this->userRole);
        $this->client->request('POST', '/en/compliance/inheritance/' . $this->logId . '/override');
        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    // ----- MANAGER: read queue (200) WITHOUT 403; write routes not forbidden -----

    #[Test]
    public function testRoleManagerCanReadQueue(): void
    {
        $this->client->loginUser($this->managerRole);
        $this->client->request('GET', '/en/compliance/inheritance/queue/' . $this->frameworkCode . '');
        $this->assertResponseStatusCodeSame(Response::HTTP_OK);
    }

    #[Test]
    public function testRoleManagerWriteRoutesNotForbidden(): void
    {
        $this->client->loginUser($this->managerRole);
        // Manager is past the role gate; with no/invalid CSRF or missing data the
        // controller returns 4xx other-than-403 or a redirect — but NEVER 403.
        $this->client->request('POST', '/en/compliance/inheritance/activate/' . $this->frameworkCode . '');
        $this->assertNotSame(
            Response::HTTP_FORBIDDEN,
            $this->client->getResponse()->getStatusCode(),
            'ROLE_MANAGER must pass the activate role gate.',
        );
    }
}
