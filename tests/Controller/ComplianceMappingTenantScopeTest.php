<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceMapping;
use App\Entity\ComplianceRequirement;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** A mapping built from tenant A's uploaded requirement must be invisible and untouchable for tenant B. */
class ComplianceMappingTenantScopeTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];
    private User $managerB;
    private ComplianceMapping $mappingA;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $uid = uniqid('scope_', true);

        $tenantA = $this->persist((new Tenant())->setName('A ' . $uid)->setCode('a_' . $uid));
        $tenantB = $this->persist((new Tenant())->setName('B ' . $uid)->setCode('b_' . $uid));

        $fw = $this->persist(
            (new ComplianceFramework())->setCode('FW_' . $uid)->setName('FW ' . $uid)->setVersion('1')
                ->setDescription('x')->setApplicableIndustry('all')->setRegulatoryBody('x')->setMandatory(false)
        );
        $reqs = [];
        foreach (['s' => $tenantA, 't' => null] as $key => $owner) {
            $r = (new ComplianceRequirement())->setFramework($fw)->setRequirementId($key . $uid)
                ->setTitle('Req ' . $key)->setDescription('d')->setCategory('c')->setPriority('medium');
            $r->setUploadTenant($owner);
            $reqs[$key] = $this->persist($r);
        }
        $this->mappingA = $this->persist(
            (new ComplianceMapping())->setSourceRequirement($reqs['s'])->setTargetRequirement($reqs['t'])
                ->setMappingType('partial')->setMappingPercentage(50)->setConfidence('medium')
        );

        $this->managerB = new User();
        $this->managerB->setEmail('mgrb_' . $uid . '@example.com');
        $this->managerB->setFirstName('M');
        $this->managerB->setLastName('B');
        $this->managerB->setRoles(['ROLE_MANAGER']);
        $this->managerB->setPassword('x');
        $this->managerB->setTenant($tenantB);
        $this->managerB->setIsActive(true);
        $this->persist($this->managerB);
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_reverse($this->created) as $entity) {
                $managed = $this->em->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->em->remove($managed);
                    $this->em->flush();
                }
            }
        } catch (\Throwable) {
        }
        parent::tearDown();
    }

    private function persist(object $entity): object
    {
        $this->em->persist($entity);
        $this->em->flush();
        $this->created[] = $entity;

        return $entity;
    }

    #[Test]
    public function otherTenantCannotViewMapping(): void
    {
        $this->client->loginUser($this->managerB);
        $this->client->request('GET', '/en/compliance/mapping/' . $this->mappingA->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function otherTenantCannotAnalyzeMapping(): void
    {
        $this->client->loginUser($this->managerB);
        $this->client->request('POST', '/en/compliance/mapping/' . $this->mappingA->getId() . '/analyze', ['_token' => 'x']);
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function otherTenantCannotReviewViaQualityApi(): void
    {
        $this->client->loginUser($this->managerB);
        $this->client->request('GET', '/en/compliance/mapping-quality/review/' . $this->mappingA->getId());
        $this->assertResponseStatusCodeSame(404);
    }

    #[Test]
    public function otherTenantDoesNotSeeMappingInIndexOrByRequirementFilter(): void
    {
        $this->client->loginUser($this->managerB);
        $this->client->request('GET', '/en/compliance/mapping?requirement=' . $this->mappingA->getSourceRequirement()->getId());
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            (string) $this->mappingA->getSourceRequirement()->getRequirementId(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function seedReviewQueueHidesOtherTenantsMapping(): void
    {
        $this->mappingA->setVerifiedBy('app:seed-test');
        $this->mappingA->setReviewStatus('unreviewed');
        $this->em->flush();

        $this->client->loginUser($this->managerB);
        $this->client->request('GET', '/en/compliance/mapping/seed-review');
        $this->assertResponseIsSuccessful();
        $this->assertStringNotContainsString(
            (string) $this->mappingA->getSourceRequirement()->getRequirementId(),
            (string) $this->client->getResponse()->getContent(),
        );
    }

    #[Test]
    public function adminQualityShowIsNotFoundForOtherTenantAndDoesNotPersistOnGet(): void
    {
        $admin = $this->managerB;
        $admin->setRoles(['ROLE_ADMIN']);
        $this->em->flush();
        $this->client->loginUser($admin);
        $this->client->request('GET', '/en/admin/mapping-quality/' . $this->mappingA->getId());
        $this->assertResponseStatusCodeSame(404);

        // own-visible mapping: GET renders but leaves the stored score untouched
        $shared = $this->sharedMapping();
        $before = $shared->getQualityScore();
        $this->client->request('GET', '/en/admin/mapping-quality/' . $shared->getId());
        $this->assertResponseIsSuccessful();
        $this->em->clear();
        $after = $this->em->find(ComplianceMapping::class, $shared->getId())->getQualityScore();
        $this->assertSame($before, $after);
    }

    private function sharedMapping(): ComplianceMapping
    {
        $fw = $this->mappingA->getSourceRequirement()->getFramework();
        $reqs = [];
        foreach (['x', 'y'] as $k) {
            $r = (new ComplianceRequirement())->setFramework($fw)->setRequirementId($k . uniqid())
                ->setTitle('Req ' . $k)->setDescription('d')->setCategory('c')->setPriority('medium');
            $reqs[] = $this->persist($r);
        }

        return $this->persist(
            (new ComplianceMapping())->setSourceRequirement($reqs[0])->setTargetRequirement($reqs[1])
                ->setMappingType('partial')->setMappingPercentage(50)->setConfidence('medium')
        );
    }

    #[Test]
    public function otherTenantStatisticsDoNotCountMapping(): void
    {
        $this->client->loginUser($this->managerB);
        $this->client->request('GET', '/en/compliance/mapping/hub');
        $stats = static::getContainer()->get(\App\Service\Compliance\MappingStatistics::class);
        $global = $stats->getQualityStatistics(global: true)['total_mappings'];
        $this->assertSame($global - 1, $stats->getQualityStatistics()['total_mappings']);
        $this->assertSame($global - 1, $stats->getMappingStatistics()['total_mappings']);
    }
}
