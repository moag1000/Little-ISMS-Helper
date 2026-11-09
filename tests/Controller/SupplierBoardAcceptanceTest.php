<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ManagementDecision;
use App\Entity\Supplier;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ManagementDecisionType;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * S12 / MaRisk AT 4.3: the board acceptance of an outsourcing risk is a
 * Leitungsentscheidung. A manager puts it before top management; the supplier
 * form cannot tick it.
 */
final class SupplierBoardAcceptanceTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    /** @var list<object> */
    private array $created = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->created) as $entity) {
            try {
                $found = $this->em->find($entity::class, $entity->getId());
                if ($found !== null) {
                    $this->em->remove($found);
                }
                $this->em->flush();
            } catch (\Throwable) {
            }
        }
        parent::tearDown();
    }

    #[Test]
    public function aManagerPutsTheOutsourcingRiskBeforeTopManagement(): void
    {
        $tenant = $this->tenant();
        $supplier = $this->supplier($tenant);
        $this->client->loginUser($this->user($tenant, ['ROLE_MANAGER']));

        $crawler = $this->client->request('GET', '/en/management-decisions/new/supplier/' . $supplier->getId());
        self::assertResponseIsSuccessful();
        $this->client->submit($crawler->filter('form[name="management_decision"]')->form());

        self::assertResponseRedirects();
        $decision = $this->em->getRepository(ManagementDecision::class)->findOneBy(['subjectType' => 'Supplier', 'subjectId' => $supplier->getId()]);
        self::assertInstanceOf(ManagementDecision::class, $decision);
        $this->created[] = $decision;
        self::assertSame(ManagementDecisionType::SupplierRiskAcceptance, $decision->getType());
        self::assertTrue($decision->isPending());
        $this->em->clear();
        self::assertFalse($this->em->find(Supplier::class, $supplier->getId())->isBoardLevelRiskAcceptance(), 'only the approval sets it');
    }

    #[Test]
    public function aSupplierOfAnotherOrganisationIsNotFound(): void
    {
        $foreign = $this->supplier($this->tenant());
        $this->client->loginUser($this->user($this->tenant(), ['ROLE_MANAGER']));

        $this->client->request('GET', '/en/management-decisions/new/supplier/' . $foreign->getId());

        self::assertContains($this->client->getResponse()->getStatusCode(), [Response::HTTP_NOT_FOUND, Response::HTTP_FORBIDDEN]);
    }

    #[Test]
    public function aPlainUserMayNotPropose(): void
    {
        $tenant = $this->tenant();
        $supplier = $this->supplier($tenant);
        $this->client->loginUser($this->user($tenant, ['ROLE_USER']));

        $this->client->request('GET', '/en/management-decisions/new/supplier/' . $supplier->getId());

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    private function tenant(): Tenant
    {
        $uid = substr(md5(uniqid('', true)), 0, 10);
        $tenant = (new Tenant())->setName('Org ' . $uid)->setCode('sba_' . $uid);
        $this->em->persist($tenant);
        $this->em->flush();
        $this->created[] = $tenant;

        return $tenant;
    }

    private function supplier(Tenant $tenant): Supplier
    {
        $supplier = new Supplier();
        $supplier->setTenant($tenant)->setName('Rechenzentrum ' . uniqid())->setEmail('rz@example.test')
            ->setAddress('Weg 1')->setCriticality('high')->setServiceProvided('Hosting');
        $supplier->setStatus('active');
        $supplier->setOutsourcingClassification('substantial');
        $this->em->persist($supplier);
        $this->em->flush();
        $this->created[] = $supplier;

        return $supplier;
    }

    /** @param list<string> $roles */
    private function user(Tenant $tenant, array $roles): User
    {
        $user = new User();
        $user->setEmail(uniqid('sba_', true) . '@example.test')->setFirstName('Max')->setLastName('Muster')
            ->setRoles($roles)->setPassword('hashed')->setTenant($tenant)->setIsActive(true);
        $this->em->persist($user);
        $this->em->flush();
        $this->created[] = $user;

        return $user;
    }
}
