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
use App\Tests\Stub\StubOcrCapabilityDetector;
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
        // Never let an OCR-available verdict leak into a sibling test class.
        StubOcrCapabilityDetector::reset();

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

    // ── OCR-assisted upload (Task 12) ─────────────────────────────────────────

    /**
     * Detector reports UNAVAILABLE (default stub verdict, mirrors a host
     * without pdftotext/tesseract) → the upload follows the unchanged manual
     * path: straight to coverage preview, no async job, no confirm-draft.
     */
    #[Test]
    public function uploadWithoutOcrGoesToManualPreview(): void
    {
        StubOcrCapabilityDetector::setAvailable(false);
        $this->client->loginUser($this->managerUser);

        // GET first to mint a valid cert_new CSRF token.
        $crawler = $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/en/compliance/certificates/new',
            [
                '_token' => $token,
                'frameworkCode' => $this->frameworkCode,
                'certBody' => 'Manual CB',
            ],
            ['certificate_file' => $this->makePdfUpload()],
        );

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        // Manual path → preview, NOT the async progress page.
        self::assertStringContainsString('/preview', $location);
        self::assertStringNotContainsString('/admin/jobs/', $location);

        $this->removeCertsByFrameworkCode($this->frameworkCode, exceptId: (int) $this->cert->getId());
    }

    /**
     * Detector reports AVAILABLE (stub flipped on) → the upload dispatches the
     * OCR job and redirects to the shared async progress page, whose `return`
     * URL points at the confirm-draft form.
     */
    #[Test]
    public function uploadWithOcrDispatchesJobAndRedirectsToProgressThenConfirmDraft(): void
    {
        StubOcrCapabilityDetector::setAvailable(true);
        $this->client->loginUser($this->managerUser);

        $crawler = $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/en/compliance/certificates/new',
            [
                '_token' => $token,
                'frameworkCode' => $this->frameworkCode,
                'certBody' => 'OCR CB',
            ],
            ['certificate_file' => $this->makePdfUpload()],
        );

        $this->assertResponseRedirects();
        $location = (string) $this->client->getResponse()->headers->get('Location');
        // Lands on the shared async progress page (PRG), and its return URL is
        // the confirm-draft form for the freshly-created certificate.
        self::assertStringContainsString('/admin/jobs/', $location);
        self::assertStringContainsString('confirm-draft', urldecode($location));

        $this->removeCertsByFrameworkCode($this->frameworkCode, exceptId: (int) $this->cert->getId());
    }

    /**
     * The confirm-draft GET form renders pre-filled from the certificate's
     * CURRENT fields (which the OCR job populated) and surfaces the confidence.
     */
    #[Test]
    public function confirmDraftFormRendersPrefilled(): void
    {
        $this->client->loginUser($this->managerUser);

        $draftCert = $this->makeOcrDraftCert();
        $crawler = $this->client->request('GET', '/en/compliance/certificates/' . $draftCert->getId() . '/confirm-draft');
        $this->assertResponseIsSuccessful();

        $body = (string) $this->client->getResponse()->getContent();
        // Pre-filled OCR cert body value present in an input value attribute.
        self::assertStringContainsString('OCR Extracted CB', $body);
        // Confidence indicator rendered (82% rounded from 0.82).
        self::assertStringContainsString('82%', $body);
        self::assertSelectorExists('[data-testid="ocr-confidence"]');
        // CSRF token field for the confirm-draft POST.
        self::assertSelectorExists('input[name="_token"]');

        $this->safeRemoveOne($draftCert);
        $this->em->flush();
    }

    /**
     * Posting the confirm-draft form maps the corrected fields onto the cert,
     * flips extractionSource to 'ocr+confirmed', and redirects to preview.
     */
    #[Test]
    public function confirmDraftPostUpdatesCertAndRedirectsToPreview(): void
    {
        $this->client->loginUser($this->managerUser);

        $draftCert = $this->makeOcrDraftCert();
        $draftId = (int) $draftCert->getId();

        $crawler = $this->client->request('GET', '/en/compliance/certificates/' . $draftId . '/confirm-draft');
        $this->assertResponseIsSuccessful();
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $this->client->request(
            'POST',
            '/en/compliance/certificates/' . $draftId . '/confirm-draft',
            [
                '_token' => $token,
                'frameworkCode' => $this->frameworkCode,
                'certBody' => 'Corrected CB',
                'certNumber' => 'CORRECTED-123',
            ],
        );

        $this->assertResponseRedirects();
        self::assertStringContainsString('/preview', (string) $this->client->getResponse()->headers->get('Location'));

        $this->em->clear();
        $fresh = $this->em->find(ComplianceCertificate::class, $draftId);
        self::assertInstanceOf(ComplianceCertificate::class, $fresh);
        self::assertSame('Corrected CB', $fresh->getCertBody());
        self::assertSame('CORRECTED-123', $fresh->getCertNumber());
        self::assertSame('ocr+confirmed', $fresh->getExtractionSource());

        $this->safeRemoveOne($fresh);
        $this->em->flush();
    }

    /**
     * RBAC: a plain ROLE_USER may not reach the confirm-draft form (CERT_MANAGE).
     */
    #[Test]
    public function confirmDraftIsForbiddenForPlainUser(): void
    {
        $this->client->loginUser($this->plainUser);
        $this->client->request('GET', '/en/compliance/certificates/' . $this->cert->getId() . '/confirm-draft');

        self::assertSame(Response::HTTP_FORBIDDEN, $this->client->getResponse()->getStatusCode());
    }

    // ── Upload form rendering (OCR vs manual path) ────────────────────────────

    /**
     * When the OCR pipeline is available the /new page must render an
     * UPLOAD-ONLY form: the file input MUST be present and the
     * frameworkCode select MUST NOT be present (to prevent users typing
     * metadata that would be silently discarded by the OCR job).
     */
    #[Test]
    public function newWithOcrAvailableRendersUploadOnlyForm(): void
    {
        StubOcrCapabilityDetector::setAvailable(true);
        $this->client->loginUser($this->managerUser);

        $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertResponseIsSuccessful();

        // File input must be present — it is the only form field in OCR mode.
        self::assertSelectorExists('input[name="certificate_file"]');

        // The full metadata select must NOT appear — its presence would expose
        // a field that the OCR job silently overwrites.
        self::assertSelectorNotExists('select[name="frameworkCode"]');
    }

    /**
     * When OCR is NOT available the /new page must render the full manual form,
     * including the frameworkCode select and certBody text input.
     */
    #[Test]
    public function newWithoutOcrRendersFullManualForm(): void
    {
        StubOcrCapabilityDetector::setAvailable(false);
        $this->client->loginUser($this->managerUser);

        $this->client->request('GET', '/en/compliance/certificates/new');
        $this->assertResponseIsSuccessful();

        // Full form: file input AND framework select must both be present.
        self::assertSelectorExists('input[name="certificate_file"]');
        self::assertSelectorExists('select[name="frameworkCode"]');
    }

    // ── OCR test helpers ──────────────────────────────────────────────────────

    /**
     * A minimal, magic-byte-valid PDF wrapped as an UploadedFile (test mode).
     */
    private function makePdfUpload(): \Symfony\Component\HttpFoundation\File\UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'cert_test_') . '.pdf';
        // %PDF magic bytes + minimal trailer so the security validator passes.
        file_put_contents($tmp, "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\n%%EOF\n");

        return new \Symfony\Component\HttpFoundation\File\UploadedFile(
            $tmp,
            'cert.pdf',
            'application/pdf',
            null,
            true, // test mode — bypasses is_uploaded_file()
        );
    }

    /**
     * A certificate already populated as if the OCR job had run (source='ocr',
     * a confidence score, and extracted field values).
     */
    private function makeOcrDraftCert(): ComplianceCertificate
    {
        $uid = uniqid('ocr_', true);
        $cert = new ComplianceCertificate();
        $cert->setTenant($this->tenant)
            ->setFrameworkCode($this->frameworkCode)
            ->setCertBody('OCR Extracted CB')
            ->setCertNumber('OCR-' . $uid)
            ->setHolder('OCR Holder')
            ->setValidUntil(new \DateTimeImmutable('+1 year'))
            ->setStatus('active')
            ->setExtractionSource('ocr')
            ->setExtractionConfidence(0.82);
        $this->em->persist($cert);
        $this->em->flush();

        return $cert;
    }

    private function removeCertsByFrameworkCode(string $code, int $exceptId): void
    {
        try {
            $this->em->clear();
            foreach ($this->em->getRepository(ComplianceCertificate::class)->findBy(['frameworkCode' => $code]) as $c) {
                if ((int) $c->getId() !== $exceptId) {
                    $this->em->remove($c);
                }
            }
            $this->em->flush();
        } catch (\Throwable) {
        }
    }
}
