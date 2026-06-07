<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\ThreatIntelligence;
use App\Entity\Tenant;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ThreatIntelligenceControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private ?Tenant $testTenant = null;
    private ?User $testUser = null;
    private ?ThreatIntelligence $testThreat = null;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = static::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $this->createTestData();
    }

    protected function tearDown(): void
    {
        if ($this->testThreat) {
            try {
                $threat = $this->entityManager->find(ThreatIntelligence::class, $this->testThreat->getId());
                if ($threat) {
                    $this->entityManager->remove($threat);
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
        $this->testUser->setRoles(['ROLE_MANAGER']);
        $this->testUser->setPassword('hashed_password');
        $this->testUser->setTenant($this->testTenant);
        $this->testUser->setIsActive(true);
        $this->entityManager->persist($this->testUser);

        $this->testThreat = new ThreatIntelligence();
        $this->testThreat->setTenant($this->testTenant);
        $this->testThreat->setTitle('Test Threat ' . $uniqueId);
        $this->testThreat->setDescription('Test threat description');
        $this->testThreat->setThreatType('malware');
        $this->testThreat->setSeverity('medium');
        $this->testThreat->setStatus('new');
        $this->testThreat->setSource('internal');
        $this->testThreat->setDetectionDate(new \DateTime());
        $this->testThreat->setAffectsOrganization(true);
        // validateAssigneeSlot requires at least one assignee.
        $this->testThreat->setAssignedTo($this->testUser);
        $this->entityManager->persist($this->testThreat);

        $this->entityManager->flush();
    }

    // ========== IN-PAGE FORM-MODAL (Turbo Frame) TESTS ==========

    #[Test]
    public function testShowInFrameRendersDetailModalPartial(): void
    {
        $this->client->loginUser($this->testUser);

        $this->client->request(
            'GET',
            '/en/threat-intelligence/' . $this->testThreat->getId(),
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
            '/en/threat-intelligence/' . $this->testThreat->getId() . '/edit',
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );

        $this->assertResponseIsSuccessful();
        $html = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('<turbo-frame id="fa-form-modal"', $html);
        self::assertStringContainsString('fa-form-layout--modal', $html);
        self::assertStringContainsString('action="/en/threat-intelligence/' . $this->testThreat->getId() . '/edit"', $html);
        self::assertStringNotContainsString('<html', $html);
    }

    #[Test]
    public function testEditWithoutFrameRendersFullPage(): void
    {
        $this->client->loginUser($this->testUser);
        $this->client->request('GET', '/en/threat-intelligence/' . $this->testThreat->getId() . '/edit');

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
            '/en/threat-intelligence/' . $this->testThreat->getId() . '/edit',
            [], [], ['HTTP_TURBO_FRAME' => 'fa-form-modal'],
        );
        $form = $crawler->filter('form')->first()->form();
        $values = $form->getPhpValues();
        // Force invalid at the form layer: an out-of-list value for the required
        // `severity` ChoiceType fails choice validation, so the form is invalid
        // and the managed entity keeps its valid value — the in-frame branch
        // re-renders the 422 partial and tearDown can still remove the row.
        $values['threat_intelligence']['severity'] = '__invalid__';

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
