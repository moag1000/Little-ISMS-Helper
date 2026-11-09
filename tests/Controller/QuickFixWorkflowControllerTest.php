<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\WorkflowStep;
use App\Workflow\Loader\RegulatoryWorkflowLoader;
use App\Workflow\Mirror\RegulatoryWorkflowMirror;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Csrf\TokenGenerator\UriSafeTokenGenerator;

/**
 * S17: Quick-Fix "Workflow-Definitionen abgleichen" — shows the regulatory chains whose database rows
 * differ from the YAML and aligns them, for a super admin only.
 */
final class QuickFixWorkflowControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?User $superAdmin = null;
    private ?Tenant $tenant = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = static::getContainer()->get(EntityManagerInterface::class);

        $id = uniqid('qfw_', true);
        $this->tenant = (new Tenant())->setName('QF Workflow ' . $id)->setCode('qfw_' . substr(md5($id), 0, 12));
        $this->entityManager->persist($this->tenant);
        $this->superAdmin = new User();
        $this->superAdmin->setEmail($id . '@example.com');
        $this->superAdmin->setFirstName('QF');
        $this->superAdmin->setLastName('Workflow');
        $this->superAdmin->setRoles(['ROLE_SUPER_ADMIN']);
        $this->superAdmin->setPassword('hashed_password');
        $this->superAdmin->setTenant($this->tenant);
        $this->superAdmin->setIsActive(true);
        $this->entityManager->persist($this->superAdmin);
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        try {
            static::getContainer()->get(RegulatoryWorkflowMirror::class)->sync();
            foreach ([$this->superAdmin, $this->tenant] as $entity) {
                $managed = $entity === null ? null : $this->entityManager->find($entity::class, $entity->getId());
                if ($managed !== null) {
                    $this->entityManager->remove($managed);
                }
            }
            $this->entityManager->flush();
        } catch (\Throwable) {
            // best effort
        }
        parent::tearDown();
    }

    #[Test]
    public function aSuperAdminSeesTheDriftAndAlignsIt(): void
    {
        $this->driftRiskTreatment();
        $this->client->loginUser($this->superAdmin);

        $this->client->request('GET', '/quick-fix');
        $this->assertResponseIsSuccessful();
        $content = (string) $this->client->getResponse()->getContent();
        $this->assertStringContainsString('ROLE_RISK_MANAGER → owner', $content);
        $this->assertStringContainsString('/quick-fix/workflows/sync', $content);

        $this->client->request('POST', '/quick-fix/workflows/sync', ['_token' => $this->csrfToken()]);

        $this->assertResponseRedirects('/quick-fix');
        $this->assertSame([], static::getContainer()->get(RegulatoryWorkflowMirror::class)->drift());
    }

    #[Test]
    public function anAnonymousVisitorCannotAlign(): void
    {
        $this->driftRiskTreatment();

        $this->client->request('POST', '/quick-fix/workflows/sync', ['_token' => $this->csrfToken()]);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
        $this->assertNotSame([], static::getContainer()->get(RegulatoryWorkflowMirror::class)->drift());
    }

    #[Test]
    public function aWrongTokenIsRefused(): void
    {
        $this->client->loginUser($this->superAdmin);
        $this->client->request('POST', '/quick-fix/workflows/sync', ['_token' => 'wrong']);

        $this->assertResponseStatusCodeSame(Response::HTTP_FORBIDDEN);
    }

    /** The state of a database written before W5: risk treatment step 1 on the risk manager. */
    private function driftRiskTreatment(): void
    {
        $container = static::getContainer();
        $container->get(RegulatoryWorkflowMirror::class)->sync();
        $meta = $container->get(RegulatoryWorkflowLoader::class)->getRegulatoryMetadata('risk_treatment') ?? [];
        $step = $this->entityManager->createQueryBuilder()
            ->select('s')->from(WorkflowStep::class, 's')->join('s.workflow', 'w')
            ->where('w.name = :name')->andWhere('w.tenant IS NULL')->andWhere('s.stepOrder = 1')
            ->setParameter('name', $meta['title'] ?? '')
            ->getQuery()->getOneOrNullResult();
        $this->assertInstanceOf(WorkflowStep::class, $step);
        $step->setApproverRole('ROLE_RISK_MANAGER');
        $this->entityManager->flush();
    }

    private function csrfToken(): string
    {
        $this->client->request('GET', '/quick-fix');
        $session = $this->client->getRequest()->getSession();
        $token = (new UriSafeTokenGenerator())->generateToken();
        $session->set('_csrf/quick_fix_workflows_sync', $token);
        $session->save();

        return $token;
    }
}
