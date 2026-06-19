<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\CertificateCoverageRule;
use App\Entity\ComplianceCertificate;
use App\Entity\ComplianceFramework;
use App\Entity\ComplianceRequirement;
use App\Entity\ComplianceRequirementFulfillment;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ComplianceRequirementFulfillmentStatus;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Functional tests for {@see \App\Controller\ComplianceCertificateController}.
 *
 * Covers: preview lists resolved controls, apply triggers bulk fulfillment
 * (a fulfillment becomes Verified), and RBAC (ROLE_USER → 403 on /new).
 */
class ComplianceCertificateControllerTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;

    private ?Tenant $tenant = null;
    private ?User $managerUser = null;
    private ?User $plainUser = null;
    private ?ComplianceFramework $framework = null;
    private ?ComplianceRequirement $r1 = null;
    private ?ComplianceRequirement $r2 = null;
    private ?CertificateCoverageRule $rule = null;
    private ?ComplianceCertificate $cert = null;

    private ?Tenant $otherTenant = null;
    private ?ComplianceCertificate $otherCert = null;

    private string $frameworkCode;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->em = static::getContainer()->get(EntityManagerInterface::class);
        $this->createFixtures();
    }

    protected function tearDown(): void
    {
        $this->safeRemove(ComplianceRequirementFulfillment::class, fn () => $this->em->getRepository(ComplianceRequirementFulfillment::class)->findBy(['tenant' => $this->tenant]));
        $this->safeRemoveOne($this->otherCert);
        $this->safeRemoveOne($this->otherTenant);
        $this->safeRemoveOne($this->cert);
        $this->safeRemoveOne($this->rule);
        $this->safeRemoveOne($this->r1);
        $this->safeRemoveOne($this->r2);
        $this->safeRemoveOne($this->framework);
        $this->safeRemoveOne($this->managerUser);
        $this->safeRemoveOne($this->plainUser);
        $this->safeRemoveOne($this->tenant);

        try {
            $this->em->flush();
        } catch (\Throwable) {
        }

        parent::tearDown();
    }

    private function safeRemove(string $class, callable $finder): void
    {
        try {
            foreach ($finder() as $e) {
                $this->em->remove($e);
            }
            $this->em->flush();
        } catch (\Throwable) {
        }
    }

    private function safeRemoveOne(?object $entity): void
    {
        if ($entity === null || $entity->getId() === null) {
            return;
        }
        try {
            $fresh = $this->em->find($entity::class, $entity->getId());
            if ($fresh !== null) {
                $this->em->remove($fresh);
            }
        } catch (\Throwable) {
        }
    }

    private function createFixtures(): void
    {
        $uid = uniqid('cert_ctrl_', true);
        $this->frameworkCode = 'FW_' . $uid;

        $this->tenant = new Tenant();
        $this->tenant->setName('Cert Ctrl Tenant ' . $uid);
        $this->tenant->setCode('cert_ctrl_' . $uid);
        $this->em->persist($this->tenant);

        $this->managerUser = new User();
        $this->managerUser->setEmail('mgr_' . $uid . '@example.test');
        $this->managerUser->setFirstName('Mgr');
        $this->managerUser->setLastName('User');
        $this->managerUser->setRoles(['ROLE_MANAGER']);
        $this->managerUser->setPassword('hashed');
        $this->managerUser->setTenant($this->tenant);
        $this->managerUser->setIsActive(true);
        $this->em->persist($this->managerUser);

        $this->plainUser = new User();
        $this->plainUser->setEmail('plain_' . $uid . '@example.test');
        $this->plainUser->setFirstName('Plain');
        $this->plainUser->setLastName('User');
        $this->plainUser->setRoles(['ROLE_USER']);
        $this->plainUser->setPassword('hashed');
        $this->plainUser->setTenant($this->tenant);
        $this->plainUser->setIsActive(true);
        $this->em->persist($this->plainUser);

        $this->framework = new ComplianceFramework();
        $this->framework->setCode($this->frameworkCode)
            ->setName('Cert Ctrl Framework ' . $uid)
            ->setVersion('1.0')
            ->setApplicableIndustry('all')
            ->setRegulatoryBody('Test')
            ->setMandatory(false)
            ->setActive(true);
        $this->em->persist($this->framework);

        $this->r1 = $this->makeRequirement('R1_' . $uid);
        $this->r2 = $this->makeRequirement('R2_' . $uid);

        $this->rule = new CertificateCoverageRule();
        $this->rule->setFrameworkCode($this->frameworkCode)
            ->setRequiredClass(null)
            ->setRequiredScopeTags([])
            ->setRequirementIds([$this->r1->getRequirementId(), $this->r2->getRequirementId()])
            ->setActive(true);
        $this->em->persist($this->rule);

        $this->cert = new ComplianceCertificate();
        $this->cert->setTenant($this->tenant)
            ->setFrameworkCode($this->frameworkCode)
            ->setCertBody('Acme CB')
            ->setCertNumber('CERT-' . $uid)
            ->setValidUntil(new \DateTimeImmutable('+2 years'))
            ->setStatus('active');
        $this->em->persist($this->cert);

        // A SECOND tenant owning its own certificate — used to lock cross-tenant
        // 404 (never 403 — must not leak existence).
        $this->otherTenant = new Tenant();
        $this->otherTenant->setName('Cert Other Tenant ' . $uid);
        $this->otherTenant->setCode('cert_other_' . $uid);
        $this->em->persist($this->otherTenant);

        $this->otherCert = new ComplianceCertificate();
        $this->otherCert->setTenant($this->otherTenant)
            ->setFrameworkCode($this->frameworkCode)
            ->setCertBody('Other CB')
            ->setCertNumber('OTHER-' . $uid)
            ->setValidUntil(new \DateTimeImmutable('+2 years'))
            ->setStatus('active');
        $this->em->persist($this->otherCert);

        $this->em->flush();
    }

    private function makeRequirement(string $rid): ComplianceRequirement
    {
        $req = new ComplianceRequirement();
        $req->setFramework($this->framework)
            ->setRequirementId($rid)
            ->setTitle('Req ' . $rid)
            ->setDescription('desc')
            ->setPriority('medium');
        $this->framework->addRequirement($req);
        $this->em->persist($req);

        return $req;
    }

    #[Test]
    public function newRequiresAuthentication(): void
    {
        $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertResponseRedirects();
    }

    #[Test]
    public function newIsForbiddenForPlainUser(): void
    {
        $this->client->loginUser($this->plainUser);
        $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function newIsAccessibleForManager(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('form');
    }

    #[Test]
    public function indexListsTenantCertificates(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance/certificates');
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString($this->cert->getCertNumber(), (string) $this->client->getResponse()->getContent());
    }

    #[Test]
    public function previewListsResolvedRequirements(): void
    {
        $this->client->loginUser($this->managerUser);
        $crawler = $this->client->request('GET', '/en/compliance/certificates/' . $this->cert->getId() . '/preview');
        $this->assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString($this->r1->getRequirementId(), $body);
        self::assertStringContainsString($this->r2->getRequirementId(), $body);
    }

    #[Test]
    public function applyTriggersBulkFulfillmentAndMarksVerified(): void
    {
        $this->client->loginUser($this->managerUser);

        // Visit preview first to obtain a valid CSRF token for cert_apply.
        $crawler = $this->client->request('GET', '/en/compliance/certificates/' . $this->cert->getId() . '/preview');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="/apply"]')->form();
        $this->client->submit($form);

        $this->assertResponseRedirects();

        // A fulfillment for R1 must now exist and be Verified.
        $this->em->clear();
        $fulfillment = $this->em->getRepository(ComplianceRequirementFulfillment::class)->findOneBy([
            'tenant' => $this->tenant->getId(),
            'requirement' => $this->r1->getId(),
        ]);
        self::assertInstanceOf(ComplianceRequirementFulfillment::class, $fulfillment);
        self::assertSame(100, $fulfillment->getFulfillmentPercentage());
        self::assertSame(ComplianceRequirementFulfillmentStatus::Verified, $fulfillment->getStatusEnum());
    }

    #[Test]
    public function applyWithZeroCoverageShowsNothingAppliedWarning(): void
    {
        $this->client->loginUser($this->managerUser);

        // A certificate for an UNKNOWN framework code → coverage resolves to
        // empty → fulfilled === 0 → warning flash, not the success flash.
        $uid = uniqid('zero_', true);
        $zeroCert = new ComplianceCertificate();
        $zeroCert->setTenant($this->tenant)
            ->setFrameworkCode('UNKNOWN_FW_' . $uid)
            ->setCertBody('Zero CB')
            ->setCertNumber('ZERO-' . $uid)
            ->setValidUntil(new \DateTimeImmutable('+2 years'))
            ->setStatus('active');
        $this->em->persist($zeroCert);
        $this->em->flush();
        $zeroCertId = $zeroCert->getId();

        // Obtain a valid cert_apply CSRF token via the preview page.
        $crawler = $this->client->request('GET', '/en/compliance/certificates/' . $zeroCertId . '/preview');
        $this->assertResponseIsSuccessful();

        $form = $crawler->filter('form[action*="/apply"]')->form();
        $this->client->submit($form);
        $this->assertResponseRedirects();

        $this->client->followRedirect();
        $body = (string) $this->client->getResponse()->getContent();
        self::assertStringContainsString('No requirement was covered', $body);
        self::assertStringNotContainsString('marked verified', $body);

        // Cleanup (tearDown only knows the shared $this->cert).
        $fresh = $this->em->find(ComplianceCertificate::class, $zeroCertId);
        if ($fresh !== null) {
            $this->em->remove($fresh);
            $this->em->flush();
        }
    }

    #[Test]
    public function previewOfForeignTenantCertificateReturns404(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance/certificates/' . $this->otherCert->getId() . '/preview');

        // 404 (not 403) — requireCertificate() must not leak existence of another
        // tenant's certificate.
        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }

    #[Test]
    public function showOfForeignTenantCertificateReturns404(): void
    {
        $this->client->loginUser($this->managerUser);
        $this->client->request('GET', '/en/compliance/certificates/' . $this->otherCert->getId());

        self::assertSame(Response::HTTP_NOT_FOUND, $this->client->getResponse()->getStatusCode());
    }
}
