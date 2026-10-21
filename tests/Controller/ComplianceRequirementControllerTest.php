<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\Person;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use PHPUnit\Framework\Attributes\Test;

class ComplianceRequirementControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?User $adminUser = null;
    private ?ComplianceFramework $testFramework = null;
    private ?ComplianceRequirement $testRequirement = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testRequirement) {
            try {
                $req = $this->entityManager->find(ComplianceRequirement::class, $this->testRequirement->getId());
                if ($req) {
                    $this->entityManager->remove($req);
                    $this->entityManager->flush();
                }
            } catch (\Exception $e) {}
        }

        if ($this->testFramework) {
            try {
                $fw = $this->entityManager->find(ComplianceFramework::class, $this->testFramework->getId());
                if ($fw) {
                    $this->entityManager->remove($fw);
                    $this->entityManager->flush();
                }
            } catch (\Exception $e) {}
        }

        if ($this->testUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->testUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->adminUser) {
            try {
                $user = $this->entityManager->find(User::class, $this->adminUser->getId());
                if ($user) {
                    $this->entityManager->remove($user);
                }
            } catch (\Exception $e) {}
        }

        if ($this->testTenant) {
            try {
                $tenant = $this->entityManager->find(Tenant::class, $this->testTenant->getId());
                if ($tenant) {
                    $this->entityManager->remove($tenant);
                }
            } catch (\Exception $e) {}
        }

        try {
            $this->entityManager->flush();
        } catch (\Exception $e) {}

        parent::tearDown();
    }

    private function createTestData(): void
    {
        $uniqueId = uniqid('test_', true);

        $this->testTenant = new Tenant();
        $this->testTenant->setName('Test Tenant ' . $uniqueId);
        $this->testTenant->setCode('test_tenant_' . $uniqueId);
        $this->entityManager->persist($this->testTenant);

        $this->testUser = new User();
        $this->testUser->setEmail('testuser_' . $uniqueId . '@example.com');
        $this->testUser->setFirstName('Test');
        $this->testUser->setLastName('User');
        $this->testUser->setRoles(['ROLE_USER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->adminUser = new User();
        $this->adminUser->setEmail('admin_' . $uniqueId . '@example.com');
        $this->adminUser->setFirstName('Admin');
        $this->adminUser->setLastName('User');
        $this->adminUser->setRoles(['ROLE_ADMIN']);
        $this->adminUser->setPassword('hashed_password');
        $this->adminUser->setTenant($this->testTenant);
        $this->adminUser->setIsActive(true);
        $this->entityManager->persist($this->adminUser);

        // Framework + Requirement fixtures so show/edit have a real target.
        // ComplianceRequirement is a shared-catalogue entity (no tenant_id);
        // it requires a non-null framework FK.
        $this->testFramework = new ComplianceFramework();
        $this->testFramework->setCode('TEST-FW-' . $uniqueId);
        $this->testFramework->setName('Test Framework ' . $uniqueId);
        $this->testFramework->setVersion('1.0');
        $this->testFramework->setApplicableIndustry('all');
        $this->testFramework->setRegulatoryBody('Test Body');
        $this->testFramework->setMandatory(false);
        $this->testFramework->setActive(true);
        $this->entityManager->persist($this->testFramework);

        $this->testRequirement = new ComplianceRequirement();
        $this->testRequirement->setFramework($this->testFramework);
        $this->testRequirement->setRequirementId('REQ-' . $uniqueId);
        $this->testRequirement->setTitle('Test Requirement ' . $uniqueId);
        $this->testRequirement->setDescription('Test requirement description');
        $this->testRequirement->setCategory('test');
        $this->testRequirement->setPriority('medium');
        $this->testRequirement->setRequirementType('core');
        $this->entityManager->persist($this->testRequirement);

        $this->entityManager->flush();
    }

    #[Test]
    public function testIndexRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/requirement');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testIndexDisplaysForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/compliance/requirement');
        $this->assertResponseIsSuccessful();
    }

    #[Test]
    public function testNewRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/requirement/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function testNewDisplaysFormForUser(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/compliance/requirement/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    #[Test]
    public function testTenantlessOperatorGetsNoForeignPersons(): void
    {
        // #1059: an instance operator without organisation works in a default
        // organisation, but the TenantFilter is unscoped for it — the person
        // pickers used findAll() and offered (and accepted) persons of every
        // organisation. Only persons of the fulfillment's organisation count.
        $uid = uniqid('', true);
        $foreignTenant = (new Tenant())->setName('Foreign ' . $uid)->setCode('crq_foreign_' . substr(md5($uid), 0, 12));
        $this->entityManager->persist($foreignTenant);
        $operator = (new User())
            ->setEmail('operator_' . $uid . '@example.com')
            ->setFirstName('Op')
            ->setLastName('Erator')
            ->setRoles(['ROLE_SUPER_ADMIN', 'ROLE_ADMIN'])
            ->setPassword('hashed_password')
            ->setIsActive(true);
        $this->entityManager->persist($operator);
        $person = (new Person())->setFullName('Foreign Person ' . $uid)->setTenant($foreignTenant);
        $this->entityManager->persist($person);
        $this->entityManager->flush();
        $personId = (string) $person->getId();
        $requirementId = $this->testRequirement->getId();

        try {
            $this->client->loginUser($operator);
            $crawler = $this->client->request('GET', '/en/compliance/requirement/' . $requirementId);
            $this->assertResponseIsSuccessful();
            foreach (['responsiblePersonId', 'attestationOwnerPersonId'] as $field) {
                self::assertCount(
                    0,
                    $crawler->filter(sprintf('select[name="%s"] option[value="%s"]', $field, $personId)),
                    $field . ' offers a person of another organisation',
                );
            }

            $form = $crawler->filter('select[name="responsiblePersonId"]')->closest('form')->form();
            $form['responsiblePersonId']->disableValidation()->select($personId);
            $form['attestationOwnerPersonId']->disableValidation()->select($personId);
            $this->client->submit($form);
            self::assertResponseRedirects();

            $linked = (int) $this->entityManager->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM compliance_requirement_fulfillment WHERE requirement_id = ?'
                . ' AND (responsible_person_person_id = ? OR attestation_owner_person_id = ?)',
                [$requirementId, $personId, $personId],
            );
            self::assertSame(0, $linked, 'a person of another organisation was linked');
        } finally {
            $this->entityManager->clear();
            $this->entityManager->getConnection()->executeStatement(
                'DELETE FROM compliance_requirement_fulfillment WHERE requirement_id = ?',
                [$requirementId],
            );
            foreach ([[User::class, $operator->getId()], [Person::class, $person->getId()], [Tenant::class, $foreignTenant->getId()]] as [$class, $id]) {
                if (($entity = $this->entityManager->find($class, $id)) !== null) {
                    $this->entityManager->remove($entity);
                    $this->entityManager->flush();
                }
            }
        }
    }

    // ========== IN-PAGE FORM-MODAL (Turbo Frame) TESTS ==========

    #[Test]
    public function testShowInFrameRendersDetailModalPartial(): void
    {
        $this->client->loginUser($this->testUser);

        $this->client->request(
            'GET',
            '/en/compliance/requirement/' . $this->testRequirement->getId(),
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    #[Test]
    public function testEditInFrameRendersFormModalPartial(): void
    {
        $this->client->loginUser($this->testUser);

        $this->client->request(
            'GET',
            '/en/compliance/requirement/' . $this->testRequirement->getId() . '/edit',
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        // Explicit form action keeps the POST on the edit route (not the list).
        self::assertStringContainsString('action="/en/compliance/requirement/' . $this->testRequirement->getId() . '/edit"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    #[Test]
    public function testEditWithoutFrameRendersFullPage(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/compliance/requirement/' . $this->testRequirement->getId() . '/edit');

        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('<html', (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function testEditInFrameInvalidReturns422ModalPartial(): void
    {
        // The POST path is frame-aware: an invalid in-frame submit re-renders the
        // slim form-modal partial with 422 (errors inline), not the full page.
        $this->client->loginUser($this->testUser);

        $crawler = $this->client->request(
            'GET',
            '/en/compliance/requirement/' . $this->testRequirement->getId() . '/edit',
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );
        $form = $crawler->filter('form[name="compliance_requirement"]')->first()->form();
        $values = $form->getPhpValues();
        // Force invalid: clear the required title.
        $values['compliance_requirement']['title'] = '';

        $this->client->request(
            'POST',
            $form->getUri(),
            $values,
            [],
            ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseStatusCodeSame(422);
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        self::assertStringNotContainsString('<html', $html);
    }
}
