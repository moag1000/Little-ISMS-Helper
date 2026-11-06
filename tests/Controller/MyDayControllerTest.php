<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Risk;
use App\Entity\Tenant;
use App\Entity\User;
use App\Entity\Workflow;
use App\Entity\WorkflowInstance;
use App\Entity\WorkflowStep;
use App\Enum\RiskStatus;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * My Day is the one inbox: a risk owner who is named as approver sees the
 * approval under the risk's name and decides it without leaving the page.
 */
final class MyDayControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private ?Tenant $tenant = null;
    private User $owner;
    private Risk $risk;
    private WorkflowInstance $instance;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);

        $uid = uniqid('myday_', true);
        $this->tenant = (new Tenant())->setName('My Day ' . $uid)->setCode('md_' . substr($uid, -10));
        $this->em->persist($this->tenant);

        $this->owner = new User();
        $this->owner->setEmail('owner_' . $uid . '@example.com');
        $this->owner->setFirstName('Risk');
        $this->owner->setLastName('Owner');
        $this->owner->setRoles(['ROLE_USER']);
        $this->owner->setPassword('hashed_password');
        $this->owner->setTenant($this->tenant);
        $this->owner->setIsActive(true);
        $this->em->persist($this->owner);

        $this->risk = new Risk();
        $this->risk->setTenant($this->tenant);
        $this->risk->setTitle('Kundendaten Online-Shop ' . $uid);
        $this->risk->setCategory('operational');
        $this->risk->setDescription('My Day fixture.');
        $this->risk->setProbability(2);
        $this->risk->setImpact(2);
        $this->risk->setStatus(RiskStatus::Identified);
        $this->risk->setCreatedAt(new DateTimeImmutable());
        $this->em->persist($this->risk);
        $this->em->flush();

        $workflow = new Workflow();
        $workflow->setTenant($this->tenant);
        $workflow->setName('Risikoakzeptanz ' . $uid);
        $workflow->setEntityType('Risk');
        $workflow->setIsActive(true);
        $this->em->persist($workflow);

        $step = new WorkflowStep();
        $step->setName('Fachbereich entscheidet');
        $step->setStepOrder(1);
        $step->setStepType('approval');
        $step->setApproverRole('ROLE_DPO');
        $step->setApproverUsers([(int) $this->owner->getId()]);
        $step->setTenant($this->tenant);
        $workflow->addStep($step);
        $this->em->persist($step);

        $this->instance = new WorkflowInstance(); // @lifecycle-initial-state
        $this->instance->setTenant($this->tenant);
        $this->instance->setWorkflow($workflow);
        $this->instance->setEntityType('Risk');
        $this->instance->setEntityId((int) $this->risk->getId());
        $this->instance->setCurrentStep($step);
        $this->instance->setCurrentStepIndex(0);
        $this->instance->setStartedAt(new DateTimeImmutable('-1 hour'));
        $this->instance->setApprovalHistory([]);
        $this->instance->setStatus('in_progress'); // fixture: instance already started
        $this->em->persist($this->instance);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        $tenantId = $this->tenant?->getId();
        if ($tenantId !== null) {
            $conn = $this->em->getConnection();
            foreach ([
                'DELETE FROM workflow_instances WHERE tenant_id = ?',
                'DELETE FROM workflow_steps WHERE workflow_id IN (SELECT id FROM workflows WHERE tenant_id = ?)',
                'DELETE FROM workflows WHERE tenant_id = ?',
                'DELETE FROM risk WHERE tenant_id = ?',
                'DELETE FROM users WHERE tenant_id = ?',
                'DELETE FROM tenant WHERE id = ?',
            ] as $sql) {
                try {
                    $conn->executeStatement($sql, [$tenantId]);
                } catch (\Throwable) {
                    // best effort: rows written by the approval (audit, notifications) may block
                }
            }
        }
        $this->tenant = null;

        parent::tearDown();
    }

    #[Test]
    public function namedApproverSeesTheApprovalUnderTheRecordsName(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/en/my-day');

        self::assertResponseIsSuccessful();
        $section = $crawler->filter('#workflows-pending');
        self::assertCount(1, $section, 'the approval section is shown');
        self::assertStringContainsString('Risk: ' . $this->risk->getTitle(), $section->text());
        self::assertCount(1, $crawler->filter(sprintf(
            'form[action$="/workflow/instance/%d/approve"]',
            $this->instance->getId(),
        )), 'the approval is decided on the page');
    }

    #[Test]
    public function decidingReturnsToMyDay(): void
    {
        $this->client->loginUser($this->owner);
        $crawler = $this->client->request('GET', '/en/my-day');
        $form = $crawler->filter(sprintf('#rejectWorkflow%d form', $this->instance->getId()))->form([
            'comments' => 'Bitte Schadenshöhe ergänzen.',
        ]);

        $this->client->submit($form, [], ['HTTP_REFERER' => 'http://localhost/en/my-day']);

        self::assertResponseRedirects('http://localhost/en/my-day');
        $this->em->clear();
        self::assertSame('rejected', $this->em->find(WorkflowInstance::class, $this->instance->getId())?->getStatus());
    }
}
